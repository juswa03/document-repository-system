<?php

namespace App\Reports;

use App\Models\Office;
use App\Models\RequiredDocument;
use Illuminate\Support\Collection;

/**
 * RPT-06 — for every (required document × office it applies to), the
 * approved document(s) that satisfy it, or a gap. Computed against the
 * admin-maintained checklist (Phase 6.2, `required_documents`).
 */
class ComplianceEvidenceReport extends Report
{
    public function key(): string
    {
        return 'compliance-evidence';
    }

    public function label(): string
    {
        return 'Compliance Evidence';
    }

    public function description(): string
    {
        return 'Each required document per office, with the approved submission that evidences it or a "missing" flag.';
    }

    public function acceptedFilters(): array
    {
        return ['office_id'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'requirement', 'label' => 'Required document'],
            ['key' => 'office', 'label' => 'Office'],
            ['key' => 'period', 'label' => 'Period'],
            ['key' => 'cadence', 'label' => 'Cadence'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'evidence_ref', 'label' => 'Evidence (tracking no.)'],
            ['key' => 'evidence_approved_at', 'label' => 'Approved'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $officeNames = Office::pluck('office_name', 'id');
        $onlyOffice = $filters['office_id'] ?? null;

        return RequiredDocument::active()->orderBy('name')->get()
            ->flatMap(function (RequiredDocument $req) use ($officeNames, $onlyOffice) {
                return $req->applicableOfficeIds()
                    ->when($onlyOffice, fn ($ids) => $ids->filter(fn ($id) => (int) $id === (int) $onlyOffice))
                    ->map(function ($officeId) use ($req, $officeNames) {
                        $doc = $req->matchingDocuments((int) $officeId)
                            ->orderByDesc('submitted_at')
                            ->first();

                        return [
                            'requirement' => $req->name,
                            'office' => $officeNames[$officeId] ?? "Office #{$officeId}",
                            'period' => $req->reporting_period_label,
                            'cadence' => $req->cadence,
                            'status' => $doc ? 'evidenced' : 'missing',
                            'evidence_ref' => $doc?->tracking_no,
                            'evidence_approved_at' => optional($doc?->review?->reviewed_at)->toDateString(),
                        ];
                    });
            })
            ->values();
    }

    public function summary(array $filters): array
    {
        $rows = $this->rows($filters);

        return [
            'requirements_checked' => $rows->count(),
            'evidenced' => $rows->where('status', 'evidenced')->count(),
            'missing' => $rows->where('status', 'missing')->count(),
        ];
    }
}
