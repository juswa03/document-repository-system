<?php

namespace App\Reports\Concerns;

use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;

trait FiltersDocuments
{
    /**
     * Apply the common date / category / office / period / status filters
     * to a documents query.
     *
     * Drafts are excluded unconditionally: a draft has never been
     * submitted and has no tracking number, so it is not part of the
     * record a report describes. Only an explicit status=draft filter
     * can surface them.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyDocumentFilters(Builder $query, array $filters, string $dateColumn = 'submitted_at'): Builder
    {
        return $query
            ->unless(
                ($filters['status'] ?? null) === Document::STATUS_DRAFT,
                fn (Builder $q) => $q->where('status', '!=', Document::STATUS_DRAFT),
            )
            ->when($filters['date_from'] ?? null, fn (Builder $q, $v) => $q->whereDate($dateColumn, '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $v) => $q->whereDate($dateColumn, '<=', $v))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $v) => $q->where('category_id', $v))
            ->when($filters['office_id'] ?? null, fn (Builder $q, $v) => $q->where('office_id', $v))
            // "by ... period" — the coverage period the uploader stated,
            // matched loosely so "2026" finds "AY 2025-2026" and "Q3 2026".
            ->when($filters['reporting_period'] ?? null,
                fn (Builder $q, $v) => $q->where('reporting_period', 'like', '%'.$v.'%'))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v));
    }
}
