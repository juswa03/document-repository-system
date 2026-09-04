<?php

namespace App\Reports;

use App\Models\DocumentVersion;
use Illuminate\Support\Collection;

/** RPT-05 — updates, revisions, superseded files and current versions. */
class VersionHistoryReport extends Report
{
    public function key(): string
    {
        return 'version-history';
    }

    public function label(): string
    {
        return 'Document Version History';
    }

    public function description(): string
    {
        return 'Every superseded version, the note that triggered it, and who superseded it.';
    }

    public function acceptedFilters(): array
    {
        return ['date_from', 'date_to', 'category_id'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'ref', 'label' => 'Document'],
            ['key' => 'version_number', 'label' => 'Version'],
            ['key' => 'title', 'label' => 'Title at this version'],
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'status', 'label' => 'Status when superseded'],
            ['key' => 'review_remarks', 'label' => 'Reviewer note'],
            ['key' => 'superseded_by', 'label' => 'Superseded by'],
            ['key' => 'superseded_at', 'label' => 'Superseded'],
        ];
    }

    public function rows(array $filters): Collection
    {
        return DocumentVersion::query()
            ->with(['document', 'category'])
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('superseded_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('superseded_at', '<=', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->orderByDesc('superseded_at')
            ->get()
            ->map(fn (DocumentVersion $v) => [
                'ref' => $v->document?->tracking_no,
                'version_number' => $v->version_number,
                'title' => $v->title,
                'category' => $v->category?->category_name,
                'status' => $v->status,
                'review_remarks' => $v->review_remarks,
                'superseded_by' => \App\Models\User::find($v->superseded_by)?->full_name,
                'superseded_at' => $v->superseded_at?->toDateTimeString(),
            ]);
    }

    public function summary(array $filters): array
    {
        return ['total_superseded_versions' => $this->rows($filters)->count()];
    }
}
