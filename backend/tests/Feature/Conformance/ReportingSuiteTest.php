<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * RPT-01…RPT-05, RPT-09, RPT-10, RPT-11 and PF-16 — the reporting suite
 * with CSV export (Phase 6.1).
 */
class ReportingSuiteTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function upload(array $overrides = []): int
    {
        return $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload($overrides))
            ->assertCreated()->json('id');
    }

    public function test_the_available_reports_are_listed(): void
    {
        $keys = collect($this->asOsmAdmin()->getJson('/api/reports')->assertOk()->json())
            ->pluck('key');

        foreach ([
            'document-inventory', 'submission-monitoring', 'pending-documents',
            'retrieval-log', 'version-history', 'confidential-access',
            'archived-documents', 'audit-trail',
            'compliance-evidence', 'office-submission-compliance', 'document-aging',
        ] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public static function reportKeys(): array
    {
        return array_map(fn ($k) => [$k], [
            'document-inventory', 'submission-monitoring', 'pending-documents',
            'retrieval-log', 'version-history', 'confidential-access',
            'archived-documents', 'audit-trail',
            'compliance-evidence', 'office-submission-compliance', 'document-aging',
        ]);
    }

    #[DataProvider('reportKeys')]
    public function test_every_report_runs_as_json_and_csv(string $key): void
    {
        $this->upload();

        $this->asOsmAdmin()->getJson("/api/reports/{$key}")
            ->assertOk()
            ->assertJsonStructure(['key', 'label', 'generated_at', 'columns', 'summary', 'rows']);

        $csv = $this->asOsmAdmin()->get("/api/reports/{$key}?format=csv");
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));
    }

    public function test_reports_are_not_reachable_by_an_ordinary_user(): void
    {
        $this->asUser()->getJson('/api/reports/document-inventory')->assertForbidden();
        $this->asUser()->getJson('/api/reports')->assertForbidden();
    }

    public function test_an_unknown_report_is_a_404(): void
    {
        $this->asOsmAdmin()->getJson('/api/reports/no-such-report')->assertNotFound();
    }

    public function test_running_a_report_is_audited(): void
    {
        $this->asOsmAdmin()->getJson('/api/reports/document-inventory')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'report_generated']);
    }

    public function test_inventory_lists_uploaded_documents_with_their_metadata(): void
    {
        $this->upload(['title' => 'Accreditation self-study', 'document_type' => 'evidence']);

        $rows = $this->asOsmAdmin()->getJson('/api/reports/document-inventory')->assertOk()->json('rows');
        $row = collect($rows)->firstWhere('title', 'Accreditation self-study');

        $this->assertNotNull($row);
        $this->assertSame('evidence', $row['document_type']);
        $this->assertSame(1, $row['version_number']);
    }

    public function test_retrieval_log_captures_a_download(): void
    {
        $id = $this->upload();
        $this->asUser()->get("/api/documents/{$id}/file")->assertOk();

        $rows = $this->asOsmAdmin()->getJson('/api/reports/retrieval-log')->assertOk()->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('Juana User', $rows[0]['actor']);
    }

    public function test_version_history_report_lists_superseded_versions(): void
    {
        $id = $this->upload();
        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'revision', 'remarks' => 'redo period',
        ])->assertCreated();
        $this->asUser()->postJson("/api/dashboard/documents/{$id}/resubmit", [
            'file' => UploadedFile::fake()->create('v2.pdf', 6, 'application/pdf'),
        ])->assertOk();

        $rows = $this->asOsmAdmin()->getJson('/api/reports/version-history')->assertOk()->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['version_number']);
        $this->assertSame('redo period', $rows[0]['review_remarks']);
    }

    public function test_confidential_access_report_shows_grant_events(): void
    {
        $doc = $this->createDocument('user@example.test', ['access_level' => 'confidential']);
        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/access-grants", [
            'grantee_user_id' => $this->userId('user@example.test'),
            'reason' => 'Audit team',
        ])->assertCreated();

        $rows = $this->asOsmAdmin()->getJson('/api/reports/confidential-access')->assertOk()->json('rows');
        $this->assertContains('access granted', array_column($rows, 'action'));
    }
}
