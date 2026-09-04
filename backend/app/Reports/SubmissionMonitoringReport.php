<?php

namespace App\Reports;

use App\Models\Document;
use App\Models\SubmissionRequest;
use App\Reports\Concerns\FiltersDocuments;
use Illuminate\Support\Collection;

/** RPT-02 — submitted / pending / returned / approved / rejected submissions. */
class SubmissionMonitoringReport extends Report
{
    use FiltersDocuments;

    public function key(): string
    {
        return 'submission-monitoring';
    }

    public function label(): string
    {
        return 'Document Submission Monitoring';
    }

    public function description(): string
    {
        return 'Each submission with its current status, latest decision, and how long it has been in review. Use the "kind" filter to include the non-document request workflow (decision 0.7).';
    }

    public function acceptedFilters(): array
    {
        return ['date_from', 'date_to', 'office_id', 'status', 'kind'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'kind', 'label' => 'Kind'],
            ['key' => 'ref', 'label' => 'Reference'],
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'office', 'label' => 'Source office'],
            ['key' => 'submitted_at', 'label' => 'Submitted'],
            ['key' => 'status', 'label' => 'Current status'],
            ['key' => 'last_decision', 'label' => 'Latest decision'],
            ['key' => 'decided_at', 'label' => 'Decided'],
            ['key' => 'days_in_stage', 'label' => 'Days in current stage'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $kind = $filters['kind'] ?? 'all';
        $rows = collect();

        if ($kind !== 'request') {
            $rows = $rows->concat(
                $this->applyDocumentFilters(Document::query(), $filters)
                    ->with(['office', 'review'])
                    ->orderByDesc('submitted_at')
                    ->get()
                    ->map(fn (Document $d) => $this->row('document', $d, $d->office?->office_name))
            );
        }

        if ($kind !== 'document') {
            $rows = $rows->concat(
                $this->applyRequestFilters(SubmissionRequest::query(), $filters)
                    ->with(['review'])
                    ->orderByDesc('submitted_at')
                    ->get()
                    ->map(fn (SubmissionRequest $r) => $this->row('request', $r, null))
            );
        }

        return $rows->sortByDesc('submitted_at')->values();
    }

    /**
     * Requests don't have category/office columns — only date and status
     * apply.
     */
    private function applyRequestFilters($query, array $filters)
    {
        return $query
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('submitted_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('submitted_at', '<=', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            // an office_id filter simply excludes requests (they have no office)
            ->when($filters['office_id'] ?? null, fn ($q) => $q->whereRaw('1 = 0'));
    }

    private function row(string $kind, $model, ?string $office): array
    {
        $anchor = $model->review?->reviewed_at ?? $model->submitted_at;

        return [
            'kind' => $kind,
            'ref' => $model->tracking_no,
            'title' => $model->title ?? ($kind === 'request' ? $model->requestType?->type_name : null),
            'office' => $office,
            'submitted_at' => $model->submitted_at?->toDateTimeString(),
            'status' => $model->status,
            'last_decision' => $model->review?->decision,
            'decided_at' => $model->review?->reviewed_at?->toDateTimeString(),
            'days_in_stage' => $anchor ? (int) $anchor->diffInDays(now()) : null,
        ];
    }

    public function summary(array $filters): array
    {
        $kind = $filters['kind'] ?? 'all';
        $counts = fn (string $status) => ($kind !== 'request'
                ? (clone $this->applyDocumentFilters(Document::query(), $filters))->where('status', $status)->count()
                : 0)
            + ($kind !== 'document'
                ? (clone $this->applyRequestFilters(SubmissionRequest::query(), $filters))->where('status', $status)->count()
                : 0);

        return [
            'total' => array_sum(array_map($counts, ['pending', 'revision', 'approved', 'rejected'])),
            'pending' => $counts('pending'),
            'revision' => $counts('revision'),
            'approved' => $counts('approved'),
            'rejected' => $counts('rejected'),
        ];
    }
}
