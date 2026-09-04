<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use App\Models\RequiredDocument;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 6.2 — RPT-06 Compliance Evidence, RPT-07 Office Submission
 * Compliance, RPT-08 Document Aging.
 */
class ComplianceReportsTest extends ConformanceTestCase
{
    private function approvedDocument(array $attrs = []): Document
    {
        Storage::fake(Document::DISK);
        $doc = $this->createDocument('user@example.test', $attrs);
        $doc->update(['status' => 'approved']);
        $doc->review()->create([
            'reviewed_by' => $this->userId('osm.admin@example.test'),
            'decision' => 'approved',
            'reviewed_at' => now(),
        ]);

        return $doc;
    }

    #[Test]
    public function required_documents_crud_is_system_admin_only(): void
    {
        $this->asOsmAdmin()->getJson('/api/admin/required-documents')->assertForbidden();

        $id = $this->asSystemAdmin()->postJson('/api/admin/required-documents', [
            'name' => 'Annual Strategic Plan',
            'cadence' => 'annual',
        ])->assertCreated()->json('id');

        $this->asSystemAdmin()->getJson('/api/admin/required-documents')
            ->assertOk()->assertJsonPath('0.name', 'Annual Strategic Plan');

        $this->assertDatabaseHas('audit_logs', ['action' => 'required_document_created']);

        $this->asSystemAdmin()->deleteJson("/api/admin/required-documents/{$id}")->assertOk();
    }

    #[Test]
    public function compliance_evidence_shows_evidenced_and_missing_rows(): void
    {
        $categoryId = $this->categoryId();
        RequiredDocument::create([
            'name' => 'Board Minutes', 'category_id' => $categoryId,
            'document_type' => 'minutes', 'cadence' => 'quarterly', 'is_active' => true,
        ]);

        // Nothing submitted yet → every applicable office row is "missing".
        $before = $this->asOsmAdmin()->getJson('/api/reports/compliance-evidence')->assertOk()->json();
        $this->assertGreaterThan(0, $before['summary']['missing']);
        $this->assertSame(0, $before['summary']['evidenced']);

        // An approved matching document for the uploader's office closes one.
        $this->approvedDocument(['document_type' => 'minutes', 'category_id' => $categoryId]);

        $after = $this->asOsmAdmin()->getJson('/api/reports/compliance-evidence')->assertOk()->json();
        $this->assertSame(1, $after['summary']['evidenced']);
    }

    #[Test]
    public function office_submission_compliance_scores_each_office(): void
    {
        RequiredDocument::create([
            'name' => 'Annual Plan', 'category_id' => $this->categoryId(),
            'document_type' => 'plan', 'cadence' => 'annual', 'is_active' => true,
        ]);

        $rows = $this->asOsmAdmin()->getJson('/api/reports/office-submission-compliance')
            ->assertOk()->json('rows');

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(1, $row['expected']);
            $this->assertArrayHasKey('pct_compliant', $row);
        }
    }

    #[Test]
    public function document_aging_flags_an_over_target_pending_document(): void
    {
        Storage::fake(Document::DISK);
        $fresh = $this->createDocument('user@example.test');
        $stale = $this->createDocument('user@example.test');
        $stale->update(['submitted_at' => now()->subDays(30)]);

        $report = $this->asOsmAdmin()->getJson('/api/reports/document-aging')->assertOk()->json();

        $rowsByRef = collect($report['rows'])->keyBy('ref');
        $this->assertSame('yes', $rowsByRef[$stale->tracking_no]['overdue']);
        $this->assertSame('no', $rowsByRef[$fresh->tracking_no]['overdue']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['overdue']);
    }
}
