<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use App\Models\Notification;
use App\Models\Office;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 4.3 — review routing & assignment (PF-08) and the reviewer
 * completeness checklist (PF-09). The queue is no longer a single
 * unscoped global pull.
 */
class ReviewRoutingTest extends ConformanceTestCase
{
    private function uploadAs(string $email): int
    {
        return $this->actingAsEmail($email)
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');
    }

    #[Test]
    public function office_queue_routing_leaves_a_submission_unassigned_and_scoped_to_its_office(): void
    {
        config(['review.routing.strategy' => 'office_queue']);
        Storage::fake(Document::DISK);

        $officeA = Office::create(['office_name' => 'Office Alpha', 'office_code' => 'ALPHA']);
        $officeB = Office::create(['office_name' => 'Office Beta', 'office_code' => 'BETA']);

        $reviewerA = User::factory()->create(['role' => User::ROLE_OSM_ADMIN, 'office_id' => $officeA->id]);
        $uploaderA = User::factory()->create(['role' => User::ROLE_USER, 'office_id' => $officeA->id]);
        $uploaderB = User::factory()->create(['role' => User::ROLE_USER, 'office_id' => $officeB->id]);

        $idA = $this->uploadAs($uploaderA->email);
        $idB = $this->uploadAs($uploaderB->email);

        $this->assertNull(Document::find($idA)->assigned_to, 'office_queue routing must not pre-assign');

        $unassigned = $this->actingAsEmail($reviewerA->email)
            ->getJson('/api/osm-admin/queue?scope=unassigned')
            ->assertOk()->json();

        $refs = collect($unassigned)->pluck('id');
        $this->assertTrue($refs->contains($idA), 'reviewer sees their own office\'s item');
        $this->assertFalse($refs->contains($idB), 'reviewer does not see another office\'s item');
    }

    #[Test]
    public function a_reviewer_can_claim_an_item_and_see_it_under_scope_mine(): void
    {
        Storage::fake(Document::DISK);
        $id = $this->uploadAs('user@example.test');

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$id}/assign", [
            'assignee_id' => $this->userId('osm.admin@example.test'),
        ])->assertOk()->assertJsonPath('assigned_to', $this->userId('osm.admin@example.test'));

        $mine = $this->asOsmAdmin()->getJson('/api/osm-admin/queue?scope=mine')->assertOk()->json();
        $this->assertSame([$id], collect($mine)->pluck('id')->all());

        $this->assertDatabaseHas('audit_logs', ['action' => 'document_assigned']);
    }

    #[Test]
    public function reassigning_to_another_reviewer_notifies_them(): void
    {
        Storage::fake(Document::DISK);
        $other = User::factory()->create(['role' => User::ROLE_OSM_ADMIN]);
        $id = $this->uploadAs('user@example.test');

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$id}/assign", [
            'assignee_id' => $other->id,
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $other->id,
            'type' => 'review_pending',
        ]);
    }

    #[Test]
    public function assignee_must_be_an_active_osm_admin(): void
    {
        Storage::fake(Document::DISK);
        $id = $this->uploadAs('user@example.test');

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$id}/assign", [
            'assignee_id' => $this->userId('user@example.test'),
        ])->assertStatus(422);
    }

    #[Test]
    public function approval_is_refused_until_every_required_checklist_item_is_confirmed(): void
    {
        Storage::fake(Document::DISK);
        $id = $this->uploadAs('user@example.test');

        // No checklist at all.
        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['checklist']]);

        // A required item still unticked.
        $partial = $this->completeChecklist();
        $partial['metadata_complete'] = false;
        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved', 'checklist' => $partial,
        ])->assertStatus(422);

        $this->assertSame('pending', Document::find($id)->status);

        // Fully confirmed → approved, and the checklist is recorded.
        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $this->assertSame('approved', Document::find($id)->status);
        $this->assertNotNull(Review::where('document_id', $id)->value('checklist'));
    }

    #[Test]
    public function returning_a_submission_does_not_require_the_checklist(): void
    {
        Storage::fake(Document::DISK);
        $id = $this->uploadAs('user@example.test');

        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'revision', 'remarks' => 'fix the period',
        ])->assertCreated();

        $this->assertSame('revision', Document::find($id)->status);
    }

    #[Test]
    public function review_config_lists_the_checklists(): void
    {
        $this->asOsmAdmin()->getJson('/api/osm-admin/review-config')
            ->assertOk()
            ->assertJsonPath('routing_strategy', config('review.routing.strategy'))
            ->assertJsonStructure([
                'checklists' => ['document', 'request'],
                'reviewers' => [['id', 'full_name']],
            ]);
    }

    #[Test]
    public function a_resubmission_keeps_its_existing_assignee(): void
    {
        Storage::fake(Document::DISK);
        $id = $this->uploadAs('user@example.test');
        $osmId = $this->userId('osm.admin@example.test');

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$id}/assign", ['assignee_id' => $osmId])->assertOk();
        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'revision', 'remarks' => 'redo',
        ])->assertCreated();

        $this->actingAsEmail('user@example.test')
            ->postJson("/api/dashboard/documents/{$id}/resubmit", ['reporting_period' => 'Q4 2026'])
            ->assertOk();

        $this->assertSame($osmId, Document::find($id)->assigned_to);
    }
}
