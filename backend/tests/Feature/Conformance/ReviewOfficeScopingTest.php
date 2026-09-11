<?php

namespace Tests\Feature\Conformance;

use App\Models\Category;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * A reviewer's role grants them the office-admin route group, but review
 * decisions, assignment, and the response-document picker must still be
 * scoped to their own office — otherwise any office admin can decide on,
 * claim, or leak documents from an office they have nothing to do with.
 * Items with no target_office_id stay visible to every office, matching
 * the existing null-is-unscoped pattern in queue()/decided().
 */
class ReviewOfficeScopingTest extends ConformanceTestCase
{
    private function makeOfficePair(): array
    {
        $officeA = Office::create(['office_name' => 'Office Alpha', 'office_code' => 'ALPHA']);
        $officeB = Office::create(['office_name' => 'Office Beta', 'office_code' => 'BETA']);

        $reviewerA = User::factory()->create(['role' => User::ROLE_OFFICE_ADMIN, 'office_id' => $officeA->id]);
        $reviewerB = User::factory()->create(['role' => User::ROLE_OFFICE_ADMIN, 'office_id' => $officeB->id]);
        $uploaderA = User::factory()->create(['role' => User::ROLE_USER, 'office_id' => $officeA->id]);

        return compact('officeA', 'officeB', 'reviewerA', 'reviewerB', 'uploaderA');
    }

    private function uploadToOffice(User $uploader, ?int $targetOfficeId = null): int
    {
        return $this->actingAsEmail($uploader->email)
            ->postJson('/api/dashboard/documents', $this->documentPayload(
                $targetOfficeId ? ['target_office_id' => $targetOfficeId] : []
            ))
            ->assertCreated()->json('id');
    }

    #[Test]
    public function a_same_office_reviewer_can_decide_on_a_submission(): void
    {
        Storage::fake(Document::DISK);
        ['reviewerA' => $reviewerA, 'uploaderA' => $uploaderA] = $this->makeOfficePair();

        $id = $this->uploadToOffice($uploaderA, $uploaderA->office_id);

        $this->actingAsEmail($reviewerA->email)->postJson('/api/office-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $this->assertSame('approved', Document::find($id)->status);
    }

    #[Test]
    public function a_different_office_reviewer_cannot_decide_on_a_submission(): void
    {
        Storage::fake(Document::DISK);
        ['reviewerB' => $reviewerB, 'uploaderA' => $uploaderA] = $this->makeOfficePair();

        $id = $this->uploadToOffice($uploaderA, $uploaderA->office_id);

        $this->actingAsEmail($reviewerB->email)->postJson('/api/office-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertStatus(403);

        $this->assertSame('pending', Document::find($id)->status);
    }

    #[Test]
    public function a_different_office_reviewer_cannot_assign_a_submission(): void
    {
        Storage::fake(Document::DISK);
        ['reviewerB' => $reviewerB, 'uploaderA' => $uploaderA] = $this->makeOfficePair();

        $id = $this->uploadToOffice($uploaderA, $uploaderA->office_id);

        $this->actingAsEmail($reviewerB->email)->postJson("/api/office-admin/documents/{$id}/assign", [
            'assignee_id' => $reviewerB->id,
        ])->assertStatus(403);

        $this->assertNull(Document::find($id)->assigned_to);
    }

    #[Test]
    public function reassigning_to_a_reviewer_in_a_different_office_is_rejected(): void
    {
        Storage::fake(Document::DISK);
        ['reviewerA' => $reviewerA, 'reviewerB' => $reviewerB, 'uploaderA' => $uploaderA] = $this->makeOfficePair();

        $id = $this->uploadToOffice($uploaderA, $uploaderA->office_id);

        $this->actingAsEmail($reviewerA->email)->postJson("/api/office-admin/documents/{$id}/assign", [
            'assignee_id' => $reviewerB->id,
        ])->assertStatus(422);

        $this->assertNull(Document::find($id)->assigned_to);
    }

    #[Test]
    public function a_different_office_reviewer_gets_nothing_from_response_documents(): void
    {
        Storage::fake(Document::DISK);
        ['officeA' => $officeA, 'reviewerB' => $reviewerB, 'uploaderA' => $uploaderA] = $this->makeOfficePair();

        $pendingId = $this->uploadToOffice($uploaderA, $officeA->id);

        // An approved, public document in office A that would otherwise be offerable.
        $answer = $this->createDocument($uploaderA->email, [
            'status' => 'approved',
            'retention_status' => 'active',
            'access_level' => 'public',
            'office_id' => $officeA->id,
            'target_office_id' => $officeA->id,
        ]);

        $this->actingAsEmail($reviewerB->email)
            ->getJson("/api/office-admin/response-documents?kind=document&id={$pendingId}")
            ->assertStatus(403);

        $this->assertDatabaseHas('documents', ['id' => $answer->id]);
    }

    #[Test]
    public function revision_with_a_policy_violating_access_level_is_rejected_like_approval_is(): void
    {
        Storage::fake(Document::DISK);
        ['reviewerA' => $reviewerA, 'uploaderA' => $uploaderA] = $this->makeOfficePair();

        // PERF (Performance Monitoring) is the one category whose policy
        // excludes "public" entirely (config/documents.php) — categoryIdPermitting()
        // isn't precise enough here since most categories that allow
        // "confidential" also allow "public" via the open '*' fallback.
        $restrictedCategoryId = Category::where('category_code', 'PERF')->value('id');

        $id = $this->actingAsEmail($uploaderA->email)
            ->postJson('/api/dashboard/documents', $this->documentPayload([
                'category_id' => $restrictedCategoryId,
                'access_level' => 'confidential',
                'target_office_id' => $uploaderA->office_id,
            ]))
            ->assertCreated()->json('id');

        $this->actingAsEmail($reviewerA->email)->postJson('/api/office-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'revision',
            'remarks' => 'needs fixes', 'access_level' => 'public',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['access_level']]);

        $this->assertSame('confidential', Document::find($id)->access_level);
        $this->assertSame('pending', Document::find($id)->status);
    }

    #[Test]
    public function an_unscoped_submission_remains_reviewable_by_any_office(): void
    {
        Storage::fake(Document::DISK);
        ['reviewerB' => $reviewerB, 'uploaderA' => $uploaderA] = $this->makeOfficePair();

        // Created normally, then stripped of its office to simulate a
        // legacy/unscoped row — documentPayload() drops null overrides
        // before the request is sent, so the only way to get a genuinely
        // null target_office_id is to null it out after the fact.
        $id = $this->uploadToOffice($uploaderA, $uploaderA->office_id);
        Document::where('id', $id)->update(['target_office_id' => null]);

        $this->actingAsEmail($reviewerB->email)->postJson('/api/office-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $this->assertSame('approved', Document::find($id)->status);
    }
}
