<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Task F-12 — uploaded files must not be reachable without authentication.
 *
 * GREEN after remediation Phase 1.1: files go to the private
 * Document::DISK (never the web-served `public` disk) and are reachable
 * only through the ownership/role-checked download route.
 * Requirement anchors: FR-14, BR-01, D-3.
 */
class FileStorageTest extends ConformanceTestCase
{
    private function upload(): array
    {
        return $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload(['title' => 'Confidential draft']))
            ->assertCreated()->json();
    }

    public function test_uploaded_files_are_not_written_to_the_public_disk(): void
    {
        Storage::fake('public');
        Storage::fake(Document::DISK);

        $this->upload();

        $this->assertCount(
            0,
            Storage::disk('public')->allFiles(),
            'Uploaded documents must not land on the publicly served disk.'
        );
        $this->assertNotEmpty(
            Storage::disk(Document::DISK)->allFiles('documents'),
            'The upload should have landed on the private document disk.'
        );
    }

    public function test_api_responses_do_not_expose_the_raw_file_path(): void
    {
        Storage::fake(Document::DISK);

        $this->assertArrayNotHasKey('file_path', $this->upload());
    }

    public function test_download_route_requires_authentication(): void
    {
        Storage::fake(Document::DISK);
        $doc = $this->createDocument();

        $this->getJson("/api/documents/{$doc->id}/file")->assertUnauthorized();
    }

    public function test_a_non_owner_non_admin_cannot_download(): void
    {
        Storage::fake(Document::DISK);
        $doc = $this->createDocument('user@example.test', ['access_level' => 'restricted']);

        \App\Models\User::factory()->create([
            'email' => 'other@example.test',
            'role' => 'user',
            'is_active' => true,
            'password' => 'password',
            'office_id' => \App\Models\Office::query()->value('id'),
        ]);

        $this->actingAsEmail('other@example.test')
            ->getJson("/api/documents/{$doc->id}/file")
            ->assertForbidden();
    }

    public function test_the_owner_and_an_admin_can_download(): void
    {
        Storage::fake(Document::DISK);
        $doc = $this->createDocument('user@example.test');

        $this->asUser()->get("/api/documents/{$doc->id}/file")->assertOk();
        $this->asOsmAdmin()->get("/api/documents/{$doc->id}/file")->assertOk();
    }
}
