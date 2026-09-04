<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeDocument;
use App\Jobs\ExtractDocumentText;
use App\LeadTime\Target;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentStageEvent;
use App\Models\Notification;
use App\Models\Office;
use App\Models\RequestType;
use App\Models\SubmissionRequest;
use App\Models\User;
use App\Scanning\Contracts\FileScanner;
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

        $documents = Document::with(['category', 'review', 'assignee'])
            ->where('uploaded_by', $userId)
            ->get()
            ->map(fn ($d) => $this->formatDocument($d));

        $merged = $requests->concat($documents)
            ->sortByDesc('submitted_at')
            ->values();

        return response()->json($merged);
    }

    /**
     * GET /api/osm-admin/queue[?scope=all|mine|unassigned]
     * Pending requests + documents for the OSM review queue (PF-08).
     * The queue is no longer a single unscoped pull:
     *   mine       — assigned to the calling reviewer
     *   unassigned — no assignee yet; office_queue routing additionally
     *                limits to the caller's own office (submitters with
     *                no office stay visible to everyone)
     *   all        — every pending item (default, back-compatible)
     */
    public function queue(Request $request)
    {
        $scope = $request->validate([
            'scope' => ['nullable', 'in:all,mine,unassigned'],
        ])['scope'] ?? 'all';

        $reviewer = $request->user();

        // $officeScoped: documents carry an office_id and can be routed to
        // an office queue; requests do not (not in the ERD), so for them
        // "unassigned" simply means no assignee yet.
        $apply = function ($query, bool $officeScoped) use ($scope, $reviewer) {
            $query->where('status', 'pending');

            if ($scope === 'mine') {
                $query->where('assigned_to', $reviewer->id);
            } elseif ($scope === 'unassigned') {
                $query->whereNull('assigned_to');

                if ($officeScoped
                    && config('review.routing.strategy') === 'office_queue'
                    && $reviewer->office_id) {
                    $query->where(fn ($q) => $q
                        ->where('office_id', $reviewer->office_id)
                        ->orWhereNull('office_id'));
                }
            }

            return $query;
        };

        $requests = $apply(SubmissionRequest::with(['requestType', 'requester', 'assignee']), false)
            ->get()
            ->map(fn ($r) => $this->formatRequest($r, includeSubmitter: true));

        $documents = $apply(Document::with(['category', 'uploader', 'assignee']), true)
            ->get()
            ->map(fn ($d) => $this->formatDocument($d, includeSubmitter: true));

        $merged = $requests->concat($documents)
            ->sortBy('submitted_at')
            ->values();

        return response()->json($merged);
    }

    /**
     * GET /api/osm-admin/review-config
     * The routing strategy + completeness checklists (PF-09) the
     * frontend renders in the review screen.
     */
    public function reviewConfig()
    {
        return response()->json([
            'routing_strategy' => config('review.routing.strategy'),
            'checklists' => config('review.checklists'),
            'reviewers' => User::query()
                ->where('role', User::ROLE_OSM_ADMIN)
                ->where('is_active', true)
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

        $data = $request->validate([
            'assignee_id' => ['present', 'nullable', 'integer', Rule::exists('users', 'id')->where(
                fn ($q) => $q->where('role', User::ROLE_OSM_ADMIN)->where('is_active', true),
            )],
        ], [
            'assignee_id.exists' => 'The assignee must be an active OSM admin.',
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
            Notification::create([
                'user_id' => $assigneeId,
                'message' => ucfirst($kind)." {$submittable->tracking_no} was assigned to you for review.",
                'type' => 'review_pending',
                'is_read' => false,
                'created_at' => now(),
            ]);
        }

        $submittable->load($kind === 'document' ? ['category', 'uploader', 'assignee', 'review'] : ['requestType', 'requester', 'assignee', 'review']);

        return response()->json($kind === 'document'
            ? $this->formatDocument($submittable, includeSubmitter: true)
            : $this->formatRequest($submittable, includeSubmitter: true));
    }

    /**
     * Route a new or resubmitted submission into the review queue
     * (PF-08, config/review.php). Idempotent: an item that already has
     * an active OSM-admin assignee keeps them (a revision stays with the
     * reviewer who knows its history).
     */
    private function routeForReview(Model $submittable, ?int $submitterOfficeId): void
    {
        if ($submittable->assigned_to !== null) {
            $stillValid = User::where('id', $submittable->assigned_to)
                ->where('role', User::ROLE_OSM_ADMIN)
                ->where('is_active', true)
                ->exists();

            if ($stillValid) {
                return;
            }
        }

        if (config('review.routing.strategy') === 'round_robin') {
            $candidates = User::query()
                ->where('role', User::ROLE_OSM_ADMIN)
                ->where('is_active', true)
                ->pluck('id');

            $assigneeId = $candidates
                ->sortBy(fn (int $id) => Document::where('assigned_to', $id)->where('status', 'pending')->count()
                    + SubmissionRequest::where('assigned_to', $id)->where('status', 'pending')->count())
                ->first();

            $submittable->update([
                'assigned_to' => $assigneeId,
                'assigned_at' => $assigneeId ? now() : null,
            ]);

            return;
        }

        // office_queue — leave unassigned; the "unassigned" view scopes
        // it to the submitter's office.
        $submittable->update(['assigned_to' => null, 'assigned_at' => null]);
    }

    /**
     * GET /api/osm-admin/stats
     * Live monitoring counts for the OSM dashboard (FR-13 / PF-14) —
     * computed from the database, not accumulated in the browser.
     */
    public function stats()
    {
        $docByStatus = Document::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $reqByStatus = SubmissionRequest::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $status = fn ($bag, string $k) => (int) ($bag[$k] ?? 0);

        $documents = [
            'total' => (int) $docByStatus->sum(),
            'pending' => $status($docByStatus, 'pending'),
            'revision' => $status($docByStatus, 'revision'),
            'approved' => $status($docByStatus, 'approved'),
            'rejected' => $status($docByStatus, 'rejected'),
            'archived' => Document::whereIn('retention_status', ['archived', 'superseded'])->count(),
            'submitted_last_7_days' => Document::where('submitted_at', '>=', now()->subDays(7))->count(),
            // Advisory lead-time breach count (Phase 7.1 / decision 0.9).
            'overdue' => Document::whereIn('status', ['pending', 'revision'])
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

        return response()->json([
            'documents' => $documents,
            'requests' => $requests,
            'awaiting_review' => $documents['pending'] + $requests['pending'],
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

        $submission = $this->createWithTrackingNo(
            fn () => $this->generateRequestTrackingNo($data['request_type_id']),
            fn (string $trackingNo) => SubmissionRequest::create([
                'tracking_no' => $trackingNo,
                'request_type_id' => $data['request_type_id'],
                'requested_by' => $request->user()->id,
                'status' => 'pending',
                'submitted_at' => now(),
                ...$this->requestMetadata($data),
            ])
        );

        $this->routeForReview($submission, $request->user()->office_id);

        $this->notifySubmissionReceived($request->user()->id, $submission->tracking_no);
        $this->notifyReviewers($request->user()->id, $submission->tracking_no, 'request', assigneeId: $submission->assigned_to);

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
    public function storeDocument(Request $request)
    {
        $data = $request->validate(
            $this->documentMetadataRules(fileRequired: true),
            $this->documentMetadataMessages(),
        );

        $file = $request->file('file');
        $user = $request->user();

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

        $document = $this->createWithTrackingNo(
            fn () => $this->generateDocumentTrackingNo($data['category_id'], $user->office_id),
            fn (string $trackingNo) => Document::create([
                'tracking_no' => $trackingNo,
                'uploaded_by' => $user->id,
                'office_id' => $user->office_id,
                'file_path' => $path,
                'file_format' => $this->fileFormat($file),
                'file_size' => $file->getSize(),
                'content_hash' => $hash,
                'status' => 'pending',
                'submitted_at' => now(),
                ...$this->documentMetadata($data),
            ])
        );

        $this->routeForReview($document, $user->office_id);
        DocumentStageEvent::record($document, DocumentStageEvent::STAGE_UPLOADED, $user->id);

        $this->notifySubmissionReceived($user->id, $document->tracking_no);
        $this->notifyReviewers($user->id, $document->tracking_no, 'document', assigneeId: $document->assigned_to);

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

        $submission->update([
            'status' => 'pending',
            'submitted_at' => now(),
            ...$this->requestMetadata($data, onlyPresent: true),
        ]);

        $this->routeForReview($submission, $request->user()->office_id);

        $this->notifySubmissionReceived($request->user()->id, $submission->tracking_no, isResubmission: true);
        $this->notifyReviewers($request->user()->id, $submission->tracking_no, 'request', isResubmission: true, assigneeId: $submission->assigned_to);

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

        $this->routeForReview($document, $request->user()->office_id);
        DocumentStageEvent::record($document, DocumentStageEvent::STAGE_RESUBMITTED, $request->user()->id);

        $this->notifySubmissionReceived($request->user()->id, $document->tracking_no, isResubmission: true);
        $this->notifyReviewers($request->user()->id, $document->tracking_no, 'document', isResubmission: true, assigneeId: $document->assigned_to);

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

        Notification::create([
            'user_id' => $userId,
            'message' => "Your {$noun} {$ref} was received and is pending review.",
            'type' => 'submission_confirmation',
            'is_read' => false,
            'created_at' => now(),
        ]);
    }

    /**
     * Tell the OSM side that something has entered the queue
     * (audit E-14 / D-12 — reviewers were previously never notified).
     * When the item was routed to a specific assignee (Phase 4.3), only
     * they are notified; otherwise the whole active OSM pool is, minus
     * the submitter if they are themselves a reviewer.
     */
    private function notifyReviewers(int $submitterId, string $ref, string $kind, bool $isResubmission = false, ?int $assigneeId = null): void
    {
        $verb = $isResubmission ? 'resubmitted and is back in the queue' : 'is awaiting review';
        $now = now();

        if ($assigneeId !== null && $assigneeId !== $submitterId) {
            Notification::create([
                'user_id' => $assigneeId,
                'message' => ucfirst($kind)." {$ref} {$verb} (assigned to you).",
                'type' => 'review_pending',
                'is_read' => false,
                'created_at' => $now,
            ]);

            return;
        }

        $rows = User::query()
            ->where('role', User::ROLE_OSM_ADMIN)
            ->where('is_active', true)
            ->where('id', '!=', $submitterId)
            ->when($assigneeId !== null, fn ($q) => $q->where('id', '!=', $assigneeId))
            ->pluck('id')
            ->map(fn (int $uid) => [
                'user_id' => $uid,
                'message' => ucfirst($kind)." {$ref} {$verb}.",
                'type' => 'review_pending',
                'is_read' => false,
                'created_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            Notification::insert($rows);
        }
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

    private function formatRequest(SubmissionRequest $r, bool $includeSubmitter = false): array
    {
        return [
            'id' => $r->id,
            'kind' => 'request',
            'ref' => $r->tracking_no,
            'type' => $r->requestType?->type_name,
            'request_type_id' => $r->request_type_id,
            'title' => $r->title,
            'description' => $r->description,
            'needed_by' => $r->needed_by?->toDateString(),
            'amount' => $r->amount,
            'access_level' => $r->access_level,
            'uploader_remarks' => $r->remarks,
            'submitted_at' => $r->submitted_at,
            'status' => $r->status,
            'assigned_to' => $r->assigned_to,
            'assignee' => $r->assignee?->full_name,
            'remarks' => $r->review?->remarks,
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
            'submitted_at' => $d->submitted_at,
            'status' => $d->status,
            'assigned_to' => $d->assigned_to,
            'assignee' => $d->assignee?->full_name,
            // Advisory lead-time signal (Phase 7.1 / decision 0.9).
            'days_in_stage' => Target::daysInStage($d),
            'target_days' => Target::reviewDays($d),
            'overdue' => Target::isOverdue($d),
            'remarks' => $d->review?->remarks,
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
