<?php

namespace App\Reports;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Review;
use Illuminate\Support\Collection;

/** RPT-04 — who viewed, downloaded or retrieved documents. */
class RetrievalLogReport extends Report
{
    public function key(): string
    {
        return 'retrieval-log';
    }

    public function label(): string
    {
        return 'Document Retrieval Log';
    }

    public function description(): string
    {
        return 'Every document retrieval, from the audit trail — who, when, what, from where. '
            .'Covers both document files and reviewer response files.';
    }

    public function acceptedFilters(): array
    {
        return ['date_from', 'date_to', 'actor_id'];
    }

    /** Audit actions that constitute retrieving a document's contents. */
    private const RETRIEVAL_ACTIONS = [
        'document_downloaded' => 'Document file',
        'review_response_downloaded' => 'Reviewer response file',
    ];

    public function columns(): array
    {
        return [
            ['key' => 'at', 'label' => 'When'],
            ['key' => 'actor', 'label' => 'User'],
            ['key' => 'ref', 'label' => 'Document'],
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'what', 'label' => 'Retrieved'],
            ['key' => 'ip', 'label' => 'IP address'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $logs = AuditLog::query()
            ->whereIn('action', array_keys(self::RETRIEVAL_ACTIONS))
            ->with('actor')
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->orderByDesc('created_at')
            ->get();

        // A document download points straight at the document; a response
        // file points at the Review, so resolve that back to its document.
        $documentIds = $logs
            ->where('action', 'document_downloaded')
            ->pluck('subject_id')->filter()->unique();

        $reviewIds = $logs
            ->where('action', 'review_response_downloaded')
            ->pluck('subject_id')->filter()->unique();

        $documentIdByReview = $reviewIds->isEmpty()
            ? collect()
            : Review::whereIn('id', $reviewIds)->pluck('document_id', 'id')->filter();

        $documents = Document::whereIn('id', $documentIds->concat($documentIdByReview->values())->unique())
            ->get(['id', 'title', 'tracking_no'])
            ->keyBy('id');

        return $logs->map(function (AuditLog $l) use ($documents, $documentIdByReview) {
            $documentId = $l->action === 'review_response_downloaded'
                ? ($documentIdByReview[$l->subject_id] ?? null)
                : $l->subject_id;

            $document = $documentId === null ? null : $documents->get($documentId);

            return [
                'at' => $l->created_at?->toDateTimeString(),
                'actor' => $l->actor?->full_name ?? 'System',
                'ref' => $document?->tracking_no,
                'title' => $document?->title,
                'what' => self::RETRIEVAL_ACTIONS[$l->action] ?? $l->action,
                'ip' => $l->ip_address,
            ];
        });
    }

    public function summary(array $filters): array
    {
        $rows = $this->rows($filters);

        return [
            'total_retrievals' => $rows->count(),
            'distinct_users' => $rows->pluck('actor')->unique()->count(),
        ];
    }
}
