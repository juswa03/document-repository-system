<?php

namespace Tests\Feature\Conformance;

/**
 * Task F-reports — the reports endpoint.
 *
 * GREEN once the project standardises on MySQL (audit E-add-4: the
 * `by_month` aggregate uses DATE_FORMAT, which fails on SQLite). This
 * test guards that decision and the query against regression; the full
 * §G reporting suite is remediation Phase 6.
 */
class ReportingTest extends ConformanceTestCase
{
    public function test_document_report_returns_the_expected_aggregates(): void
    {
        $this->asOsmAdmin()
            ->getJson('/api/reports/documents')
            ->assertOk()
            ->assertJsonStructure(['total', 'by_status', 'by_category', 'by_office', 'by_month']);
    }

    public function test_reports_are_not_reachable_by_an_ordinary_user(): void
    {
        $this->asUser()
            ->getJson('/api/reports/documents')
            ->assertForbidden();
    }
}
