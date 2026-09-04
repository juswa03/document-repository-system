<?php

namespace Tests\Feature\Conformance;

use App\Models\AccessGrant;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * FR-06 / BR-04 / BR-08 — access-level enforcement (Phase 4.2).
 * Restricted / confidential documents are reachable only by the
 * uploader, an OSM admin, or the holder of an active access grant.
 */
class AccessControlTest extends ConformanceTestCase
{
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
        $this->other = User::factory()->create([
            'email' => 'other@example.test', 'role' => 'user', 'is_active' => true,
            'password' => 'password', 'office_id' => Office::query()->value('id'),
        ]);
    }

    private function doc(string $level): Document
    {
        return $this->createDocument('user@example.test', ['access_level' => $level, 'title' => "A {$level} file"]);
    }

    public function test_internal_documents_are_open_to_any_authenticated_user(): void
    {
        $doc = $this->doc('internal');
        $this->actingAsEmail('other@example.test')->get("/api/documents/{$doc->id}/file")->assertOk();
    }

    public function test_a_restricted_document_is_denied_without_a_grant(): void
    {
        $doc = $this->doc('restricted');
        $this->actingAsEmail('other@example.test')->get("/api/documents/{$doc->id}/file")->assertForbidden();
    }

    public function test_a_user_grant_opens_a_restricted_document(): void
    {
        $doc = $this->doc('restricted');

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/access-grants", [
            'grantee_user_id' => $this->other->id,
            'reason' => 'Working group member.',
        ])->assertCreated();

        $this->actingAsEmail('other@example.test')->get("/api/documents/{$doc->id}/file")->assertOk();
    }

    public function test_an_office_grant_opens_a_restricted_document(): void
    {
        $doc = $this->doc('restricted');

        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/access-grants", [
            'grantee_office_id' => $this->other->office_id,
            'reason' => 'Whole office needs it.',
        ])->assertCreated();

        $this->actingAsEmail('other@example.test')->get("/api/documents/{$doc->id}/file")->assertOk();
    }

    public function test_a_revoked_or_expired_grant_no_longer_opens_the_document(): void
    {
        $doc = $this->doc('restricted');
        $grant = $doc->accessGrants()->create([
            'grantee_user_id' => $this->other->id, 'granted_by' => $this->userId('osm.admin@example.test'),
            'reason' => 'temp',
        ]);
        $this->actingAsEmail('other@example.test')->get("/api/documents/{$doc->id}/file")->assertOk();

        $this->asOsmAdmin()->deleteJson("/api/osm-admin/access-grants/{$grant->id}")->assertOk();
        $this->actingAsEmail('other@example.test')->get("/api/documents/{$doc->id}/file")->assertForbidden();

        $grant->update(['revoked_at' => null, 'expires_at' => now()->subDay()]);
        $this->actingAsEmail('other@example.test')->get("/api/documents/{$doc->id}/file")->assertForbidden();
    }

    public function test_a_confidential_grant_is_time_boxed_by_default(): void
    {
        $doc = $this->doc('confidential');

        $grant = $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/access-grants", [
            'grantee_user_id' => $this->other->id, 'reason' => 'Audit review.',
        ])->assertCreated()->json();

        $this->assertNotNull($grant['expires_at']);
    }

    public function test_grants_are_rejected_on_public_or_internal_documents(): void
    {
        $doc = $this->doc('internal');
        $this->asOsmAdmin()->postJson("/api/osm-admin/documents/{$doc->id}/access-grants", [
            'grantee_user_id' => $this->other->id, 'reason' => 'n/a',
        ])->assertStatus(422);
    }

    public function test_repository_search_hides_documents_the_caller_cannot_see(): void
    {
        $this->doc('restricted');            // "A restricted file"
        $this->doc('internal');              // "A internal file"

        // osm_admin sees everything
        $adminTitles = array_column(
            $this->asOsmAdmin()->getJson('/api/repository/documents')->json('data'), 'title'
        );
        $this->assertContains('A restricted file', $adminTitles);

        // system_admin is not need-to-know for restricted content
        $sysTitles = array_column(
            $this->asSystemAdmin()->getJson('/api/repository/documents')->json('data'), 'title'
        );
        $this->assertNotContains('A restricted file', $sysTitles);
        $this->assertContains('A internal file', $sysTitles);
    }

    public function test_the_reviewer_can_set_the_access_level_at_approval(): void
    {
        $id = $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload(['access_level' => 'internal']))
            ->assertCreated()->json('id');

        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved', 'access_level' => 'confidential',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $this->assertSame('confidential', Document::find($id)->access_level);
    }
}
