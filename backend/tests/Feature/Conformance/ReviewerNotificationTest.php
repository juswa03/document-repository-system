<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use App\Models\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * E-14 / D-12 — the OSM review pool is told when something enters the
 * queue. Previously only the submitter was ever notified (Phase 4.4).
 */
class ReviewerNotificationTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function uploadAsUser(): int
    {
        return $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');
    }

    public function test_reviewers_are_notified_when_a_document_enters_the_queue(): void
    {
        $ref = $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('ref');

        // office_queue routing (the default) leaves the item unassigned,
        // so the whole OSM pool gets the lighter "review_queue" nudge
        // (in-app only); "review_pending" is reserved for a direct handoff.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->userId('office.admin@example.test'),
            'type' => 'review_queue',
            'link' => '/office-admin',
        ]);
        $this->assertStringContainsString($ref, Notification::where('type', 'review_queue')->value('message'));

        // The submitter only gets their own confirmation.
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->userId('user@example.test'),
            'type' => 'review_queue',
        ]);
    }

    public function test_reviewers_are_notified_again_on_resubmission(): void
    {
        $id = $this->uploadAsUser();
        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'revision', 'remarks' => 'redo the period',
        ])->assertCreated();

        Notification::where('type', 'review_queue')->delete();

        $this->asUser()->postJson("/api/dashboard/documents/{$id}/resubmit", [
            'file' => UploadedFile::fake()->create('v2.pdf', 8, 'application/pdf'),
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->userId('office.admin@example.test'),
            'type' => 'review_queue',
        ]);
    }

    public function test_a_reviewer_who_submits_does_not_notify_themselves(): void
    {
        // office_admin cannot use the dashboard endpoint (user-only), so
        // create the document directly as the office admin and confirm the
        // self-notification guard in notifyReviewers still holds.
        $this->createDocument('office.admin@example.test', ['title' => 'Office admin own upload']);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->userId('office.admin@example.test'),
            'type' => 'review_queue',
        ]);
    }
}
