<?php

namespace App\Reports;

use App\LeadTime\Target;
use App\Models\Document;
use App\Reports\Concerns\FiltersDocuments;
use Illuminate\Support\Collection;

/**
 * RPT-08 — documents still in the review pipeline, aged against the
 * advisory lead-time targets (decision 0.9 / config/lead_times.php).
 * Advisory only: nothing here blocks a workflow.
 */
class DocumentAgingReport extends Report
{
    use FiltersDocuments;

    public function key(): string
    {
        return 'document-aging';
    }

    public function label(): string
    {
        return 'Document Aging';
    }

    public function description(): string
    {
        return 'Pending and in-revision documents, time in the current stage vs the suggested lead time, most overdue first.';
    }

    public function acceptedFilters(): array
    {
        return ['category_id', 'office_id'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'ref', 'label' => 'Reference'],
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'office', 'label' => 'Source office'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'complexity', 'label' => 'Complexity'],
            ['key' => 'days_in_stage', 'label' => 'Days in stage'],
            ['key' => 'target_days', 'label' => 'Target (working days)'],
            ['key' => 'days_overdue', 'label' => 'Days over target'],
            ['key' => 'overdue', 'label' => 'Overdue?'],
        ];
    }

    public function rows(array $filters): Collection
    {
        return $this->applyDocumentFilters(Document::query(), $filters)
            ->whereIn('status', ['pending', 'revision'])
            ->with(['office', 'review'])
            ->get()
            ->map(fn (Document $d) => [
                'ref' => $d->tracking_no,
                'title' => $d->title,
                'office' => $d->office?->office_name,
                'status' => $d->status,
                'complexity' => Target::complexity($d),
                'days_in_stage' => Target::daysInStage($d),
                'target_days' => Target::reviewDays($d),
                'days_overdue' => max(0, Target::daysOverdue($d)),
                'overdue' => Target::isOverdue($d) ? 'yes' : 'no',
            ])
            ->sortByDesc('days_overdue')
            ->values();
    }

    public function summary(array $filters): array
    {
        $rows = $this->rows($filters);

        return [
            'in_pipeline' => $rows->count(),
            'overdue' => $rows->where('overdue', 'yes')->count(),
            'worst_days_over' => (int) ($rows->max('days_overdue') ?? 0),
        ];
    }
}
