<?php

namespace App\Http\Controllers\Api;

use App\Classification\AccessLevelPolicy;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentStageEvent;
use App\Models\Review;
use App\Models\SubmissionRequest;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    /**
     * POST /api/office-admin/reviews
     * Approves or rejects a request/document, records the review,
     * updates the underlying status, and notifies the submitter.
     * On approval the reviewer may optionally attach a response file
     * (e.g. signed letter, issued document) that the submitter can
     * download from their dashboard.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['request', 'document'])],
            'id' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['approved', 'rejected', 'revision'])],
            'remarks' => ['required_unless:decision,approved', 'nullable', 'string', 'max:1000'],
            // The reviewer confirms / adjusts the access level at approval
            // (decision 0.5 / 0.10, BR-08). Documents only.
            'access_level' => ['sometimes', Rule::in(Document::ACCESS_LEVELS)],
            // Reviewer completeness checklist (PF-09, Phase 4.3) —
            // { checklist_key: bool }. Required items must all be true
            // before an APPROVE is accepted; ignored for return/reject.
            'checklist' => ['sometimes', 'array'],
            'checklist.*' => ['boolean'],
            // Optional response file — only accepted on approval decisions.
            'response_file' => [
                'nullable',
                'file',
                'max:'.config('documents.max_upload_kb'),
                'mimes:'.implode(',', config('documents.allowed_mimes')),
            ],
            // ...or an existing repository document handed over instead.
            // Mutually exclusive with response_file: two answers to the
            // same question would leave the submitter guessing which one
            // is authoritative.
            'response_document_id' => [
                'nullable',
                'integer',
                'exists:documents,id',
                'prohibits:response_file',
            ],
        ]);

        $submittable = $data['kind'] === 'request'
            ? SubmissionRequest::findOrFail($data['id'])
            : Document::findOrFail($data['id']);

        $submitterId = $data['kind'] === 'request'
            ? $submittable->requested_by
            : $submittable->uploaded_by;

        if ($submitterId === $request->user()->id) {
            return response()->json([
                'message' => "You can't review your own submission. Ask another OSM admin to decide on this one.",
            ], 422);
        }

        // State machine (decision 0.1): only a submission that is awaiting
        // review can be decided. `approved` and `rejected` are terminal;
        // `revision` must be resubmitted (→ `pending`) before it can be
        // reviewed again.
        if ($submittable->status !== 'pending') {
            return response()->json([
                'message' => "This submission is not awaiting review (current status: {$submittable->status}).",
            ], 422);
        }

        // Office scoping: a reviewer may only decide on items routed to
        // their own office. Unscoped items (target_office_id null) stay
        // visible to every office, matching queue()/decided() elsewhere.
        $reviewerOfficeId = $request->user()->office_id;
        if ($reviewerOfficeId && $submittable->target_office_id && $submittable->target_office_id !== $reviewerOfficeId) {
            return response()->json([
                'message' => 'This submission belongs to a different office and is not yours to review.',
            ], 403);
        }

        // Completeness checklist (PF-09). An approval requires every
        // `required` item for this kind to be confirmed; return/reject
        // are not gated so a reviewer can always send an incomplete
        // submission back.
        // Multipart requests (a response file attached) carry the checklist
        // as FormData string values ("1"/"0"), not JSON booleans — normalize
        // before the strict comparison below.
        $checklist = collect($data['checklist'] ?? [])
            ->map(fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN))
            ->all();
        if ($data['decision'] === 'approved') {
            $missing = collect(config("review.checklists.{$data['kind']}", []))
                ->filter(fn (array $item) => ($item['required'] ?? false) && ($checklist[$item['key']] ?? false) !== true)
                ->pluck('label')
                ->all();

            if ($missing !== []) {
                return response()->json([
                    'message' => 'Confirm every required completeness check before approving.',
                    'errors' => ['checklist' => array_values($missing)],
                ], 422);
            }
        }

        $statusUpdate = ['status' => $data['decision']];

        // "Validate category and access level" sits before "approve" in the
        // process flow, so it gates EVERY approval — not only the ones where
        // the reviewer sends a new level. A document can drift out of
        // compliance without its access_level moving: accepting an AI
        // classification suggestion reclassifies it into a category whose
        // policy forbids the level it already carries (BR-04 / BR-08).
        // Return/reject are deliberately not gated when access_level is left
        // untouched, so the "No" branch stays open — bouncing it back is how
        // a bad pairing gets fixed. But a reviewer actively SETTING a level
        // on any decision (including revision) is still bound by the policy
        // — otherwise a `revision` decision becomes a back door to write a
        // policy-violating level that `approved` would have refused.
        if ($data['kind'] === 'document' && ($data['decision'] === 'approved' || array_key_exists('access_level', $data))) {
            $effectiveLevel = $data['access_level'] ?? $submittable->access_level;
            $policy = app(AccessLevelPolicy::class);

            if (! $policy->permits($submittable->category_id, (string) $effectiveLevel)) {
                $message = $policy->rejectionMessage($submittable->category_id, (string) $effectiveLevel);

                return response()->json([
                    'message' => array_key_exists('access_level', $data)
                        ? $message
                        : $message.' Set an allowed level on this approval, or return it for revision.',
                    'errors' => ['access_level' => [$message]],
                ], 422);
            }
        }

        if ($data['kind'] === 'document' && array_key_exists('access_level', $data)) {
            $statusUpdate['access_level'] = $data['access_level'];
        }

        // A linked repository document is re-checked here rather than
        // trusted from the picker: the same id could be posted directly,
        // and linking a restricted document would hand the submitter a
        // file the access-grant system is meant to gate.
        $responseDocumentId = null;
        if ($data['decision'] === 'approved' && ! empty($data['response_document_id'])) {
            $linked = Document::find($data['response_document_id']);
            $officeId = $submittable->target_office_id ?? $request->user()->office_id;

            $usable = $linked !== null
                && $linked->status === 'approved'
                && $linked->retention_status === 'active'
                && in_array($linked->access_level, ['public', 'internal'], true)
                && ($officeId === null
                    || $linked->target_office_id === $officeId
                    || $linked->office_id === $officeId);

            if (! $usable) {
                $why = 'That document cannot be sent as a response. It must be an approved, '
                    .'active, public or internal document held by this office.';

                return response()->json([
                    'message' => $why,
                    'errors' => ['response_document_id' => [$why]],
                ], 422);
            }

            $responseDocumentId = $linked->id;
        }

        // Everything above this line can refuse the decision. Nothing is
        // written until every check has passed, so a rejected response
        // document never leaves a submission approved-but-unanswered.
        $submittable->update($statusUpdate);

        // Store the optional response file on the same private disk as
        // uploaded documents. Only accepted on approvals — a file on a
        // rejected/revision review wouldn't have a clear meaning.
        $responseFilePath = null;
        $responseFileName = null;
        if ($data['decision'] === 'approved' && $request->hasFile('response_file')) {
            $file = $request->file('response_file');
            $responseFilePath = $file->store('review-responses', Document::DISK);
            $responseFileName = $file->getClientOriginalName();
        }


        $review = Review::create([
            'document_id' => $data['kind'] === 'document' ? $submittable->id : null,
            'request_id' => $data['kind'] === 'request' ? $submittable->id : null,
            'reviewed_by' => $request->user()->id,
            'decision' => $data['decision'],
            'remarks' => $data['remarks'] ?? null,
            'checklist' => $checklist !== [] ? $checklist : null,
            'response_file_path' => $responseFilePath,
            'response_file_name' => $responseFileName,
            'response_document_id' => $responseDocumentId,
            'reviewed_at' => now(),
        ]);

        Notifier::send(
            $submitterId,
            'review_decision',
            $this->notificationMessage($submittable->tracking_no, $data['decision'], $data['remarks'] ?? null),
            '/dashboard',
            config('app.name')." — {$submittable->tracking_no} {$data['decision']}",
        );

        AuditLog::record(
            $request->user()->id,
            "review_{$data['decision']}",
            "Marked {$submittable->tracking_no} as {$data['decision']}"
                .($responseFileName ? " with response file \"{$responseFileName}\"." : '.'),
            $data['kind'] === 'document' ? Document::class : SubmissionRequest::class,
            $submittable->id
        );

        // Lead-time instrumentation (Phase 7.1) — documents only.
        if ($data['kind'] === 'document') {
            if ($checklist !== []) {
                DocumentStageEvent::record($submittable, DocumentStageEvent::STAGE_COMPLETENESS_CHECKED, $request->user()->id);
            }
            DocumentStageEvent::record($submittable, DocumentStageEvent::STAGE_DECIDED, $request->user()->id, $data['decision']);
        }

        return response()->json([
            'review' => $review,
            'status' => $submittable->status,
            'response_file' => ($responseFilePath || $responseDocumentId) ? [
                'review_id' => $review->id,
                'name' => $responseFileName ?? $review->responseDocument?->title,
                'from_repository' => $responseDocumentId !== null,
            ] : null,
        ], 201);
    }

    /**
     * GET /api/reviews/{review}/response-file
     * Download the file the office admin attached to an approval.
     * Only the original submitter may fetch it.
     */
    public function downloadResponseFile(Request $request, Review $review)
    {
        if (! $review->response_file_path && ! $review->response_document_id) {
            abort(404, 'This review has no response file.');
        }

        $user = $request->user();

        $submitterId = $review->document_id
            ? Document::where('id', $review->document_id)->value('uploaded_by')
            : SubmissionRequest::where('id', $review->request_id)->value('requested_by');

        if ($submitterId !== $user->id) {
            abort(403, 'You are not the submitter of this review.');
        }

        $disk = Storage::disk(Document::DISK);

        // A linked repository document is served from its own record, so
        // the submitter always gets the current version rather than a
        // copy frozen at approval time.
        if ($review->response_document_id) {
            $linked = $review->responseDocument;

            if ($linked === null || ! $linked->file_path || ! $disk->exists($linked->file_path)) {
                abort(404, 'The linked document is no longer available.');
            }

            AuditLog::record(
                $user->id,
                'review_response_downloaded',
                "Downloaded response document {$linked->tracking_no} for review #{$review->id}.",
                Review::class,
                $review->id
            );

            $extension = pathinfo($linked->file_path, PATHINFO_EXTENSION);

            return $disk->download(
                $linked->file_path,
                Str::slug($linked->title).($extension ? ".{$extension}" : ''),
            );
        }

        if (! $disk->exists($review->response_file_path)) {
            abort(404, 'Response file not found on disk.');
        }

        AuditLog::record(
            $user->id,
            'review_response_downloaded',
            "Downloaded response file for review #{$review->id} ({$review->response_file_name}).",
            Review::class,
            $review->id
        );

        return $disk->download($review->response_file_path, $review->response_file_name);
    }

    private function notificationMessage(string $ref, string $decision, ?string $remarks): string
    {
        return match ($decision) {
            'approved' => "Your submission {$ref} was approved.",
            'revision' => "Your submission {$ref} needs revision: {$remarks}",
            default => "Your submission {$ref} was rejected: {$remarks}",
        };
    }
}
