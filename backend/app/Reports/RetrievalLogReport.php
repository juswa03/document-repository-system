<?php

namespace App\Reports;

use App\Models\AuditLog;
use App\Models\Document;
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
        return 'Every document download, from the audit trail — who, when, from where.';
    }

    public function acceptedFilters(): array
    {
        return ['date_from', 'date_to', 'actor_id'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'at', 'label' => 'When'],
            ['key' => 'actor', 'label' => 'User'],
            ['key' => 'ref', 'label' => 'Document'],
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'ip', 'label' => 'IP address'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $logs = AuditLog::query()
            ->where('action', 'document_downloaded')
            ->with('actor')
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->orderByDesc('created_at')
            ->get();

        $titles = Document::whereIn('id', $logs->pluck('subject_id')->filter()->unique())
            ->pluck('title', 'id');
        $refs = Document::whereIn('id', $logs->pluck('subject_id')->filter()->unique())
            ->pluck('tracking_no', 'id');

        return $logs->map(fn (AuditLog $l) => [
            'at' => $l->created_at?->toDateTimeString(),
            'actor' => $l->actor?->full_name ?? 'System',
            'ref' => $refs[$l->subject_id] ?? null,
            'title' => $titles[$l->subject_id] ?? null,
            'ip' => $l->ip_address,
        ]);
    }

    public function summary(array $filters): array
    {
        return ['total_downloads' => $this->rows($filters)->count()];
    }
}
