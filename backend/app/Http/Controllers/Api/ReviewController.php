<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentStageEvent;
use App\Models\Notification;
use App\Models\Review;
use App\Models\SubmissionRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    /**
     * POST /api/osm-admin/reviews
     * Approves or rejects a request/document, records the review,
     * updates the underlying status, and notifies the submitter.
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

        // Completeness checklist (PF-09). An approval requires every
        // `required` item for this kind to be confirmed; return/reject
        // are not gated so a reviewer can always send an incomplete
        // submission back.
        $checklist = $data['checklist'] ?? [];
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
        if ($data['kind'] === 'document' && array_key_exists('access_level', $data)) {
            $statusUpdate['access_level'] = $data['access_level'];
        }
        $submittable->update($statusUpdate);

        $review = Review::create([
            'document_id' => $data['kind'] === 'document' ? $submittable->id : null,
            'request_id' => $data['kind'] === 'request' ? $submittable->id : null,
            'reviewed_by' => $request->user()->id,
            'decision' => $data['decision'],
            'remarks' => $data['remarks'] ?? null,
            'checklist' => $checklist !== [] ? $checklist : null,
            'reviewed_at' => now(),
        ]);

        Notification::create([
            'user_id' => $submitterId,
            'message' => $this->notificationMessage($submittable->tracking_no, $data['decision'], $data['remarks'] ?? null),
            'type' => 'review_decision',
            'is_read' => false,
            'created_at' => now(),
        ]);

        AuditLog::record(
            $request->user()->id,
            "review_{$data['decision']}",
            "Marked {$submittable->tracking_no} as {$data['decision']}.",
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
        ], 201);
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
