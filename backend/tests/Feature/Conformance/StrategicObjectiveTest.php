<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use App\Models\StrategicObjective;
use Database\Seeders\StrategicObjectiveSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * DR objective linkage (Phase 11). The mechanism: an admin-managed
 * objective tree and a document-to-objective link. The tree seeded here
 * is placeholder data pending decision 0.8.
 */
class StrategicObjectiveTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
        $this->seed(StrategicObjectiveSeeder::class);
    }

    /* ---- objective tree (system admin) ---- */

    public function test_the_objective_tree_is_listed_with_its_hierarchy(): void
    {
        $body = $this->asSystemAdmin()->getJson('/api/admin/strategic-objectives')->assertOk()->json();

        $this->assertNotEmpty($body['tree']);
        $g3 = collect($body['tree'])->firstWhere('code', 'G3');
        $this->assertNotNull($g3);
        $this->assertContains('G3.3', collect($g3['children'])->pluck('code'));
    }

    public function test_a_system_admin_can_add_an_objective_and_others_cannot(): void
    {
        $this->asUser()->postJson('/api/admin/strategic-objectives', ['code' => 'X1', 'title' => 'x'])->assertForbidden();
        $this->asOsmAdmin()->postJson('/api/admin/strategic-objectives', ['code' => 'X1', 'title' => 'x'])->assertForbidden();

        $this->asSystemAdmin()->postJson('/api/admin/strategic-objectives', [
            'code' => 'G3.4', 'title' => 'Modernise records management', 'parent_id' => StrategicObjective::where('code', 'G3')->value('id'),
        ])->assertCreated();

        $this->assertDatabaseHas('strategic_objectives', ['code' => 'G3.4']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'strategic_objective_created']);
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        $this->asSystemAdmin()->postJson('/api/admin/strategic-objectives', ['code' => 'G1', 'title' => 'dup'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_an_objective_cannot_be_its_own_parent(): void
    {
        $g1 = StrategicObjective::where('code', 'G1')->first();

        $this->asSystemAdmin()->patchJson("/api/admin/strategic-objectives/{$g1->id}", ['parent_id' => $g1->id])
            ->assertStatus(422);
    }

    public function test_deleting_a_goal_detaches_its_children(): void
    {
        $g2 = StrategicObjective::where('code', 'G2')->first();
        $childId = $g2->children()->first()->id;

        $this->asSystemAdmin()->deleteJson("/api/admin/strategic-objectives/{$g2->id}")->assertOk();

        $this->assertDatabaseMissing('strategic_objectives', ['id' => $g2->id]);
        $this->assertDatabaseHas('strategic_objectives', ['id' => $childId, 'parent_id' => null]);
    }

    /* ---- document links (OSM admin) ---- */

    public function test_a_document_can_be_linked_to_objectives_and_it_is_audited(): void
    {
        $doc = $this->createDocument();
        $ids = StrategicObjective::whereIn('code', ['G1.1', 'G3.3'])->pluck('id')->all();

        $this->asOsmAdmin()->putJson("/api/osm-admin/documents/{$doc->id}/objectives", ['objective_ids' => $ids])
            ->assertOk()
            ->assertJsonCount(2);

        $this->assertEqualsCanonicalizing(['G1.1', 'G3.3'], $doc->objectives()->pluck('code')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_objectives_set', 'subject_id' => $doc->id]);

        $this->asOsmAdmin()->putJson("/api/osm-admin/documents/{$doc->id}/objectives", ['objective_ids' => []])
            ->assertOk()->assertJsonCount(0);
    }

    public function test_ordinary_users_cannot_link_objectives(): void
    {
        $doc = $this->createDocument();
        $this->asUser()->putJson("/api/osm-admin/documents/{$doc->id}/objectives", ['objective_ids' => []])->assertForbidden();
    }

    public function test_an_osm_admin_can_read_the_tree_to_pick_from_but_not_edit_it(): void
    {
        $this->asOsmAdmin()->getJson('/api/osm-admin/strategic-objectives')
            ->assertOk()->assertJsonStructure(['tree', 'flat']);

        $this->asOsmAdmin()->postJson('/api/admin/strategic-objectives', ['code' => 'Z9', 'title' => 'x'])
            ->assertForbidden();
    }

    public function test_repository_search_can_filter_by_objective(): void
    {
        $g11 = StrategicObjective::where('code', 'G1.1')->value('id');
        $linked = $this->createDocument();
        $linked->objectives()->attach($g11);
        $unlinked = $this->createDocument();

        $refs = collect($this->asOsmAdmin()->getJson("/api/repository/documents?objective_id={$g11}")->json('data'))
            ->pluck('ref');

        $this->assertContains($linked->tracking_no, $refs);
        $this->assertNotContains($unlinked->tracking_no, $refs);
    }
}
