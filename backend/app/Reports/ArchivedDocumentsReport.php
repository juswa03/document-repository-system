<?php

namespace App\Reports;

use App\Models\Document;
use App\Reports\Concerns\FiltersDocuments;
use Illuminate\Support\Collection;

/** RPT-10 — inactive, historical and superseded documents retained for reference. */
class ArchivedDocumentsReport extends Report
{
    use FiltersDocuments;

    public function key(): string
    {
        return 'archived-documents';
    }

    public function label(): string
    {
        return 'Archived Documents';
    }

    public function description(): string
    {
        return 'Documents whose retention status is superseded or archived.';
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
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'office', 'label' => 'Source office'],
            ['key' => 'retention_status', 'label' => 'Retention status'],
            ['key' => 'version_number', 'label' => 'Version'],
            ['key' => 'updated_at', 'label' => 'Last updated'],
        ];
    }

    public function rows(array $filters): Collection
    {
        return $this->applyDocumentFilters(Document::query(), $filters)
            ->whereIn('retention_status', ['superseded', 'archived'])
            ->with(['category', 'office'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Document $d) => [
                'ref' => $d->tracking_no,
                'title' => $d->title,
                'category' => $d->category?->category_name,
                'office' => $d->office?->office_name,
                'retention_status' => $d->retention_status,
                'version_number' => $d->version_number,
                'updated_at' => $d->updated_at?->toDateTimeString(),
            ]);
    }

    public function summary(array $filters): array
    {
        $base = $this->applyDocumentFilters(Document::query(), $filters)
            ->whereIn('retention_status', ['superseded', 'archived']);

        return [
            'total' => (clone $base)->count(),
            'superseded' => (clone $base)->where('retention_status', 'superseded')->count(),
            'archived' => (clone $base)->where('retention_status', 'archived')->count(),
        ];
    }
}
