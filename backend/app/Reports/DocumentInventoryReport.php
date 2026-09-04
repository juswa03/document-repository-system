<?php

namespace App\Reports;

use App\Models\Document;
use App\Reports\Concerns\FiltersDocuments;
use Illuminate\Support\Collection;

/** RPT-01 — every stored document by category, office, period and status. */
class DocumentInventoryReport extends Report
{
    use FiltersDocuments;

    public function key(): string
    {
        return 'document-inventory';
    }

    public function label(): string
    {
        return 'Document Inventory';
    }

    public function description(): string
    {
        return 'All stored documents with their category, source office, coverage period and status.';
    }

    public function acceptedFilters(): array
    {
        return ['date_from', 'date_to', 'category_id', 'office_id', 'status'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'ref', 'label' => 'Reference'],
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'document_type', 'label' => 'Type'],
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'office', 'label' => 'Source office'],
            ['key' => 'uploader', 'label' => 'Uploaded by'],
            ['key' => 'document_date', 'label' => 'Document date'],
            ['key' => 'reporting_period', 'label' => 'Coverage period'],
            ['key' => 'access_level', 'label' => 'Access level'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'retention_status', 'label' => 'Retention'],
            ['key' => 'version_number', 'label' => 'Version'],
            ['key' => 'submitted_at', 'label' => 'Submitted'],
        ];
    }

    public function rows(array $filters): Collection
    {
        return $this->applyDocumentFilters(Document::query(), $filters)
            ->with(['category', 'office', 'uploader'])
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn (Document $d) => [
                'ref' => $d->tracking_no,
                'title' => $d->title,
                'document_type' => $d->document_type,
                'category' => $d->category?->category_name,
                'office' => $d->office?->office_name,
                'uploader' => $d->uploader?->full_name,
                'document_date' => $d->document_date?->toDateString(),
                'reporting_period' => $d->reporting_period,
                'access_level' => $d->access_level,
                'status' => $d->status,
                'retention_status' => $d->retention_status,
                'version_number' => $d->version_number,
                'submitted_at' => $d->submitted_at?->toDateTimeString(),
            ]);
    }

    public function summary(array $filters): array
    {
        $base = $this->applyDocumentFilters(Document::query(), $filters);

        return [
            'total' => (clone $base)->count(),
            'by_status' => (clone $base)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ];
    }
}
