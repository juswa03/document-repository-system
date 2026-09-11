<?php

namespace Tests\Feature\Conformance;

use App\Http\Controllers\Api\ProfileController;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Self-service profile management: display name, password change, and
 * profile picture upload/removal — previously untested.
 */
class ProfileTest extends ConformanceTestCase
{
    #[Test]
    public function a_user_can_update_their_display_name(): void
    {
        $this->asUser()->patchJson('/api/profile', ['full_name' => 'Juana Updated'])
            ->assertOk()
            ->assertJsonPath('name', 'Juana Updated');

        $this->assertSame('Juana Updated', $this->user('user@example.test')->full_name);
    }

    #[Test]
    public function a_user_can_change_their_password_with_the_correct_current_one(): void
    {
        $this->asUser()->patchJson('/api/profile', [
            'full_name' => 'Juana User',
            'current_password' => 'password',
            'new_password' => 'a-new-password123',
            'new_password_confirmation' => 'a-new-password123',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-new-password123', $this->user('user@example.test')->password));
    }

    #[Test]
    public function changing_password_with_the_wrong_current_password_is_refused(): void
    {
        $this->asUser()->patchJson('/api/profile', [
            'full_name' => 'Juana User',
            'current_password' => 'not-the-right-password',
            'new_password' => 'a-new-password123',
            'new_password_confirmation' => 'a-new-password123',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $this->user('user@example.test')->password));
    }

    #[Test]
    public function a_user_can_upload_replace_and_remove_their_avatar(): void
    {
        Storage::fake(ProfileController::AVATAR_DISK);

        $first = UploadedFile::fake()->image('avatar1.jpg', 100, 100)->size(50);
        $this->asUser()->post('/api/profile/avatar', ['avatar' => $first])->assertOk();

        $user = $this->user('user@example.test');
        $this->assertNotNull($user->avatar_path);
        Storage::disk(ProfileController::AVATAR_DISK)->assertExists($user->avatar_path);
        $firstPath = $user->avatar_path;

        // Replacing deletes the previous file rather than leaving an orphan.
        $second = UploadedFile::fake()->image('avatar2.png', 100, 100)->size(50);
        $this->asUser()->post('/api/profile/avatar', ['avatar' => $second])->assertOk();

        $user->refresh();
        $this->assertNotSame($firstPath, $user->avatar_path);
        Storage::disk(ProfileController::AVATAR_DISK)->assertMissing($firstPath);
        Storage::disk(ProfileController::AVATAR_DISK)->assertExists($user->avatar_path);

        $this->asUser()->delete('/api/profile/avatar')->assertOk()->assertJsonPath('avatar_url', null);

        $user->refresh();
        $this->assertNull($user->avatar_path);
    }

    #[Test]
    public function a_non_image_file_is_rejected_as_an_avatar(): void
    {
        Storage::fake(ProfileController::AVATAR_DISK);

        $this->asUser()->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('not-a-photo.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('avatar');
    }

    #[Test]
    public function any_authenticated_user_can_view_a_colleagues_avatar(): void
    {
        Storage::fake(ProfileController::AVATAR_DISK);

        $this->asUser()->post('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 100, 100)->size(50),
        ])->assertOk();

        $target = $this->user('user@example.test');

        $this->asOfficeAdmin()->get("/api/users/{$target->id}/avatar")->assertOk();
    }

    #[Test]
    public function an_unauthenticated_caller_cannot_view_an_avatar(): void
    {
        // Sanctum::actingAs() (used by asUser()/asOfficeAdmin() elsewhere in
        // this file) binds the acting user for the rest of the test process
        // rather than per-request, so an "unauthenticated" check must never
        // follow one in the same test — write the avatar straight to disk
        // instead of authenticating to upload it through the endpoint.
        Storage::fake(ProfileController::AVATAR_DISK);
        $target = $this->user('user@example.test');
        $target->avatar_path = 'avatars/existing.jpg';
        $target->save();
        Storage::disk(ProfileController::AVATAR_DISK)->put($target->avatar_path, 'fake-image-bytes');

        $this->getJson("/api/users/{$target->id}/avatar")->assertStatus(401);
    }

    #[Test]
    public function viewing_an_avatar_for_a_user_with_none_is_a_404(): void
    {
        $target = $this->user('office.admin@example.test');

        $this->asUser()->get("/api/users/{$target->id}/avatar")->assertStatus(404);
    }
}
