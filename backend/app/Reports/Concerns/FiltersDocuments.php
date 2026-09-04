<?php

namespace App\Reports\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait FiltersDocuments
{
    /**
     * Apply the common date / category / office / status filters to a
     * documents query.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyDocumentFilters(Builder $query, array $filters, string $dateColumn = 'submitted_at'): Builder
    {
        return $query
            ->when($filters['date_from'] ?? null, fn (Builder $q, $v) => $q->whereDate($dateColumn, '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $v) => $q->whereDate($dateColumn, '<=', $v))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $v) => $q->where('category_id', $v))
            ->when($filters['office_id'] ?? null, fn (Builder $q, $v) => $q->where('office_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v));
    }
}
