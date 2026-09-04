<?php

namespace App\Reports;

use App\Models\AuditLog;
use Illuminate\Support\Collection;

/** RPT-11 — the full transaction history: upload, review, approval, revision, retrieval, archival. */
class AuditTrailReport extends Report
{
    public function key(): string
    {
        return 'audit-trail';
    }

    public function label(): string
    {
        return 'Audit Trail';
    }

    public function description(): string
    {
        return 'The complete audit log — every recorded action, actor, and source address.';
    }

    public function acceptedFilters(): array
    {
        return ['date_from', 'date_to', 'action', 'actor_id'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'at', 'label' => 'When'],
            ['key' => 'actor', 'label' => 'Actor'],
            ['key' => 'action', 'label' => 'Action'],
            ['key' => 'description', 'label' => 'Description'],
            ['key' => 'subject', 'label' => 'Subject'],
            ['key' => 'ip', 'label' => 'IP address'],
        ];
    }

    public function rows(array $filters): Collection
    {
        return AuditLog::query()
            ->with('actor')
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($filters['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get()
            ->map(fn (AuditLog $l) => [
                'at' => $l->created_at?->toDateTimeString(),
                'actor' => $l->actor?->full_name ?? 'System',
                'action' => $l->action,
                'description' => $l->description,
                'subject' => $l->subject_type ? class_basename($l->subject_type)." #{$l->subject_id}" : null,
                'ip' => $l->ip_address,
            ]);
    }

    public function summary(array $filters): array
    {
        return [
            'total_events' => (int) AuditLog::query()
                ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
                ->count(),
        ];
    }
}
