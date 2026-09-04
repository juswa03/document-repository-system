<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;

/**
 * FR-13 / PF-14 — the OSM monitoring dashboard counts come from the
 * database, not a browser-session accumulator (Phase 4.5).
 */
class DashboardStatsTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function upload(): int
    {
        return $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');
    }

    public function test_stats_reflect_persisted_document_status_counts(): void
    {
        $a = $this->upload();
        $b = $this->upload();
        $this->upload(); // stays pending

        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $a, 'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();
        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $b, 'decision' => 'revision', 'remarks' => 'fix it',
        ])->assertCreated();

        $this->asOsmAdmin()->getJson('/api/osm-admin/stats')
            ->assertOk()
            ->assertJsonPath('documents.total', 3)
            ->assertJsonPath('documents.pending', 1)
            ->assertJsonPath('documents.approved', 1)
            ->assertJsonPath('documents.revision', 1)
            ->assertJsonPath('documents.rejected', 0)
            ->assertJsonPath('awaiting_review', 1)
            ->assertJsonPath('documents.submitted_last_7_days', 3);
    }

    public function test_archived_and_superseded_documents_are_counted_separately(): void
    {
        $this->upload();
        $this->createDocument('user@example.test', ['retention_status' => 'superseded']);
        $this->createDocument('user@example.test', ['retention_status' => 'archived']);

        $this->asOsmAdmin()->getJson('/api/osm-admin/stats')
            ->assertOk()
            ->assertJsonPath('documents.archived', 2);
    }

    public function test_stats_are_osm_admin_only(): void
    {
        $this->asUser()->getJson('/api/osm-admin/stats')->assertForbidden();
        $this->asSystemAdmin()->getJson('/api/osm-admin/stats')->assertForbidden();
    }
}
