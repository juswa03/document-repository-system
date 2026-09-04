<?php

namespace App\Reports;

use Illuminate\Support\Collection;

class Registry
{
    /** @var list<class-string<Report>> */
    private const REPORTS = [
        DocumentInventoryReport::class,           // RPT-01
        SubmissionMonitoringReport::class,        // RPT-02
        PendingDocumentsReport::class,            // RPT-03
        RetrievalLogReport::class,                // RPT-04
        VersionHistoryReport::class,             // RPT-05
        ComplianceEvidenceReport::class,          // RPT-06 (Phase 6.2)
        OfficeSubmissionComplianceReport::class,  // RPT-07 (Phase 6.2)
        DocumentAgingReport::class,               // RPT-08 (Phase 6.2, decision 0.9)
        ConfidentialAccessReport::class,          // RPT-09
        ArchivedDocumentsReport::class,           // RPT-10
        AuditTrailReport::class,                  // RPT-11
    ];

    /** @return Collection<string, Report> keyed by report key */
    public function all(): Collection
    {
        return collect(self::REPORTS)
            ->map(fn (string $class) => new $class)
            ->keyBy(fn (Report $r) => $r->key());
    }

    public function find(string $key): ?Report
    {
        return $this->all()->get($key);
    }
}
