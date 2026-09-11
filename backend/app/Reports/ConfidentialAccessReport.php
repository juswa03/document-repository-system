<?php

namespace App\Reports;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Review;
use Illuminate\Support\Collection;

/** RPT-09 — access to restricted and confidential documents. */
class ConfidentialAccessReport extends Report
{
    public function key(): string
    {
        return 'confidential-access';
    }

    public function label(): string
    {
        return 'Confidential Document Access';
    }

    public function description(): string
    {
        return 'Grants, revocations and downloads on restricted / confidential documents.';
    }

    public function acceptedFilters(): array
    {
        return ['date_from', 'date_to'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'at', 'label' => 'When'],
            ['key' => 'action', 'label' => 'Action'],
            ['key' => 'actor', 'label' => 'User'],
            ['key' => 'ref', 'label' => 'Document'],
            ['key' => 'access_level', 'label' => 'Access level'],
            ['key' => 'detail', 'label' => 'Detail'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $sensitiveIds = Document::whereIn('access_level', ['restricted', 'confidential'])->pluck('id');
        $meta = Document::whereIn('id', $sensitiveIds)->get(['id', 'tracking_no', 'access_level'])->keyBy('id');

        // A reviewer's response file is attached to a Review, not the
        // document — but retrieving one for a sensitive document is
        // exactly the access this report exists to surface.
        $sensitiveReviewIds = Review::whereIn('document_id', $sensitiveIds)
            ->pluck('document_id', 'id')
            ->filter();

        return AuditLog::query()
            ->where(fn ($q) => $q
                ->where(fn ($qq) => $qq
                    ->whereIn('action', ['access_granted', 'access_revoked', 'document_downloaded'])
                    ->where('subject_type', Document::class)
                    ->whereIn('subject_id', $sensitiveIds))
                ->orWhere(fn ($qq) => $qq
                    ->where('action', 'review_response_downloaded')
                    ->where('subject_type', Review::class)
                    ->whereIn('subject_id', $sensitiveReviewIds->keys())))
            ->with('actor')
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->get()
            ->map(function (AuditLog $l) use ($meta, $sensitiveReviewIds) {
                $documentId = $l->subject_type === Review::class
                    ? ($sensitiveReviewIds[$l->subject_id] ?? null)
                    : $l->subject_id;

                return [
                    'at' => $l->created_at?->toDateTimeString(),
                    'action' => str_replace('_', ' ', $l->action),
                    'actor' => $l->actor?->full_name ?? 'System',
                    'ref' => $documentId ? $meta[$documentId]?->tracking_no : null,
                    'access_level' => $documentId ? $meta[$documentId]?->access_level : null,
                    'detail' => $l->description,
                ];
            });
    }

    public function summary(array $filters): array
    {
        return [
            'sensitive_documents' => Document::whereIn('access_level', ['restricted', 'confidential'])->count(),
            'events' => $this->rows($filters)->count(),
        ];
    }
}
