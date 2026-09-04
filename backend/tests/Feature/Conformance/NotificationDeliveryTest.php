<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use App\Models\Notification as NotificationRow;
use App\Notifications\UserAlert;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Real-world notification delivery (Phase 34): every in-app row is still
 * written, actionable types are additionally emailed (after the response,
 * so upload/review latency is untouched), rows carry a deep link, and the
 * bell's read endpoints are scoped to the caller.
 */
class NotificationDeliveryTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function approveAnUpload(): void
    {
        $id = $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');

        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document',
            'id' => $id,
            'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();
    }

    public function test_a_decision_writes_an_in_app_row_with_a_deep_link(): void
    {
        Notification::fake();
        $this->approveAnUpload();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->userId('user@example.test'),
            'type' => 'review_decision',
            'link' => '/dashboard',
            'is_read' => false,
        ]);
    }

    public function test_an_actionable_type_is_also_emailed(): void
    {
        Notification::fake();
        $this->approveAnUpload();

        Notification::assertSentTo(
            $this->user('user@example.test'),
            UserAlert::class,
        );
    }

    public function test_no_mail_goes_out_when_email_delivery_is_disabled(): void
    {
        config(['notifications.email_enabled' => false]);
        Notification::fake();

        $this->approveAnUpload();

        Notification::assertNothingSent();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->userId('user@example.test'),
            'type' => 'review_decision',
        ]);
    }

    public function test_the_review_queue_broadcast_stays_in_app_only(): void
    {
        config(['notifications.email_enabled' => true]);
        Notification::fake();

        $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated();

        // The pool nudge is written but never mailed.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->userId('osm.admin@example.test'),
            'type' => 'review_queue',
        ]);
        Notification::assertNotSentTo($this->user('osm.admin@example.test'), UserAlert::class);
    }

    public function test_index_returns_recent_rows_and_the_unread_count(): void
    {
        $uid = $this->userId('user@example.test');
        NotificationRow::insert([
            ['user_id' => $uid, 'message' => 'one', 'type' => 'system', 'link' => '/dashboard', 'is_read' => false, 'created_at' => now()],
            ['user_id' => $uid, 'message' => 'two', 'type' => 'system', 'link' => null, 'is_read' => true, 'created_at' => now()],
        ]);

        $this->asUser()->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.link', '/dashboard');
    }

    public function test_mark_all_read_clears_the_unread_count(): void
    {
        $uid = $this->userId('user@example.test');
        NotificationRow::insert([
            ['user_id' => $uid, 'message' => 'a', 'type' => 'system', 'is_read' => false, 'created_at' => now()],
            ['user_id' => $uid, 'message' => 'b', 'type' => 'system', 'is_read' => false, 'created_at' => now()],
        ]);

        $this->asUser()->patchJson('/api/notifications/read-all')
            ->assertOk()->assertJsonPath('updated', 2);

        $this->asUser()->getJson('/api/notifications')->assertJsonPath('unread_count', 0);
    }

    public function test_a_user_cannot_mark_another_users_notification_read(): void
    {
        $row = NotificationRow::create([
            'user_id' => $this->userId('osm.admin@example.test'),
            'message' => 'not yours',
            'type' => 'system',
            'is_read' => false,
            'created_at' => now(),
        ]);

        $this->asUser()->patchJson("/api/notifications/{$row->id}")->assertNotFound();
        $this->assertDatabaseHas('notifications', ['id' => $row->id, 'is_read' => false]);
    }
}
