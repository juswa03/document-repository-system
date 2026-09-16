<?php

namespace App\Reports;

use Illuminate\Support\Collection;

/**
 * A repository report (§G / RPT-01…RPT-11). Each concrete report
 * declares its identity, the filters it accepts, its columns, and how
 * to build the rows. The controller handles JSON vs CSV rendering.
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /**
     * Filter keys this report understands. All are optional at call
     * time. Supported: date_from, date_to, category_id, office_id,
     * status, action, actor_id.
     *
     * @return list<string>
     */
    public function acceptedFilters(): array
    {
        return ['date_from', 'date_to'];
    }

    /**
     * `wrap: true` marks a column as free-text (a description, a
     * reviewer's note) that should wrap onto multiple lines in the
     * on-screen table instead of truncating with an ellipsis like every
     * other column. Omit it (the default, false) for short/label-shaped
     * values — most columns.
     *
     * @return list<array{key: string, label: string, wrap?: bool}>
     */
    abstract public function columns(): array;

    /**
     * One associative array per row, keyed by column key.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    abstract public function rows(array $filters): Collection;

    /**
     * Optional headline figures shown above the table.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        return [];
    }
}
