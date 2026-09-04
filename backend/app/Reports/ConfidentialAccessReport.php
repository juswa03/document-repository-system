<?php

namespace App\Reports;

use App\Models\AuditLog;
use App\Models\Document;
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

        return AuditLog::query()
            ->whereIn('action', ['access_granted', 'access_revoked', 'document_downloaded'])
            ->where('subject_type', Document::class)
            ->whereIn('subject_id', $sensitiveIds)
            ->with('actor')
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AuditLog $l) => [
                'at' => $l->created_at?->toDateTimeString(),
                'action' => str_replace('_', ' ', $l->action),
                'actor' => $l->actor?->full_name ?? 'System',
                'ref' => $meta[$l->subject_id]?->tracking_no,
                'access_level' => $meta[$l->subject_id]?->access_level,
                'detail' => $l->description,
            ]);
    }

    public function summary(array $filters): array
    {
        return [
            'sensitive_documents' => Document::whereIn('access_level', ['restricted', 'confidential'])->count(),
            'events' => $this->rows($filters)->count(),
        ];
    }
}
