<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * An office admin can upload a document straight into the repository from
 * /repository, not just a regular user from their own dashboard. The
 * upload is labeled to the office admin's own office by default — the
 * same office_id/target_office_id defaulting every uploader gets — and
 * still goes through the normal review queue rather than being
 * auto-approved: the existing self-review check keeps the uploader from
 * deciding on their own submission.
 */
class OfficeAdminDocumentUploadTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    #[Test]
    public function an_office_admin_can_upload_and_it_is_labeled_to_their_own_office(): void
    {
        $office = Office::create(['office_name' => 'Office Alpha', 'office_code' => 'ALPHA']);
        $admin = User::factory()->create(['role' => User::ROLE_OFFICE_ADMIN, 'office_id' => $office->id]);

        // No target_office_id sent — it must default to the uploader's own
        // office, exactly like a regular user's upload does.
        $id = $this->actingAsEmail($admin->email)
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');

        $document = Document::find($id);
        $this->assertSame($office->id, $document->office_id);
        $this->assertSame($office->id, $document->target_office_id);
        $this->assertSame('pending', $document->status, 'An office admin\'s upload still goes through review, it is not auto-approved.');
    }

    #[Test]
    public function an_office_admin_cannot_approve_their_own_upload(): void
    {
        $office = Office::create(['office_name' => 'Office Alpha', 'office_code' => 'ALPHA']);
        $admin = User::factory()->create(['role' => User::ROLE_OFFICE_ADMIN, 'office_id' => $office->id]);

        $id = $this->actingAsEmail($admin->email)
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');

        $this->actingAsEmail($admin->email)->postJson('/api/office-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertStatus(422);

        $this->assertSame('pending', Document::find($id)->status);
    }

    #[Test]
    public function another_office_admin_in_the_same_office_can_review_it(): void
    {
        $office = Office::create(['office_name' => 'Office Alpha', 'office_code' => 'ALPHA']);
        $uploaderAdmin = User::factory()->create(['role' => User::ROLE_OFFICE_ADMIN, 'office_id' => $office->id]);
        $reviewerAdmin = User::factory()->create(['role' => User::ROLE_OFFICE_ADMIN, 'office_id' => $office->id]);

        $id = $this->actingAsEmail($uploaderAdmin->email)
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');

        $this->actingAsEmail($reviewerAdmin->email)->postJson('/api/office-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $this->assertSame('approved', Document::find($id)->status);
    }

    #[Test]
    public function a_system_admin_still_cannot_upload_a_document(): void
    {
        $this->asSystemAdmin()
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertForbidden();
    }
}
