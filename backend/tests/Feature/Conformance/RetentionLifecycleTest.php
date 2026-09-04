<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;

/**
 * DR-14 / decision 0.4 — the records-retention lifecycle:
 * active → archived → disposed. Archival is reversible and keeps the
 * document retrievable; disposal is terminal, deletes the file, and
 * leaves a tombstone. Retention periods are placeholders (config/retention.php).
 */
class RetentionLifecycleTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function approvedDocument(array $attributes = []): Document
    {
        $doc = $this->createDocument('user@example.test', $attributes);
        $doc->update(['status' => 'approved']);

        return $doc->fresh();
    }

    /* ---- archive ---- */

    public function test_an_approved_document_can_be_archived(): void
    {
        $doc = $this->approvedDocument();

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/archive")
            ->assertOk()
            ->assertJsonPath('retention_status', 'archived');

        $doc->refresh();
        $this->assertSame('archived', $doc->retention_status);
        $this->assertNotNull($doc->archived_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_archived', 'subject_id' => $doc->id,
        ]);
    }

    public function test_an_archived_document_drops_out_of_the_default_repository_search(): void
    {
        $doc = $this->approvedDocument();
        $doc->archive();

        $refs = fn (array $query = []) => collect(
            $this->asOsmAdmin()->getJson('/api/repository/documents?'.http_build_query($query))
                ->json('data')
        )->pluck('ref');

        $this->assertNotContains($doc->tracking_no, $refs());
        $this->assertContains($doc->tracking_no, $refs(['retention_status' => 'archived']));
    }

    public function test_a_document_that_is_not_approved_cannot_be_archived(): void
    {
        $doc = $this->createDocument('user@example.test');   // still pending

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/archive")
            ->assertStatus(422);
    }

    public function test_archiving_twice_is_rejected(): void
    {
        $doc = $this->approvedDocument();

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/archive")->assertOk();
        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/archive")->assertStatus(422);
    }

    public function test_an_archived_document_can_be_restored(): void
    {
        $doc = $this->approvedDocument();
        $doc->archive();

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/restore")
            ->assertOk()
            ->assertJsonPath('retention_status', 'active');

        $this->assertNull($doc->fresh()->archived_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_restored', 'subject_id' => $doc->id]);
    }

    /* ---- dispose ---- */

    public function test_an_archived_document_can_be_disposed_and_the_file_is_deleted(): void
    {
        $doc = $this->approvedDocument();
        $path = $doc->file_path;
        Storage::disk(Document::DISK)->assertExists($path);
        $doc->archive();

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/dispose", [
            'reason' => 'End of the approved retention schedule for FY 2020 minutes.',
        ])->assertOk()->assertJsonPath('retention_status', 'disposed');

        $doc->refresh();
        $this->assertSame('disposed', $doc->retention_status);
        $this->assertNotNull($doc->disposed_at);
        $this->assertNotNull($doc->disposal_reason);
        Storage::disk(Document::DISK)->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_disposed', 'subject_id' => $doc->id]);
    }

    public function test_disposal_requires_a_reason(): void
    {
        $doc = $this->approvedDocument();
        $doc->archive();

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/dispose", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_only_an_archived_document_can_be_disposed(): void
    {
        $doc = $this->approvedDocument();   // active, not archived

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/dispose", [
            'reason' => 'Trying to skip archival.',
        ])->assertStatus(422);
    }

    public function test_downloading_a_disposed_document_returns_410(): void
    {
        $doc = $this->approvedDocument();
        $doc->archive();
        $doc->dispose('Retention schedule complete.');

        $this->asUser()->get("/api/documents/{$doc->id}/file")->assertStatus(410);
    }

    /* ---- eligibility + command ---- */

    public function test_the_overview_lists_documents_due_for_archival_and_disposal(): void
    {
        config(['retention.periods_months.default' => 1, 'retention.disposal_grace_months' => 24]);

        $due = $this->approvedDocument(['document_date' => now()->subMonths(6)->toDateString()]);
        $notDue = $this->approvedDocument(['document_date' => now()->subDays(3)->toDateString()]);
        $disposeDue = $this->approvedDocument();
        $disposeDue->update(['retention_status' => 'archived', 'archived_at' => now()->subMonths(30)]);

        $body = $this->asOsmAdmin()->getJson('/api/osm-admin/retention')->assertOk()->json();

        $archivalRefs = collect($body['due_for_archival'])->pluck('ref');
        $this->assertContains($due->tracking_no, $archivalRefs);
        $this->assertNotContains($notDue->tracking_no, $archivalRefs);
        $this->assertContains($disposeDue->tracking_no, collect($body['due_for_disposal'])->pluck('ref'));
    }

    public function test_apply_retention_archives_due_documents_only_with_the_flag(): void
    {
        config(['retention.periods_months.default' => 1]);
        $doc = $this->approvedDocument(['document_date' => now()->subMonths(6)->toDateString()]);

        $this->artisan('documents:apply-retention')->assertSuccessful();
        $this->assertSame('active', $doc->fresh()->retention_status);

        $this->artisan('documents:apply-retention --archive --dry-run')->assertSuccessful();
        $this->assertSame('active', $doc->fresh()->retention_status);

        $this->artisan('documents:apply-retention --archive')->assertSuccessful();
        $this->assertSame('archived', $doc->fresh()->retention_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_archived', 'subject_id' => $doc->id]);
    }

    public function test_retention_endpoints_are_osm_admin_only(): void
    {
        $doc = $this->approvedDocument();

        $this->asUser()->getJson('/api/osm-admin/retention')->assertForbidden();
        $this->asUser()->postJson("/api/osm-admin/documents/{$doc->id}/archive")->assertForbidden();
    }
}
