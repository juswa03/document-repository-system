<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * FR-11 / FR-12 / PF-17 / BR-05 — version history. Each resubmission
 * snapshots the current state as a numbered version; the superseded
 * version stays fully traceable but is never served as current.
 */
class VersioningTest extends ConformanceTestCase
{
    private function fakeDisks(): void
    {
        Storage::fake(Document::DISK);
        Storage::fake('public');
    }

    private function uploadAndReturn(): int
    {
        $id = $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload(['title' => 'Self-study report']))
            ->assertCreated()->json('id');

        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'revision', 'remarks' => 'Fix the coverage period.',
        ])->assertCreated();

        return $id;
    }

    public function test_resubmission_creates_a_numbered_version_and_bumps_the_current_number(): void
    {
        $this->fakeDisks();
        $id = $this->uploadAndReturn();
        $v1 = Document::find($id);

        $this->asUser()->postJson("/api/dashboard/documents/{$id}/resubmit", [
            'title' => 'Self-study report (rev)',
            'reporting_period' => 'AY 2025–2026',
            'file' => UploadedFile::fake()->create('rev.pdf', 12, 'application/pdf'),
        ])->assertOk();

        $current = Document::with('versions')->find($id);
        $this->assertSame(2, $current->version_number);
        $this->assertCount(1, $current->versions);

        $snapshot = $current->versions->first();
        $this->assertSame(1, $snapshot->version_number);
        $this->assertSame($v1->title, $snapshot->title);
        $this->assertSame($v1->file_path, $snapshot->file_path);
        $this->assertSame('Fix the coverage period.', $snapshot->review_remarks);
        $this->assertTrue(Storage::disk(Document::DISK)->exists($snapshot->file_path));
        $this->assertTrue(Storage::disk(Document::DISK)->exists($current->file_path));
    }

    public function test_the_version_history_endpoint_lists_every_version(): void
    {
        $this->fakeDisks();
        $id = $this->uploadAndReturn();

        $this->asUser()->postJson("/api/dashboard/documents/{$id}/resubmit", [
            'reporting_period' => 'AY 2025–2026',
            'file' => UploadedFile::fake()->create('rev.pdf', 12, 'application/pdf'),
        ])->assertOk();

        $this->asUser()->getJson("/api/documents/{$id}/versions")
            ->assertOk()
            ->assertJsonPath('current_version', 2)
            ->assertJsonPath('versions.0.version_number', 1)
            ->assertJsonPath('versions.0.is_current', false)
            ->assertJsonPath('versions.1.version_number', 2)
            ->assertJsonPath('versions.1.is_current', true);
    }

    public function test_version_history_is_access_controlled(): void
    {
        $this->fakeDisks();
        $doc = $this->createDocument('user@example.test', ['access_level' => 'confidential']);

        \App\Models\User::factory()->create([
            'email' => 'other@example.test', 'role' => 'user', 'is_active' => true,
            'password' => 'password', 'office_id' => \App\Models\Office::query()->value('id'),
        ]);

        $this->actingAsEmail('other@example.test')
            ->getJson("/api/documents/{$doc->id}/versions")->assertForbidden();

        $this->asOsmAdmin()->getJson("/api/documents/{$doc->id}/versions")->assertOk();
    }

    public function test_superseded_documents_are_hidden_from_repository_search_by_default(): void
    {
        $this->fakeDisks();
        $doc = $this->createDocument('user@example.test');
        $doc->update(['title' => 'Old policy', 'retention_status' => 'superseded']);
        $this->createDocument('user@example.test')->update(['title' => 'Current policy']);

        $default = $this->asOsmAdmin()->getJson('/api/repository/documents')->assertOk()->json('data');
        $this->assertNotContains('Old policy', array_column($default, 'title'));
        $this->assertContains('Current policy', array_column($default, 'title'));

        $all = $this->asOsmAdmin()->getJson('/api/repository/documents?include_superseded=1')->assertOk()->json('data');
        $this->assertContains('Old policy', array_column($all, 'title'));
    }
}
