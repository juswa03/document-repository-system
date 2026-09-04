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

    public function test_the_user_list_can_be_filtered_by_search_role_and_status(): void
    {
        $office = Office::query()->value('id');
        User::factory()->create([
            'full_name' => 'Zenaida Filed',
            'email' => 'zen@example.test',
            'role' => 'osm_admin',
            'office_id' => $office,
            'is_active' => false,
        ]);

        $admin = $this->asSystemAdmin();

        $admin->getJson('/api/admin/users?q=zen')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'zen@example.test');

        $admin->getJson('/api/admin/users?role=user')
            ->assertOk()
            ->assertJsonPath('data.0.role', 'user')
            ->assertJsonCount(1, 'data');

        $admin->getJson('/api/admin/users?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'zen@example.test');
    }

    public function test_an_admin_can_set_a_new_password_which_signs_the_user_out(): void
    {
        $user = $this->user('user@example.test');
        $user->createToken('device');
        $this->assertSame(1, $user->tokens()->count());

        $this->asSystemAdmin()
            ->patchJson("/api/admin/users/{$user->id}", ['password' => 'brand-new-pass'])
            ->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());

        $this->loginRequest('user@example.test', 'brand-new-pass')
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        $me = $this->user('system.admin@example.test');

        $this->asSystemAdmin()
            ->patchJson("/api/admin/users/{$me->id}", ['is_active' => false])
            ->assertStatus(422);

        $this->assertTrue($me->fresh()->is_active);
    }

    public function test_the_last_active_system_admin_cannot_be_demoted(): void
    {
        $me = $this->user('system.admin@example.test');

        // Only one system admin is seeded — demoting them would orphan the platform.
        $this->asSystemAdmin()
            ->patchJson("/api/admin/users/{$me->id}", ['role' => 'user'])
            ->assertStatus(422);

        $this->assertSame('system_admin', $me->fresh()->role);

        // With a second active system admin, the demotion goes through.
        User::factory()->create([
            'email' => 'admin2@example.test',
            'role' => 'system_admin',
            'office_id' => Office::query()->value('id'),
            'is_active' => true,
        ]);

        $this->asSystemAdmin()
            ->patchJson("/api/admin/users/{$me->id}", ['role' => 'user'])
            ->assertOk();

        $this->assertSame('user', $me->fresh()->role);
    }
}
