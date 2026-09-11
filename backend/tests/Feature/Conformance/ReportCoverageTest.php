<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use App\Reports\Registry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The eleven documented reports (RPT-01…RPT-11), and the three coverage
 * gaps found against their stated purposes:
 *
 *   inventory   — "by category, office, PERIOD and status": period was a
 *                 column but not a filter
 *   retrieval   — "accessed, viewed, downloaded or RETRIEVED": reviewer
 *                 response-file downloads were audited but unreported
 *   all listing — drafts (never submitted, no tracking number) must not
 *                 appear as rows in any report
 */
class ReportCoverageTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    #[Test]
    public function every_documented_report_is_registered(): void
    {
        $expected = [
            'document-inventory',
            'submission-monitoring',
            'pending-documents',
            'retrieval-log',
            'version-history',
            'compliance-evidence',
            'office-submission-compliance',
            'document-aging',
            'confidential-access',
            'archived-documents',
            'audit-trail',
        ];

        $this->assertEqualsCanonicalizing(
            $expected,
            app(Registry::class)->all()->keys()->all(),
        );
    }

    #[Test]
    public function every_registered_report_is_listed_to_an_admin(): void
    {
        $this->asSystemAdmin()->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(11);
    }

    /* ---- Inventory: filter by coverage period ---- */

    #[Test]
    public function the_inventory_can_be_filtered_by_reporting_period(): void
    {
        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'title' => 'Third quarter figures',
            'reporting_period' => 'Q3 2026',
        ]))->assertCreated();

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'title' => 'Academic year review',
            'reporting_period' => 'AY 2025-2026',
        ]))->assertCreated();

        $rows = $this->asSystemAdmin()
            ->getJson('/api/reports/document-inventory?reporting_period=Q3')
            ->assertOk()->json('rows');

        $this->assertCount(1, $rows);
        $this->assertSame('Q3 2026', $rows[0]['reporting_period']);
    }

    #[Test]
    public function a_period_filter_matches_loosely_so_a_year_finds_its_periods(): void
    {
        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'reporting_period' => 'AY 2025-2026',
        ]))->assertCreated();

        $this->asSystemAdmin()
            ->getJson('/api/reports/document-inventory?reporting_period=2026')
            ->assertOk()
            ->assertJsonCount(1, 'rows');
    }

    /* ---- Drafts never appear in a report ---- */

    #[Test]
    public function a_draft_appears_in_no_report(): void
    {
        $this->asUser()->postJson('/api/dashboard/documents/draft', [
            'title' => 'Unsubmitted work in progress',
            'category_id' => $this->categoryId(),
        ])->assertCreated();

        foreach (['document-inventory', 'submission-monitoring', 'pending-documents'] as $report) {
            $rows = $this->asSystemAdmin()->getJson("/api/reports/{$report}")->assertOk()->json('rows');

            $this->assertSame([], $rows, "A draft leaked into the {$report} report.");
        }
    }

    #[Test]
    public function a_report_cannot_be_asked_for_drafts(): void
    {
        // status=draft is not an accepted value — the private
        // work-in-progress state is not reportable at all.
        $this->asSystemAdmin()
            ->getJson('/api/reports/document-inventory?status=draft')
            ->assertStatus(422);
    }

    /* ---- Retrieval log: response files count as retrievals ---- */

    #[Test]
    public function the_retrieval_log_records_a_reviewer_response_file_download(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');

        // Approve with a response file attached.
        $this->asOfficeAdmin()->post('/api/office-admin/reviews', [
            'kind' => 'document',
            'id' => $id,
            'decision' => 'approved',
            'response_file' => UploadedFile::fake()->create('signed.pdf', 6, 'application/pdf'),
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $reviewId = \App\Models\Review::where('document_id', $id)->value('id');

        // The submitter fetches it — a genuine retrieval of document content.
        $this->asUser()->get("/api/reviews/{$reviewId}/response-file")->assertOk();

        $rows = $this->asSystemAdmin()->getJson('/api/reports/retrieval-log')->assertOk()->json('rows');

        $this->assertCount(1, $rows);
        $this->assertSame('Reviewer response file', $rows[0]['what']);
        $this->assertNotNull($rows[0]['ref'], 'The retrieval must resolve back to its document.');
    }

    #[Test]
    public function the_retrieval_log_still_records_ordinary_document_downloads(): void
    {
        $document = $this->createDocument('user@example.test');

        $this->asUser()->get("/api/documents/{$document->id}/file")->assertOk();

        $rows = $this->asSystemAdmin()->getJson('/api/reports/retrieval-log')->assertOk()->json('rows');

        $this->assertCount(1, $rows);
        $this->assertSame('Document file', $rows[0]['what']);
        $this->assertSame($document->tracking_no, $rows[0]['ref']);
    }

    #[Test]
    public function a_response_file_download_for_a_sensitive_document_is_reported(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'category_id' => $this->categoryIdPermitting('confidential'),
            'access_level' => 'confidential',
        ]))->assertCreated()->json('id');

        $this->asOfficeAdmin()->post('/api/office-admin/reviews', [
            'kind' => 'document',
            'id' => $id,
            'decision' => 'approved',
            'access_level' => 'confidential',
            'response_file' => UploadedFile::fake()->create('signed.pdf', 6, 'application/pdf'),
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $reviewId = \App\Models\Review::where('document_id', $id)->value('id');
        $this->asUser()->get("/api/reviews/{$reviewId}/response-file")->assertOk();

        $rows = $this->asSystemAdmin()
            ->getJson('/api/reports/confidential-access')
            ->assertOk()->json('rows');

        $refs = collect($rows)->pluck('ref')->filter()->all();
        $this->assertContains(Document::find($id)->tracking_no, $refs);
    }
}
