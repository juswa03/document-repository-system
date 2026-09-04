<?php

namespace App\Reports;

use App\Models\Office;
use App\Models\RequiredDocument;
use Illuminate\Support\Collection;

/**
 * RPT-07 — per office: how many of its required documents have an
 * approved submission, as a count and a percentage. Computed against the
 * admin-maintained checklist (Phase 6.2).
 */
class OfficeSubmissionComplianceReport extends Report
{
    public function key(): string
    {
        return 'office-submission-compliance';
    }

    public function label(): string
    {
        return 'Office Submission Compliance';
    }

    public function description(): string
    {
        return 'Each office scored against the required-documents checklist: expected, submitted, and percent compliant.';
    }

    public function acceptedFilters(): array
    {
        return ['office_id'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'office', 'label' => 'Office'],
            ['key' => 'expected', 'label' => 'Required'],
            ['key' => 'submitted', 'label' => 'Approved & on file'],
            ['key' => 'missing', 'label' => 'Missing'],
            ['key' => 'pct_compliant', 'label' => '% compliant'],
            ['key' => 'last_submission', 'label' => 'Latest approved submission'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $requirements = RequiredDocument::active()->get();

        return Office::query()
            ->when($filters['office_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('office_name')
            ->get()
            ->map(function (Office $office) use ($requirements) {
                $applicable = $requirements->filter(
                    fn (RequiredDocument $r) => $r->office_id === null || $r->office_id === $office->id
                );

                $latest = null;
                $met = 0;
                foreach ($applicable as $req) {
                    $doc = $req->matchingDocuments($office->id)->orderByDesc('submitted_at')->first();
                    if ($doc) {
                        $met++;
                        $approvedAt = $doc->review?->reviewed_at;
                        if ($approvedAt && (! $latest || $approvedAt->gt($latest))) {
                            $latest = $approvedAt;
                        }
                    }
                }

                $expected = $applicable->count();

                return [
                    'office' => $office->office_name,
                    'expected' => $expected,
                    'submitted' => $met,
                    'missing' => $expected - $met,
                    'pct_compliant' => $expected ? round($met / $expected * 100) : null,
                    'last_submission' => optional($latest)->toDateString(),
                ];
            })
            ->values();
    }

    public function summary(array $filters): array
    {
        $rows = $this->rows($filters);
        $expected = $rows->sum('expected');
        $submitted = $rows->sum('submitted');

        return [
            'offices' => $rows->count(),
            'overall_pct_compliant' => $expected ? round($submitted / $expected * 100) : null,
            'fully_compliant_offices' => $rows->where('missing', 0)->where('expected', '>', 0)->count(),
        ];
    }
}
