<?php

namespace App\Http\Controllers\Api;

use App\Classification\AccessLevelPolicy;
use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeDocument;
use App\Jobs\ExtractDocumentText;
use App\LeadTime\Target;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentStageEvent;
use App\Models\Office;
use App\Models\RequestType;
use App\Models\SubmissionRequest;
use App\Models\User;
use App\Scanning\Contracts\FileScanner;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubmissionController extends Controller
{
    /**
     * GET /api/dashboard/submissions
     * The current user's own requests + documents, merged into one
     * "my submissions" list for the user/office dashboard.
     */
    public function mine(Request $request)
    {
        $userId = $request->user()->id;

        $requests = SubmissionRequest::with(['requestType', 'review', 'assignee'])
            ->where('requested_by', $userId)
            ->get()
            ->map(fn ($r) => $this->formatRequest($r));

        $documents = Document::with(['category', 'review', 'assignee', 'office'])
            ->where('uploaded_by', $userId)
            ->get()
            ->map(fn ($d) => $this->formatDocument($d));

        $merged = $requests->concat($documents)
            ->sortByDesc('submitted_at')
            ->values();

        // Not paginated: a single user's own submissions is the smallest
        // list in the app, and the dashboard needs the full set to derive
        // the "needs revision / rejected" notices.
        return response()->json($merged);
    }

    /**
     * GET /api/office-admin/queue[?scope=all|mine|unassigned]
     * Pending requests + documents for the office admin review queue (PF-08).
     * Items are always scoped to the calling office_admin's office via
     * target_office_id; unscoped items (null) are visible to all offices.
     *   mine       — assigned to the calling reviewer
     *   unassigned — no assignee yet, within office scope
     *   all        — every pending item in office scope (default)
     */
    public function queue(Request $request)
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'in:all,mine,unassigned'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);
        $scope = $validated['scope'] ?? 'all';
        $categoryId = $validated['category_id'] ?? null;

        $reviewer = $request->user();

        $apply = function ($query) use ($scope, $reviewer) {
            $query->where('status', 'pending');

            if ($scope === 'mine') {
                $query->where('assigned_to', $reviewer->id);
            } elseif ($scope === 'unassigned') {
                $query->whereNull('assigned_to');
            }

            // Always scope to office — items with no target_office_id are
            // visible to all offices (e.g. untagged legacy rows).
            if ($reviewer->office_id) {
                $query->where(fn ($q) => $q
                    ->where('target_office_id', $reviewer->office_id)
                    ->orWhereNull('target_office_id'));
            }

            return $query;
        };

        // A category filter only makes sense for documents; requests have
        // no category, so setting it drops them from the queue.
        $requests = $categoryId
            ? collect()
            : $apply(SubmissionRequest::with(['requestType', 'requester', 'assignee']))
                ->get()
                ->map(fn ($r) => $this->formatRequest($r, includeSubmitter: true));

        $documents = $apply(Document::with(['category', 'uploader', 'assignee', 'office']))
            ->when($categoryId, fn ($q, $v) => $q->where('category_id', $v))
            ->get()
            ->map(fn ($d) => $this->formatDocument($d, includeSubmitter: true));

        $merged = $requests->concat($documents)
            ->sortBy('submitted_at')
            ->values();

        return response()->json($this->paged($this->paginateCollection($merged, $request, 20)));
    }

    /**
     * GET /api/office-admin/decided[?category_id=]
     * Requests + documents this office has already decided on (approved,
     * rejected, or sent back for revision) — the review history view, as
     * opposed to queue()'s still-pending items. Same office scoping as
     * queue(): target_office_id match or unscoped (null) rows.
     */
    public function decided(Request $request)
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);
        $categoryId = $validated['category_id'] ?? null;

        $reviewer = $request->user();
        $decidedStatuses = ['approved', 'rejected', 'revision'];

        $apply = function ($query) use ($decidedStatuses, $reviewer) {
            $query->whereIn('status', $decidedStatuses)->orderByDesc('updated_at');

            if ($reviewer->office_id) {
                $query->where(fn ($q) => $q
                    ->where('target_office_id', $reviewer->office_id)
                    ->orWhereNull('target_office_id'));
            }

            return $query;
        };

        $requests = $categoryId
            ? collect()
            : $apply(SubmissionRequest::with(['requestType', 'requester', 'assignee', 'review.responseDocument']))
                ->get()
                ->map(fn ($r) => $this->formatRequest($r, includeSubmitter: true) + ['decided_at' => $r->updated_at]);

        $documents = $apply(Document::with(['category', 'uploader', 'assignee', 'review.responseDocument', 'office']))
            ->when($categoryId, fn ($q, $v) => $q->where('category_id', $v))
            ->get()
            ->map(fn ($d) => $this->formatDocument($d, includeSubmitter: true) + ['decided_at' => $d->updated_at]);

        $merged = $requests->concat($documents)
            ->sortByDesc('decided_at')
            ->values();

        return response()->json($this->paged($this->paginateCollection($merged, $request, 20)));
    }

    /**
     * GET /api/office-admin/response-documents?q=&kind=&id=
     *
     * Repository documents a reviewer may hand over as the answer to a
     * submission, instead of uploading a fresh copy.
     *
     * Deliberately narrow:
     *  - approved and retention-active only — a pending or superseded
     *    record is not an answer to give anyone;
     *  - public / internal only — linking a restricted or confidential
     *    document would let the submitter download it without the access
     *    grant that normally gates such a release (BR-04 / FR-06). If
     *    they genuinely need one, the reviewer issues a grant, which is
     *    auditable and expiring;
     *  - scoped to the office the submission was routed to, which is what
     *    "documents associated with where the request went" means.
     */
    public function responseDocuments(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'kind' => ['required', Rule::in(['request', 'document'])],
            'id' => ['required', 'integer'],
        ]);

        $submittable = $data['kind'] === 'request'
            ? SubmissionRequest::find($data['id'])
            : Document::find($data['id']);

        if ($submittable === null) {
            return response()->json(['data' => []]);
        }

        $reviewer = $request->user();

        // A reviewer may only browse response documents for a submission
        // routed to their own office — otherwise this endpoint becomes a
        // way to enumerate another office's approved documents by simply
        // naming one of its pending items.
        if ($reviewer->office_id && $submittable->target_office_id && $submittable->target_office_id !== $reviewer->office_id) {
            return response()->json([
                'message' => 'This submission belongs to a different office.',
            ], 403);
        }

        // The office the submission was routed to. Falls back to the
        // reviewer's own office for untagged legacy rows.
        $officeId = $submittable->target_office_id ?? $reviewer->office_id;

        $documents = Document::query()
            ->where('status', 'approved')
            ->where('retention_status', 'active')
            ->whereIn('access_level', ['public', 'internal'])
            ->when($officeId, fn ($q, $v) => $q->where(fn ($qq) => $qq
                ->where('target_office_id', $v)
                ->orWhere('office_id', $v)))
            // Never offer the very item under review as its own answer.
            ->when($data['kind'] === 'document', fn ($q) => $q->where('id', '!=', $submittable->id))
            ->when($data['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';
                $q->where(fn ($qq) => $qq
                    ->where('title', 'like', $like)
                    ->orWhere('tracking_no', 'like', $like)
                    ->orWhere('keywords', 'like', $like));
            })
            ->with('category')
            ->latest('submitted_at')
            ->limit(50)
            ->get()
            ->map(fn (Document $d) => [
                'id' => $d->id,
                'ref' => $d->tracking_no,
                'title' => $d->title,
                'category' => $d->category?->category_name,
                'document_type' => $d->document_type,
                'reporting_period' => $d->reporting_period,
                'access_level' => $d->access_level,
                'file_format' => $d->file_format,
                'version_number' => $d->version_number,
                'submitted_at' => $d->submitted_at,
            ]);

        return response()->json(['data' => $documents]);
    }

    /**
     * GET /api/office-admin/review-config
     * The completeness checklists (PF-09) the frontend renders in the
     * review screen, plus the list of reviewers in this office.
     */
    public function reviewConfig(Request $request)
    {
        return response()->json([
            'routing_strategy' => config('review.routing.strategy'),
            'checklists' => config('review.checklists'),
            'reviewers' => User::query()
                ->where('role', User::ROLE_OFFICE_ADMIN)
                ->where('is_active', true)
                ->where('office_id', $request->user()->office_id)
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
        ]);
    }

    /**
     * POST /api/osm-admin/documents/{document}/assign
     * POST /api/osm-admin/requests/{request}/assign
     * Claim, reassign, or release (assignee_id = null) a queued item.
     */
    public function assignDocument(Request $request, Document $document)
    {
        return $this->assign($request, $document, 'document');
    }

    public function assignRequest(Request $request, SubmissionRequest $submission)
    {
        return $this->assign($request, $submission, 'request');
    }

    private function assign(Request $request, Model $submittable, string $kind)
    {
        if ($submittable->status !== 'pending') {
            return response()->json([
                'message' => "Only a pending {$kind} can be assigned (current status: {$submittable->status}).",
            ], 422);
        }

        $actorOfficeId = $request->user()->office_id;
        if ($actorOfficeId && $submittable->target_office_id && $submittable->target_office_id !== $actorOfficeId) {
            return response()->json([
                'message' => "This {$kind} belongs to a different office and is not yours to assign.",
            ], 403);
        }

        // The assignee must be an active office admin, and — when both the
        // item's target office and the assignee's own office are known —
        // in the same office it was routed to. An assignee with no office
        // on record (legacy/unscoped account) is still allowed, matching
        // the null-means-unscoped pattern used throughout this controller.
        $targetOfficeId = $submittable->target_office_id ?? $actorOfficeId;
        $data = $request->validate([
            'assignee_id' => ['present', 'nullable', 'integer', Rule::exists('users', 'id')->where(
                fn ($q) => $q->where('role', User::ROLE_OFFICE_ADMIN)
                    ->where('is_active', true)
                    ->when($targetOfficeId, fn ($qq) => $qq->where(fn ($qqq) => $qqq
                        ->where('office_id', $targetOfficeId)
                        ->orWhereNull('office_id'))),
            )],
        ], [
            'assignee_id.exists' => 'The assignee must be an active office admin in the same office.',
        ]);

        $assigneeId = $data['assignee_id'];
        $submittable->update([
            'assigned_to' => $assigneeId,
            'assigned_at' => $assigneeId ? now() : null,
        ]);

        $actor = $request->user();
        $verb = $assigneeId === null
            ? 'released to the queue'
            : ($assigneeId === $actor->id ? 'claimed' : 'reassigned');

        AuditLog::record(
            $actor->id,
            "{$kind}_assigned",
            ucfirst($kind)." {$submittable->tracking_no} {$verb}.",
            $kind === 'document' ? Document::class : SubmissionRequest::class,
            $submittable->id,
            ['assigned_to' => $assigneeId],
        );

        if ($assigneeId !== null && $assigneeId !== $actor->id) {
            Notifier::send(
                $assigneeId,
                'review_pending',
                ucfirst($kind)." {$submittable->tracking_no} was assigned to you for review.",
                '/office-admin',
                config('app.name')." — {$kind} {$submittable->tracking_no} assigned to you",
            );
        }

        $submittable->load($kind === 'document' ? ['category', 'uploader', 'assignee', 'review'] : ['requestType', 'requester', 'assignee', 'review']);

        return response()->json($kind === 'document'
            ? $this->formatDocument($submittable, includeSubmitter: true)
            : $this->formatRequest($submittable, includeSubmitter: true));
    }

    /**
     * Route a new or resubmitted submission into the review queue (PF-08).
     * Config strategy drives assignment:
     *   round_robin  — assign to the least-loaded office_admin in the
     *                  target office's pool.
     *   office_queue — leave unassigned; the "unassigned" queue view
     *                  scopes items to the office_admin's own office.
     * In both strategies, if an item already has a valid active
     * office_admin assignee that reviewer is kept (revision continuity).
     */
    private function routeForReview(Model $submittable, ?int $targetOfficeId): void
    {
        if ($submittable->assigned_to !== null) {
            $stillValid = User::where('id', $submittable->assigned_to)
                ->where('role', User::ROLE_OFFICE_ADMIN)
                ->where('is_active', true)
                ->exists();

            if ($stillValid) {
                return;
            }
        }

        if (config('review.routing.strategy') === 'round_robin') {
            $candidates = User::query()
                ->where('role', User::ROLE_OFFICE_ADMIN)
                ->where('is_active', true)
                ->when($targetOfficeId, fn ($q) => $q->where('office_id', $targetOfficeId))
                ->pluck('id');

            $assigneeId = $candidates
                ->sortBy(fn (int $id) => Document::where('assigned_to', $id)->where('status', 'pending')->count()
                    + SubmissionRequest::where('assigned_to', $id)->where('status', 'pending')->count())
                ->first();

            $submittable->update([
                'assigned_to' => $assigneeId ?? null,
                'assigned_at' => $assigneeId ? now() : null,
            ]);

            return;
        }

        // office_queue — leave unassigned; queue view scopes by target_office_id.
        $submittable->update(['assigned_to' => null, 'assigned_at' => null]);
    }

    /**
     * GET /api/office-admin/stats
     * Live monitoring counts for the office admin dashboard (FR-13 / PF-14).
     * office_admin sees only their own office's submissions; system_admin
     * sees all (future use — route is currently office_admin only).
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        $officeId = $user->role === User::ROLE_OFFICE_ADMIN ? $user->office_id : null;

        // Include documents with no target_office_id (legacy / no-office rows)
        // alongside this office's own — mirrors the queue scope.
        $officeScope = fn ($q) => $q->where(fn ($qq) => $qq
            ->where('target_office_id', $officeId)
            ->orWhereNull('target_office_id'));

        $docByStatus = Document::when($officeId, $officeScope)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $reqByStatus = SubmissionRequest::when($officeId, $officeScope)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $status = fn ($bag, string $k) => (int) ($bag[$k] ?? 0);

        $documents = [
            'total' => (int) $docByStatus->sum(),
            'pending' => $status($docByStatus, 'pending'),
            'revision' => $status($docByStatus, 'revision'),
            'approved' => $status($docByStatus, 'approved'),
            'rejected' => $status($docByStatus, 'rejected'),
            'archived' => Document::when($officeId, $officeScope)
                ->whereIn('retention_status', ['archived', 'superseded'])->count(),
            'submitted_last_7_days' => Document::when($officeId, $officeScope)
                ->where('submitted_at', '>=', now()->subDays(7))->count(),
            // Advisory lead-time breach count (Phase 7.1 / decision 0.9).
            'overdue' => Document::when($officeId, $officeScope)
                ->whereIn('status', ['pending', 'revision'])
                ->with('review')
                ->get()
                ->filter(fn (Document $d) => Target::isOverdue($d))
                ->count(),
        ];

        $requests = [
            'total' => (int) $reqByStatus->sum(),
            'pending' => $status($reqByStatus, 'pending'),
            'revision' => $status($reqByStatus, 'revision'),
            'approved' => $status($reqByStatus, 'approved'),
            'rejected' => $status($reqByStatus, 'rejected'),
        ];

        $avgLeadDays = \DB::table('reviews')
            ->join('documents', 'reviews.document_id', '=', 'documents.id')
            ->whereIn('reviews.decision', ['approved', 'rejected'])
            ->where('reviews.reviewed_at', '>=', now()->subDays(30))
            ->whereNotNull('documents.submitted_at')
            ->when($officeId, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('documents.target_office_id', $officeId)
                ->orWhereNull('documents.target_office_id')))
            ->selectRaw('AVG(DATEDIFF(reviews.reviewed_at, documents.submitted_at)) as avg_days')
            ->value('avg_days');

        return response()->json([
            'documents' => $documents,
            'requests' => $requests,
            'awaiting_review' => $documents['pending'] + $requests['pending'],
            'avg_lead_days' => $avgLeadDays !== null ? (int) round($avgLeadDays) : null,
        ]);
    }

    /**
     * POST /api/dashboard/requests
     */
    public function storeRequest(Request $request)
    {
        $data = $request->validate(
            $this->requestMetadataRules(required: true),
            $this->requestMetadataMessages(),
        );
        $this->assertAmountPresentIfRequired($data);
        $this->assertJustificationPresentIfRequired($data);

        $targetOfficeId = $data['target_office_id'] ?? $request->user()->office_id;

        $submission = $this->createWithTrackingNo(
            fn () => $this->generateRequestTrackingNo($data['request_type_id']),
            fn (string $trackingNo) => SubmissionRequest::create([
                'tracking_no' => $trackingNo,
                'request_type_id' => $data['request_type_id'],
                'requested_by' => $request->user()->id,
                'target_office_id' => $targetOfficeId,
                'status' => 'pending',
                'submitted_at' => now(),
                ...$this->requestMetadata($data),
            ])
        );

        $this->routeForReview($submission, $targetOfficeId);

        $this->notifySubmissionReceived($request->user()->id, $submission->tracking_no);
        $this->notifyReviewers($request->user()->id, $submission->tracking_no, 'request', assigneeId: $submission->assigned_to, targetOfficeId: $targetOfficeId);

        AuditLog::record(
            $request->user()->id,
            'request_submitted',
            "Submitted request {$submission->tracking_no}.",
            SubmissionRequest::class,
            $submission->id
        );

        return response()->json(
            $this->formatRequest($submission->load('requestType')),
            201
        );
    }

    /**
     * POST /api/dashboard/documents
     */
    /**
     * POST /api/dashboard/documents/draft
     * PATCH /api/dashboard/documents/{id}/draft
     *
     * "Draft — document details are being encoded but not yet submitted."
     * Only a title is required: the whole point of a draft is that it is
     * incomplete. Completeness (BR-02) and the access-level policy are
     * enforced when it is submitted, not while it is being written.
     *
     * A draft is invisible to reviewers — it has no tracking number, is
     * not routed, notifies nobody, and is excluded from every queue and
     * stat until submitDraft() promotes it.
     */
    public function storeDraft(Request $request, ?int $id = null)
    {
        $user = $request->user();

        $data = $request->validate(
            $this->draftRules(),
            $this->documentMetadataMessages(),
        );

        $document = $id === null
            ? null
            : Document::where('uploaded_by', $user->id)
                ->where('status', Document::STATUS_DRAFT)
                ->findOrFail($id);

        $attributes = $this->documentMetadata($data, onlyPresent: true);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $this->scanUpload($file, $user);
            $attributes['file_path'] = $file->store('documents', Document::DISK);
            $attributes['file_format'] = $this->fileFormat($file);
            $attributes['file_size'] = $file->getSize();
            $attributes['content_hash'] = hash_file('sha256', $file->getPathname()) ?: null;
            $attributes['extracted_text'] = null;
            $attributes['text_extracted_at'] = null;
        }

        if (array_key_exists('target_office_id', $data)) {
            $attributes['target_office_id'] = $data['target_office_id'];
        }

        if ($document === null) {
            $document = Document::create([
                ...$attributes,
                'uploaded_by' => $user->id,
                'office_id' => $user->office_id,
                'status' => Document::STATUS_DRAFT,
                // A draft has no tracking number yet: numbers are issued
                // on submission so a draft never consumes one it might
                // not use, and never leaves a gap in the daily sequence.
                'tracking_no' => null,
                'submitted_at' => null,
            ]);

            AuditLog::record(
                $user->id,
                'document_draft_created',
                "Started a draft document (\"{$document->title}\").",
                Document::class,
                $document->id,
            );
        } else {
            $document->update($attributes);

            AuditLog::record(
                $user->id,
                'document_draft_updated',
                "Updated draft document (\"{$document->title}\").",
                Document::class,
                $document->id,
            );
        }

        return response()->json(
            $this->formatDocument($document->load('category')),
            $id === null ? 201 : 200,
        );
    }

    /**
     * POST /api/dashboard/documents/{id}/submit
     * Promote a draft into the review queue — the point at which the full
     * metadata rules (BR-02) and the access-level policy finally bite, and
     * the document is issued its tracking number and routed.
     */
    public function submitDraft(Request $request, int $id)
    {
        $user = $request->user();

        $document = Document::where('uploaded_by', $user->id)
            ->where('status', Document::STATUS_DRAFT)
            ->findOrFail($id);

        // Accept last-minute edits in the same call as the submit.
        $data = $request->validate(
            $this->documentMetadataRules(fileRequired: false, required: false),
            $this->documentMetadataMessages(),
        );

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $this->scanUpload($file, $user);
            $document->fill([
                'file_path' => $file->store('documents', Document::DISK),
                'file_format' => $this->fileFormat($file),
                'file_size' => $file->getSize(),
                'content_hash' => hash_file('sha256', $file->getPathname()) ?: null,
                'extracted_text' => null,
                'text_extracted_at' => null,
            ]);
        }

        $document->fill($this->documentMetadata($data, onlyPresent: true));

        if (array_key_exists('target_office_id', $data)) {
            $document->target_office_id = $data['target_office_id'];
        }

        // Everything BR-02 requires must be present *now*, even though the
        // draft was allowed to be missing it.
        $this->assertDraftIsComplete($document);
        $this->assertAccessLevelFitsCategory(
            ['category_id' => $document->category_id, 'access_level' => $document->access_level],
        );

        $targetOfficeId = $document->target_office_id ?? $user->office_id;

        $document->fill([
            'status' => Document::STATUS_PENDING,
            'target_office_id' => $targetOfficeId,
            'submitted_at' => now(),
        ]);

        // Issue the tracking number at submission, not at draft creation.
        $this->createWithTrackingNo(
            fn () => $this->generateDocumentTrackingNo($document->category_id, $user->office_id),
            function (string $trackingNo) use ($document) {
                $document->tracking_no = $trackingNo;
                $document->save();

                return $document;
            },
        );

        $this->routeForReview($document, $targetOfficeId);
        DocumentStageEvent::record($document, DocumentStageEvent::STAGE_UPLOADED, $user->id);

        $this->notifySubmissionReceived($user->id, $document->tracking_no);
        $this->notifyReviewers(
            $user->id,
            $document->tracking_no,
            'document',
            assigneeId: $document->assigned_to,
            targetOfficeId: $targetOfficeId,
        );

        AuditLog::record(
            $user->id,
            'document_uploaded',
            "Submitted draft as document {$document->tracking_no} ({$document->title}).",
            Document::class,
            $document->id,
        );

        ExtractDocumentText::dispatch($document);
        AnalyzeDocument::dispatch($document);

        return response()->json($this->formatDocument($document->load('category')));
    }

    /**
     * DELETE /api/dashboard/documents/{id}/draft
     * Discard a draft. Only ever a draft: a submitted document is part of
     * the record and is archived/disposed through retention instead.
     */
    public function destroyDraft(Request $request, int $id)
    {
        $document = Document::where('uploaded_by', $request->user()->id)
            ->where('status', Document::STATUS_DRAFT)
            ->findOrFail($id);

        $title = $document->title;
        $document->delete();

        AuditLog::record(
            $request->user()->id,
            'document_draft_discarded',
            "Discarded draft document (\"{$title}\").",
            Document::class,
            $id,
        );

        return response()->json(['message' => 'Draft discarded.']);
    }

    /**
     * A draft may be saved half-finished, but it may not be SUBMITTED
     * half-finished — this is BR-02 applied at the promotion boundary.
     */
    private function assertDraftIsComplete(Document $document): void
    {
        $missing = [];

        foreach ([
            'title' => 'a title',
            'category_id' => 'a category',
            'document_type' => 'a document type',
            'document_date' => 'the document date',
            'reporting_period' => 'the reporting period',
            'access_level' => 'an access level',
            'keywords' => 'keywords',
            'description' => 'a description',
            'file_path' => 'an attached file',
            // Source office / unit — taken from the account, not typed.
            'office_id' => 'a source office on your account (ask a system admin)',
        ] as $field => $label) {
            if (blank($document->{$field})) {
                $missing[$field] = "This draft still needs {$label}.";
            }
        }

        if (filled($document->description) && mb_strlen((string) $document->description) < 20) {
            $missing['description'] = 'The description is too short — give a sentence or two.';
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /**
     * Draft validation: shape-check whatever was sent, require nothing but
     * a title. Contrast documentMetadataRules(), which enforces BR-02.
     *
     * @return array<string, mixed>
     */
    private function draftRules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'document_type' => ['nullable', Rule::in(Document::TYPES)],
            'document_date' => ['nullable', 'date'],
            'reporting_period' => ['nullable', 'string', 'max:120'],
            'access_level' => ['nullable', Rule::in(Document::ACCESS_LEVELS)],
            'keywords' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'target_office_id' => ['nullable', 'exists:offices,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'file' => [
                'nullable',
                'file',
                'max:'.config('documents.max_upload_kb'),
                'mimes:'.implode(',', config('documents.allowed_mimes')),
            ],
        ];
    }

    public function storeDocument(Request $request)
    {
        $data = $request->validate(
            $this->documentMetadataRules(fileRequired: true),
            $this->documentMetadataMessages(),
        );

        $this->assertAccessLevelFitsCategory($data);

        $file = $request->file('file');
        $user = $request->user();

        $this->assertSourceOfficeIsKnown($user);
        $this->scanUpload($file, $user);

        // Duplicate detection (PF-06 / AI-03): a SHA-256 of the file's
        // bytes. Advisory only — the submission still goes through (BR-03:
        // nothing here blocks on an automated finding); the reviewer sees
        // the flag and decides. Checked before the file is stored so the
        // hash is of the upload, not a moved copy.
        $hash = hash_file('sha256', $file->getPathname()) ?: null;
        $duplicateOf = $hash === null ? null : Document::query()
            ->possibleDuplicateOf($hash, $user)
            ->first();

        $path = $file->store('documents', Document::DISK);

        $targetOfficeId = $data['target_office_id'] ?? $user->office_id;

        // Step 6 — the uploader confirmed the preflight's "new version of
        // X" finding, so this upload continues X rather than starting a
        // new record: same tracking number, version_number + 1, and the
        // prior state frozen as a version row (FR-11 / FR-12 / PF-17).
        if (($supersedesId = $data['supersedes_id'] ?? null) !== null) {
            return $this->storeAsNewVersion(
                (int) $supersedesId,
                $data,
                $user,
                $path,
                $file,
                $hash,
                $targetOfficeId,
            );
        }

        $document = $this->createWithTrackingNo(
            fn () => $this->generateDocumentTrackingNo($data['category_id'], $user->office_id),
            fn (string $trackingNo) => Document::create([
                'tracking_no' => $trackingNo,
                'uploaded_by' => $user->id,
                'office_id' => $user->office_id,
                'target_office_id' => $targetOfficeId,
                'file_path' => $path,
                'file_format' => $this->fileFormat($file),
                'file_size' => $file->getSize(),
                'content_hash' => $hash,
                'status' => 'pending',
                'submitted_at' => now(),
                ...$this->documentMetadata($data),
            ])
        );

        $this->routeForReview($document, $targetOfficeId);
        DocumentStageEvent::record($document, DocumentStageEvent::STAGE_UPLOADED, $user->id);

        $this->notifySubmissionReceived($user->id, $document->tracking_no);
        $this->notifyReviewers($user->id, $document->tracking_no, 'document', assigneeId: $document->assigned_to, targetOfficeId: $targetOfficeId);

        AuditLog::record(
            $user->id,
            'document_uploaded',
            "Uploaded document {$document->tracking_no} ({$document->title}).",
            Document::class,
            $document->id
        );

        if ($duplicateOf !== null) {
            AuditLog::record(
                $user->id,
                'duplicate_flagged',
                "Upload {$document->tracking_no} is byte-identical to existing document {$duplicateOf->tracking_no}.",
                Document::class,
                $document->id,
                ['duplicate_of' => $duplicateOf->tracking_no, 'duplicate_of_id' => $duplicateOf->id],
            );
        }

        ExtractDocumentText::dispatch($document);
        AnalyzeDocument::dispatch($document);

        $payload = $this->formatDocument($document->load('category'));

        if ($duplicateOf !== null) {
            $payload['duplicate_of'] = [
                'id' => $duplicateOf->id,
                'ref' => $duplicateOf->tracking_no,
                'title' => $duplicateOf->title,
                'status' => $duplicateOf->status,
            ];
        }

        return response()->json($payload, 201);
    }

    /**
     * Step 6 outcome "new version": file this upload as the next version
     * of an existing document the uploader owns (or their office does)
     * instead of creating a separate record. Mirrors resubmitDocument's
     * versioning, but is reachable from a fresh upload rather than only
     * from a "needs revision" bounce.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeAsNewVersion(
        int $supersedesId,
        array $data,
        User $user,
        string $path,
        UploadedFile $file,
        ?string $hash,
        ?int $targetOfficeId,
    ) {
        $document = Document::query()
            ->where('id', $supersedesId)
            ->where('retention_status', 'active')
            ->where(function ($q) use ($user) {
                $q->where('uploaded_by', $user->id)
                    ->when($user->office_id, fn ($qq, $office) => $qq->orWhere('office_id', $office));
            })
            ->first();

        if ($document === null) {
            throw ValidationException::withMessages([
                'supersedes_id' => 'That document is not one you can file a new version of.',
            ]);
        }

        if ($document->status === 'pending') {
            throw ValidationException::withMessages([
                'supersedes_id' => "{$document->tracking_no} is still awaiting review — "
                    .'wait for a decision before filing a new version of it.',
            ]);
        }

        $document->loadMissing('review');
        $version = $document->snapshotAsVersion($user->id);

        $document->update([
            'status' => 'pending',
            'submitted_at' => now(),
            'version_number' => $document->version_number + 1,
            'target_office_id' => $targetOfficeId,
            'file_path' => $path,
            'file_format' => $this->fileFormat($file),
            'file_size' => $file->getSize(),
            'content_hash' => $hash,
            'extracted_text' => null,
            'text_extracted_at' => null,
            ...$this->documentMetadata($data),
        ]);

        $this->routeForReview($document, $targetOfficeId);
        DocumentStageEvent::record($document, DocumentStageEvent::STAGE_UPLOADED, $user->id);

        $this->notifySubmissionReceived($user->id, $document->tracking_no, isResubmission: true);
        $this->notifyReviewers(
            $user->id,
            $document->tracking_no,
            'document',
            isResubmission: true,
            assigneeId: $document->assigned_to,
            targetOfficeId: $targetOfficeId,
        );

        AuditLog::record(
            $user->id,
            'document_versioned',
            "Filed a new version (v{$document->version_number}) of {$document->tracking_no} from a fresh upload.",
            Document::class,
            $document->id,
            [
                'new_version' => $document->version_number,
                'superseded_version' => $version->version_number,
                'superseded_file' => $version->file_path,
            ],
        );

        ExtractDocumentText::dispatch($document);
        AnalyzeDocument::dispatch($document);

        return response()->json($this->formatDocument($document->load('category')), 201);
    }

    /**
     * POST /api/dashboard/requests/{id}/resubmit
     * Continues the SAME record after "needs revision" — same
     * tracking number, status reset to pending — rather than creating
     * a brand-new submission, matching the flowchart's resubmit loop.
     */
    public function resubmitRequest(Request $request, int $id)
    {
        $submission = SubmissionRequest::where('requested_by', $request->user()->id)->findOrFail($id);

        if ($submission->status !== 'revision') {
            return response()->json([
                'message' => 'Only submissions marked "needs revision" can be resubmitted.',
            ], 422);
        }

        // The request was already complete when first submitted, so on
        // resubmission every field is optional — send only what changed.
        $data = $request->validate(
            $this->requestMetadataRules(required: false),
            $this->requestMetadataMessages(),
        );
        $this->assertAmountPresentIfRequired($data, $submission);
        $this->assertJustificationPresentIfRequired($data, $submission);

        $submission->update([
            'status' => 'pending',
            'submitted_at' => now(),
            ...$this->requestMetadata($data, onlyPresent: true),
        ]);

        $this->routeForReview($submission, $submission->target_office_id ?? $request->user()->office_id);

        $this->notifySubmissionReceived($request->user()->id, $submission->tracking_no, isResubmission: true);
        $this->notifyReviewers($request->user()->id, $submission->tracking_no, 'request', isResubmission: true, assigneeId: $submission->assigned_to, targetOfficeId: $submission->target_office_id);

        AuditLog::record(
            $request->user()->id,
            'request_resubmitted',
            "Resubmitted request {$submission->tracking_no} after revision.",
            SubmissionRequest::class,
            $submission->id
        );

        return response()->json($this->formatRequest($submission->load(['requestType', 'review'])));
    }

    /**
     * POST /api/dashboard/documents/{id}/resubmit
     * Same tracking number as the original — only status resets and
     * submitted_at updates. A new file is optional: if omitted, the
     * previously uploaded file is kept as-is.
     */
    public function resubmitDocument(Request $request, int $id)
    {
        $document = Document::where('uploaded_by', $request->user()->id)->findOrFail($id);

        if ($document->status !== 'revision') {
            return response()->json([
                'message' => 'Only submissions marked "needs revision" can be resubmitted.',
            ], 422);
        }

        // The document was already complete when first submitted (BR-02 is
        // enforced in storeDocument), so on resubmission every metadata
        // field is optional — send only what changed.
        $data = $request->validate(
            $this->documentMetadataRules(fileRequired: false, required: false),
            $this->documentMetadataMessages(),
        );

        // A resubmission may change either half of the pair, so validate
        // the resulting combination, not just what was sent.
        $this->assertAccessLevelFitsCategory($data, $document);

        // Freeze the current state as version N before overwriting it
        // (FR-11 / FR-12 / PF-17 / BR-05). The version's file is left on
        // disk (Phase 3.1) so an earlier version stays retrievable.
        $document->loadMissing('review');
        $version = $document->snapshotAsVersion($request->user()->id);
        $fileReplaced = $request->hasFile('file');

        $updates = [
            'status' => 'pending',
            'submitted_at' => now(),
            'version_number' => $document->version_number + 1,
            ...$this->documentMetadata($data, onlyPresent: true),
        ];

        if ($fileReplaced) {
            $file = $request->file('file');
            $this->scanUpload($file, $request->user());
            $updates['file_path'] = $file->store('documents', Document::DISK);
            $updates['file_format'] = $this->fileFormat($file);
            $updates['file_size'] = $file->getSize();
            $updates['content_hash'] = hash_file('sha256', $file->getPathname()) ?: null;
        }

        $document->update($updates);

        $this->routeForReview($document, $document->target_office_id ?? $request->user()->office_id);
        DocumentStageEvent::record($document, DocumentStageEvent::STAGE_RESUBMITTED, $request->user()->id);

        $this->notifySubmissionReceived($request->user()->id, $document->tracking_no, isResubmission: true);
        $this->notifyReviewers($request->user()->id, $document->tracking_no, 'document', isResubmission: true, assigneeId: $document->assigned_to, targetOfficeId: $document->target_office_id);

        AuditLog::record(
            $request->user()->id,
            'document_resubmitted',
            "Resubmitted document {$document->tracking_no} as v{$document->version_number}"
                .($fileReplaced ? ' (file replaced).' : '.'),
            Document::class,
            $document->id,
            [
                'new_version' => $document->version_number,
                'superseded_version' => $version->version_number,
                'superseded_file' => $version->file_path,
            ]
        );

        ExtractDocumentText::dispatch($document);
        AnalyzeDocument::dispatch($document);

        return response()->json($this->formatDocument($document->load(['category', 'review'])));
    }

    /**
     * Confirmation notification on successful submission (objective 2.7) —
     * distinct from the review-decision notifications ReviewController fires.
     */
    private function notifySubmissionReceived(int $userId, string $ref, bool $isResubmission = false): void
    {
        $noun = $isResubmission ? 'resubmission' : 'submission';

        Notifier::send(
            $userId,
            'submission_confirmation',
            "Your {$noun} {$ref} was received and is pending review.",
            '/dashboard',
            config('app.name')." — {$noun} {$ref} received",
        );
    }

    /**
     * Notify the office_admin side that something has entered the queue
     * (audit E-14 / D-12). Notifications are scoped to the target office's
     * admin pool — admins in other offices are not notified.
     * When the item was routed to a specific assignee (Phase 4.3), only
     * they are notified; otherwise the whole target-office pool is, minus
     * the submitter if they are themselves an office_admin.
     */
    private function notifyReviewers(int $submitterId, string $ref, string $kind, bool $isResubmission = false, ?int $assigneeId = null, ?int $targetOfficeId = null): void
    {
        $verb = $isResubmission ? 'resubmitted and is back in the queue' : 'is awaiting review';

        // Routed to one reviewer — a direct, actionable handoff, so this
        // one also goes out by email (config/notifications.php).
        if ($assigneeId !== null && $assigneeId !== $submitterId) {
            Notifier::send(
                $assigneeId,
                'review_pending',
                ucfirst($kind)." {$ref} {$verb} (assigned to you).",
                '/office-admin',
                config('app.name')." — {$kind} {$ref} assigned to you",
            );

            return;
        }

        // Broadcast to the target office's active admin pool — in-app + live
        // push, but never emailed (review_queue is not in config/notifications.php
        // email_types), so a busy queue doesn't spam every admin's inbox.
        $pool = User::query()
            ->where('role', User::ROLE_OFFICE_ADMIN)
            ->where('is_active', true)
            ->where('id', '!=', $submitterId)
            ->when($targetOfficeId, fn ($q) => $q->where('office_id', $targetOfficeId))
            ->when($assigneeId !== null, fn ($q) => $q->where('id', '!=', $assigneeId))
            ->pluck('id');

        Notifier::sendMany($pool, 'review_queue', ucfirst($kind)." {$ref} {$verb}.", '/office-admin');
    }

    /**
     * Assign a tracking number and create the record, retrying if a
     * concurrent submission grabbed the same number first — the sequence
     * is derived from a live COUNT(*), so two requests can compute the
     * same value before either inserts. The `tracking_no` unique index
     * is the backstop; this turns the resulting error into the next
     * number instead of a 500.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  callable():string  $generate
     * @param  callable(string):TModel  $create
     * @return TModel
     */
    private function createWithTrackingNo(callable $generate, callable $create)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $create($generate());
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Standardized naming convention (objective 1.1): CATEGORY-OFFICE-YYYYMMDD-SEQ,
     * e.g. FIN-HQ-20260814-001. Sequence resets daily per category+office.
     */
    private function generateDocumentTrackingNo(int $categoryId, ?int $officeId): string
    {
        $categoryCode = Category::find($categoryId)?->category_code ?? 'DOC';
        $officeCode = $officeId ? (Office::find($officeId)?->office_code ?? 'GEN') : 'GEN';
        $prefix = "{$categoryCode}-{$officeCode}-".now()->format('Ymd').'-';

        $seq = $this->nextSequence(Document::where('tracking_no', 'like', $prefix.'%')->pluck('tracking_no'), $prefix);

        return $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Same convention for requests: REQUESTTYPE-YYYYMMDD-SEQ (no office
     * segment — requests aren't tied to a submitting office in the ERD).
     */
    private function generateRequestTrackingNo(int $requestTypeId): string
    {
        $typeCode = RequestType::find($requestTypeId)?->type_code ?? 'REQ';
        $prefix = "{$typeCode}-".now()->format('Ymd').'-';

        $seq = $this->nextSequence(SubmissionRequest::where('tracking_no', 'like', $prefix.'%')->pluck('tracking_no'), $prefix);

        return $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Next daily sequence for a tracking-number prefix: one past the
     * highest suffix already in use. MAX-based, not COUNT-based, so gaps
     * (from a failed/rolled-back insert) never produce a number that is
     * already taken.
     *
     * @param  Collection<int, string>  $existing
     */
    private function nextSequence($existing, string $prefix): int
    {
        $highest = $existing
            ->map(fn (string $trackingNo) => (int) substr($trackingNo, strlen($prefix)))
            ->max() ?? 0;

        return $highest + 1;
    }

    /**
     * What the submitter can download from a decision, if anything — an
     * uploaded file, or a repository document handed over in its place.
     *
     * @return array<string, mixed>|null
     */
    private function responseFileFor(?\App\Models\Review $review): ?array
    {
        if ($review === null) {
            return null;
        }

        if ($review->response_file_path) {
            return [
                'review_id' => $review->id,
                'name' => $review->response_file_name,
                'from_repository' => false,
            ];
        }

        if ($review->response_document_id) {
            $linked = $review->responseDocument;

            return [
                'review_id' => $review->id,
                'name' => $linked?->title ?? 'Linked document',
                'ref' => $linked?->tracking_no,
                'from_repository' => true,
            ];
        }

        return null;
    }

    private function formatRequest(SubmissionRequest $r, bool $includeSubmitter = false): array
    {
        return [
            'id' => $r->id,
            'kind' => 'request',
            'ref' => $r->tracking_no,
            'type' => $r->requestType?->type_name,
            'request_type_id' => $r->request_type_id,
            // Whether this type is "subject to approval" — the detail
            // panel keeps the reason row visible for these even when it
            // is empty, since a missing reason is worth seeing.
            'requires_justification' => (bool) $r->requestType?->requires_justification,
            'title' => $r->title,
            'description' => $r->description,
            'needed_by' => $r->needed_by?->toDateString(),
            'amount' => $r->amount,
            'access_level' => $r->access_level,
            'uploader_remarks' => $r->remarks,
            'submitted_at' => $r->submitted_at,
            'status' => $r->status,
            'target_office_id' => $r->target_office_id,
            'assigned_to' => $r->assigned_to,
            'assignee' => $r->assignee?->full_name,
            'remarks' => $r->review?->remarks,
            'response_file' => $this->responseFileFor($r->review),
            'submitter' => $includeSubmitter ? $r->requester?->full_name : null,
        ];
    }

    private function formatDocument(Document $d, bool $includeSubmitter = false): array
    {
        return [
            'id' => $d->id,
            'kind' => 'document',
            'ref' => $d->tracking_no,
            'type' => $d->title,
            'title' => $d->title,
            'document_type' => $d->document_type,
            'document_date' => $d->document_date?->toDateString(),
            'reporting_period' => $d->reporting_period,
            'access_level' => $d->access_level,
            'keywords' => $d->keywords,
            'description' => $d->description,
            'uploader_remarks' => $d->remarks,
            'category' => $d->category?->category_name,
            'category_id' => $d->category_id,
            'file_format' => $d->file_format,
            'file_size' => $d->file_size,
            'version_number' => $d->version_number,
            'retention_status' => $d->retention_status,
            // Source office — the unit the document came FROM, taken from
            // the uploader's account. Distinct from target_office_id,
            // which is where the submission was routed TO.
            'office_id' => $d->office_id,
            'source_office' => $d->office?->office_name,
            'target_office_id' => $d->target_office_id,
            'submitted_at' => $d->submitted_at,
            'status' => $d->status,
            // "Submitted" vs "For review" — derived from assignment, not
            // a stored status. See Document::reviewStage().
            'review_stage' => $d->reviewStage(),
            'assigned_to' => $d->assigned_to,
            'assignee' => $d->assignee?->full_name,
            // Advisory lead-time signal (Phase 7.1 / decision 0.9).
            'days_in_stage' => Target::daysInStage($d),
            'target_days' => Target::reviewDays($d),
            'overdue' => Target::isOverdue($d),
            'remarks' => $d->review?->remarks,
            'response_file' => $this->responseFileFor($d->review),
            'submitter' => $includeSubmitter ? $d->uploader?->full_name : null,
        ];
    }

    /**
     * Minimum request metadata (decision 0.7 / request-workflow-spec.md).
     * $required=false makes every field `sometimes` — used on resubmit.
     */
    private function requestMetadataRules(bool $required = true): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'request_type_id' => [$req, $required
                ? Rule::exists('request_types', 'id')->where('is_active', true)
                : 'exists:request_types,id'],
            'title' => [$req, 'string', 'min:3', 'max:255'],
            'description' => [$req, 'string', 'min:20', 'max:2000'],
            'needed_by' => [$req, 'date'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'access_level' => ['sometimes', Rule::in(Document::ACCESS_LEVELS)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'target_office_id' => ['nullable', 'exists:offices,id'],
        ];
    }

    private function requestMetadataMessages(): array
    {
        return [
            'request_type_id.required' => 'Choose a request type before submitting.',
            'request_type_id.exists' => 'That request type is no longer available.',
            'title.required' => 'Give the request a short title.',
            'description.required' => 'Describe what is being requested and why.',
            'description.min' => 'The description is too short — give a sentence or two.',
            'needed_by.required' => 'State when you need this by.',
            'amount.numeric' => 'Enter the amount as a number.',
        ];
    }

    /**
     * BUD / SUP requests must carry an amount (request-workflow-spec.md).
     * On resubmit the existing value counts.
     */
    /**
     * Some request types are "subject to approval" — an urgent request
     * that jumps the queue, or a sensitive one that releases restricted
     * material. Those demand a stated reason in `remarks`, which is what
     * the reviewer weighs when deciding whether the claim stands.
     *
     * `description` says WHAT is being asked for; this says WHY it
     * warrants the exception. Conflating them would bury the reason.
     */
    private function assertJustificationPresentIfRequired(array $data, ?SubmissionRequest $existing = null): void
    {
        $typeId = $data['request_type_id'] ?? $existing?->request_type_id;
        $type = $typeId ? RequestType::find($typeId) : null;

        if ($type === null || ! $type->requires_justification) {
            return;
        }

        $reason = $data['remarks'] ?? $existing?->remarks;

        if (blank($reason) || mb_strlen(trim((string) $reason)) < 20) {
            $name = mb_strtolower($type->type_name);
            $firstLetter = mb_substr($name, 0, 1);
            $article = ($firstLetter !== '' && str_contains('aeiou', $firstLetter)) ? 'An' : 'A';

            throw ValidationException::withMessages([
                'remarks' => "{$article} {$name} needs a reason (at least 20 characters) — "
                    .'state the deadline, meeting, or authority behind it so the reviewer can decide.',
            ]);
        }
    }

    private function assertAmountPresentIfRequired(array $data, ?SubmissionRequest $existing = null): void
    {
        $typeId = $data['request_type_id'] ?? $existing?->request_type_id;
        $code = $typeId ? RequestType::find($typeId)?->type_code : null;

        if (! in_array($code, SubmissionRequest::AMOUNT_REQUIRED_TYPE_CODES, true)) {
            return;
        }

        $amount = $data['amount'] ?? $existing?->amount;
        if ($amount === null || $amount === '') {
            throw ValidationException::withMessages([
                'amount' => 'This request type needs an amount.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function requestMetadata(array $data, bool $onlyPresent = false): array
    {
        $keys = ['request_type_id', 'title', 'description', 'needed_by', 'amount', 'access_level', 'remarks'];

        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            } elseif (! $onlyPresent && in_array($key, ['amount', 'remarks'], true)) {
                $out[$key] = null;
            }
        }

        return $out;
    }

    /**
     * Validation rules for the documented minimum document metadata
     * (DR-01…DR-10 + the file). When $required is false every field
     * becomes `sometimes` — used on resubmission.
     */
    private function documentMetadataRules(bool $fileRequired, bool $required = true): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'min:3', 'max:255'],
            'document_type' => [$req, Rule::in(Document::TYPES)],
            'document_date' => [$req, 'date'],
            'reporting_period' => [$req, 'string', 'max:120'],
            'access_level' => [$req, Rule::in(Document::ACCESS_LEVELS)],
            'keywords' => [$req, 'string', 'min:2', 'max:500'],
            'description' => [$req, 'string', 'min:20', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'target_office_id' => ['nullable', 'exists:offices,id'],
            // Step 6 — the uploader confirmed the preflight's "this is a
            // new version of X" finding. The upload then continues X's
            // version chain instead of creating an unrelated record.
            'supersedes_id' => ['nullable', 'integer', 'exists:documents,id'],
            'category_id' => [$req, $required
                ? Rule::exists('categories', 'id')->where('is_active', true)
                : 'exists:categories,id'],
            'file' => [
                $fileRequired ? 'required' : 'nullable',
                'file',
                'max:'.config('documents.max_upload_kb'),
                'mimes:'.implode(',', config('documents.allowed_mimes')),
            ],
        ];
    }

    /**
     * Step 9 — the access level a submitter chose must be one the
     * document's category permits (config/documents.php access_policy).
     * Applied on upload and on resubmission; ReviewController applies the
     * same rule when a reviewer changes the level at approval.
     *
     * @param  array<string, mixed>  $data  validated input
     */
    /**
     * Source office / unit is required metadata, but it is taken from the
     * uploader's account rather than typed — so the thing to check is that
     * the account actually has one. users.office_id is nullable while
     * documents.office_id is not, so without this an office-less account
     * would fail on a raw integrity error instead of a message anyone can
     * act on.
     */
    private function assertSourceOfficeIsKnown(User $user): void
    {
        if ($user->office_id === null) {
            throw ValidationException::withMessages([
                'office_id' => 'Your account has no office assigned, so the source office '
                    .'cannot be recorded. Ask a system admin to set your office before submitting.',
            ]);
        }
    }

    private function assertAccessLevelFitsCategory(array $data, ?Document $existing = null): void
    {
        $categoryId = $data['category_id'] ?? $existing?->category_id;
        $accessLevel = $data['access_level'] ?? $existing?->access_level;

        if ($categoryId === null || $accessLevel === null) {
            return;
        }

        $policy = app(AccessLevelPolicy::class);

        if (! $policy->permits((int) $categoryId, (string) $accessLevel)) {
            throw ValidationException::withMessages([
                'access_level' => $policy->rejectionMessage((int) $categoryId, (string) $accessLevel),
            ]);
        }
    }

    private function documentMetadataMessages(): array
    {
        return [
            'title.required' => 'A title is required.',
            'title.min' => 'Title is too short to be meaningful — use at least 3 characters.',
            'document_type.required' => 'Choose a document type.',
            'document_type.in' => 'That is not a recognised document type.',
            'document_date.required' => 'Enter the date on the document itself.',
            'reporting_period.required' => 'State the reporting or coverage period (e.g. "AY 2025–2026").',
            'access_level.required' => 'Choose a proposed access level.',
            'access_level.in' => 'That is not a valid access level.',
            'keywords.required' => 'Add at least one keyword or tag.',
            'description.required' => 'Add a brief description / abstract.',
            'description.min' => 'The description is too short — give a sentence or two.',
            'category_id.required' => 'Choose a category before submitting.',
            'category_id.exists' => 'That category is no longer available.',
            'file.required' => 'Attach a file — submissions without a document are incomplete.',
            'file.mimes' => 'Accepted file types: '.implode(', ', config('documents.allowed_mimes')).'.',
            'file.max' => 'File is too large — the limit is '.round(config('documents.max_upload_kb') / 1024).'MB.',
        ];
    }

    /**
     * Pull the persistable metadata keys out of validated input.
     * With $onlyPresent the caller gets just the keys that were sent
     * (resubmission: leave everything else untouched).
     */
    private function documentMetadata(array $data, bool $onlyPresent = false): array
    {
        $keys = ['title', 'document_type', 'document_date', 'reporting_period',
            'access_level', 'keywords', 'description', 'remarks', 'category_id'];

        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            } elseif (! $onlyPresent && $key === 'remarks') {
                $out[$key] = null;
            }
        }

        return $out;
    }

    private function fileFormat(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin'));
    }

    /**
     * Malware scan an upload before it is stored (PF-03, Phase 7.3).
     * An infected file is always rejected. If the scanner is unreachable
     * the config `scanning.fail_open` flag decides accept-or-reject;
     * either outcome is audited.
     */
    private function scanUpload(UploadedFile $file, User $user): void
    {
        $result = app(FileScanner::class)->scan($file->getPathname());
        $name = $file->getClientOriginalName();

        if (! $result->clean && $result->scannerAvailable) {
            AuditLog::record(
                $user->id,
                'document_scan_blocked',
                "Upload \"{$name}\" was blocked by malware scanning: {$result->reason}",
                null,
                null,
                ['reason' => $result->reason],
            );

            throw ValidationException::withMessages([
                'file' => 'This file was rejected by malware scanning.',
            ]);
        }

        if (! $result->scannerAvailable) {
            $failOpen = (bool) config('scanning.fail_open');

            AuditLog::record(
                $user->id,
                $failOpen ? 'document_scan_skipped' : 'document_scan_blocked',
                "Malware scanner unavailable for \"{$name}\": {$result->reason}"
                    .($failOpen ? ' — accepted (fail-open).' : ' — rejected (fail-closed).'),
                null,
                null,
                ['reason' => $result->reason, 'fail_open' => $failOpen],
            );

            if (! $failOpen) {
                throw ValidationException::withMessages([
                    'file' => 'Uploads are temporarily unavailable — the malware scanner could not be reached.',
                ]);
            }
        }
    }
}
