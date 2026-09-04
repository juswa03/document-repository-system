<?php

namespace App\Reports;

use App\Models\Document;
use App\Reports\Concerns\FiltersDocuments;
use Illuminate\Support\Collection;

/** RPT-03 — documents still awaiting review or revision. */
class PendingDocumentsReport extends Report
{
    use FiltersDocuments;

    public function key(): string
    {
        return 'pending-documents';
    }

    public function label(): string
    {
        return 'Pending Documents';
    }

    public function description(): string
    {
        return 'Documents awaiting an OSM decision or a resubmission, oldest first.';
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
            ['key' => 'uploader', 'label' => 'Uploaded by'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'submitted_at', 'label' => 'Submitted'],
            ['key' => 'days_waiting', 'label' => 'Days waiting'],
        ];
    }

    public function rows(array $filters): Collection
    {
        return $this->applyDocumentFilters(Document::query(), $filters)
            ->whereIn('status', ['pending', 'revision'])
            ->with(['office', 'uploader'])
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (Document $d) => [
                'ref' => $d->tracking_no,
                'title' => $d->title,
                'office' => $d->office?->office_name,
                'uploader' => $d->uploader?->full_name,
                'status' => $d->status,
                'submitted_at' => $d->submitted_at?->toDateTimeString(),
                'days_waiting' => $d->submitted_at ? (int) $d->submitted_at->diffInDays(now()) : null,
            ]);
    }

    public function summary(array $filters): array
    {
        $base = $this->applyDocumentFilters(Document::query(), $filters)->whereIn('status', ['pending', 'revision']);

        return [
            'total' => (clone $base)->count(),
            'awaiting_review' => (clone $base)->where('status', 'pending')->count(),
            'awaiting_revision' => (clone $base)->where('status', 'revision')->count(),
        ];
    }
}
