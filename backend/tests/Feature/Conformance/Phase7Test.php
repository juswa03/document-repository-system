<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use App\Models\DocumentStageEvent;
use App\Models\GovernanceReview;
use App\Scanning\Contracts\FileScanner;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeFileScanner;

/**
 * Phase 7 — lead-time instrumentation (7.1 / NFR), governance cadence
 * (7.2 / BR-07), and upload malware scanning (7.3 / PF-03).
 */
class Phase7Test extends ConformanceTestCase
{
    private function upload(): int
    {
        return $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');
    }

    // ---- 7.1 lead-time instrumentation ------------------------------

    #[Test]
    public function stage_events_are_written_on_upload_and_decision(): void
    {
        Storage::fake(Document::DISK);
        $id = $this->upload();

        $this->assertDatabaseHas('document_stage_events', [
            'document_id' => $id, 'stage' => DocumentStageEvent::STAGE_UPLOADED,
        ]);

        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $this->assertDatabaseHas('document_stage_events', [
            'document_id' => $id, 'stage' => DocumentStageEvent::STAGE_COMPLETENESS_CHECKED,
        ]);
        $this->assertDatabaseHas('document_stage_events', [
            'document_id' => $id, 'stage' => DocumentStageEvent::STAGE_DECIDED, 'detail' => 'approved',
        ]);
    }

    #[Test]
    public function the_queue_and_stats_expose_an_overdue_signal(): void
    {
        Storage::fake(Document::DISK);
        $stale = $this->createDocument('user@example.test');
        $stale->update(['submitted_at' => now()->subDays(20)]);

        $row = collect($this->asOsmAdmin()->getJson('/api/osm-admin/queue')->assertOk()->json('data'))
            ->firstWhere('id', $stale->id);
        $this->assertTrue($row['overdue']);
        $this->assertSame(config('lead_times.review_days.simple'), $row['target_days']);

        $this->asOsmAdmin()->getJson('/api/osm-admin/stats')
            ->assertOk()
            ->assertJsonPath('documents.overdue', 1);
    }

    #[Test]
    public function escalate_stale_notifies_the_pool_for_an_overdue_document(): void
    {
        Storage::fake(Document::DISK);
        $stale = $this->createDocument('user@example.test');
        $stale->update(['submitted_at' => now()->subDays(20)]);

        $this->artisan('documents:escalate-stale')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['action' => 'document_escalated']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->userId('osm.admin@example.test'),
            'type' => 'review_pending',
        ]);
    }

    // ---- 7.2 governance cadence -----------------------------------

    #[Test]
    public function a_governance_review_can_be_recorded_and_sets_the_next_due_date(): void
    {
        $this->asOsmAdmin()->getJson('/api/admin/governance-reviews')->assertForbidden();

        $before = $this->asSystemAdmin()->getJson('/api/admin/governance-reviews')->assertOk()->json('status');
        $this->assertTrue(collect($before)->firstWhere('scope', 'retention')['overdue']);

        $this->asSystemAdmin()->postJson('/api/admin/governance-reviews', [
            'scope' => 'retention', 'notes' => 'Reviewed retention statuses; no changes.',
        ])->assertCreated();

        $after = $this->asSystemAdmin()->getJson('/api/admin/governance-reviews')->assertOk()->json('status');
        $retention = collect($after)->firstWhere('scope', 'retention');
        $this->assertFalse($retention['overdue']);
        $this->assertNotNull($retention['last_reviewed_at']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'governance_review_recorded']);
    }

    #[Test]
    public function governance_remind_notifies_admins_when_a_scope_is_overdue(): void
    {
        $this->artisan('governance:remind')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->userId('system.admin@example.test'),
            'type' => 'governance_reminder',
        ]);
    }

    #[Test]
    public function recording_a_review_uses_the_configured_cadence(): void
    {
        $review = GovernanceReview::create([
            'reviewed_by' => $this->userId('system.admin@example.test'),
            'scope' => 'retention',
            'performed_at' => now(),
            'next_due_at' => now()->addMonths(GovernanceReview::cadenceMonths('retention'))->toDateString(),
        ]);

        $this->assertSame(6, GovernanceReview::cadenceMonths('retention'));
        $this->assertTrue($review->next_due_at->gt(now()->addMonths(5)));
    }

    // ---- 7.3 upload malware scanning ------------------------------

    #[Test]
    public function an_infected_upload_is_rejected_and_audited(): void
    {
        Storage::fake(Document::DISK);
        $this->app->instance(FileScanner::class, FakeFileScanner::infected());

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'document_scan_blocked']);
        $this->assertDatabaseCount('documents', 0);
    }

    #[Test]
    public function a_clean_upload_passes(): void
    {
        Storage::fake(Document::DISK);
        $this->app->instance(FileScanner::class, FakeFileScanner::clean());

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated();
    }

    #[Test]
    public function an_unavailable_scanner_fails_open_by_default_and_is_audited(): void
    {
        Storage::fake(Document::DISK);
        config(['scanning.fail_open' => true]);
        $this->app->instance(FileScanner::class, FakeFileScanner::unavailable());

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'document_scan_skipped']);
    }

    #[Test]
    public function an_unavailable_scanner_can_be_configured_to_fail_closed(): void
    {
        Storage::fake(Document::DISK);
        config(['scanning.fail_open' => false]);
        $this->app->instance(FileScanner::class, FakeFileScanner::unavailable());

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'document_scan_blocked']);
    }
}
