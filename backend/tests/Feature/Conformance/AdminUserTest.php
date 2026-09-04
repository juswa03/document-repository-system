<?php

namespace Tests\Feature\Conformance;

use App\Models\Office;
use App\Models\User;

/**
 * Task F-13 — an admin-created user must be able to log in.
 *
 * GREEN after remediation Phase 1.5: the controller no longer hashes the
 * password a second time on top of the model's `hashed` cast.
 * Anchors: D-7, E-add-8.
 */
class AdminUserTest extends ConformanceTestCase
{
    public function test_a_user_created_by_an_admin_can_log_in_with_the_given_password(): void
    {
        $this->asSystemAdmin()->postJson('/api/admin/users', [
            'full_name' => 'Newly Onboarded',
            'email' => 'newbie@example.test',
            'role' => 'user',
            'office_id' => Office::query()->value('id'),
            'password' => 's3cret-pass',
        ])->assertCreated();

        $this->loginRequest('newbie@example.test', 's3cret-pass')
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_deactivating_a_user_revokes_their_issued_tokens(): void
    {
        $user = $this->user('user@example.test');
        $user->createToken('device-a');
        $user->createToken('device-b');
        $this->assertSame(2, $user->tokens()->count());

        $this->asSystemAdmin()
            ->patchJson("/api/admin/users/{$user->id}", ['is_active' => false])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }
}
