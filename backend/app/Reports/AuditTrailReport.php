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
            ['key' => 'description', 'label' => 'Description', 'wrap' => true],
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

    /**
     * Aggregates, not just a total — these are what the audit-trail
     * assistant (§F) narrates: who was active, what kinds of action
     * dominated, and how documents moved through the workflow.
     */
    public function summary(array $filters): array
    {
        $scoped = fn () => AuditLog::query()
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($filters['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v));

        $byAction = $scoped()
            ->selectRaw('action, COUNT(*) as total')
            ->groupBy('action')
            ->orderByDesc('total')
            ->limit(15)
            ->pluck('total', 'action')
            ->all();

        $byActor = $scoped()
            ->with('actor')
            ->selectRaw('actor_id, COUNT(*) as total')
            ->groupBy('actor_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->mapWithKeys(fn ($r) => [$r->actor?->full_name ?? 'System' => (int) $r->total])
            ->all();

        // Document movement — the workflow transitions specifically,
        // separated from routine reads and logins.
        $movement = collect($byAction)
            ->filter(fn ($_, $action) => (bool) preg_match('/^(document_|review_|request_)/', (string) $action))
            ->all();

        return [
            'total_events' => (int) $scoped()->count(),
            'distinct_actors' => (int) $scoped()->distinct()->count('actor_id'),
            'first_event' => $scoped()->min('created_at'),
            'last_event' => $scoped()->max('created_at'),
            'by_action' => $byAction,
            'most_active_users' => $byActor,
            'document_movement' => $movement,
        ];
    }
}
