<?php

namespace Tests\Feature\Conformance;

use App\Models\SystemSetting;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Task F-03 / PF-01 — the /api/login endpoint.
 *
 * GREEN today: locks the login + server-side redirect behaviour that
 * the audit found working, and the two gates that already exist
 * (inactive account, maintenance mode).
 */
class LoginEndpointTest extends ConformanceTestCase
{
    public static function roleRedirects(): array
    {
        return [
            ['system.admin@example.test', 'system_admin', '/admin'],
            ['osm.admin@example.test', 'osm_admin', '/osm-admin'],
            ['user@example.test', 'user', '/dashboard'],
        ];
    }

    #[DataProvider('roleRedirects')]
    public function test_valid_credentials_return_a_token_and_server_computed_redirect(string $email, string $role, string $redirect): void
    {
        $this->postJson('/api/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role', $role)
            ->assertJsonPath('redirect', $redirect)
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role'], 'redirect']);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->postJson('/api/login', ['email' => 'user@example.test', 'password' => 'nope'])
            ->assertStatus(422);
    }

    public function test_a_deactivated_account_cannot_log_in(): void
    {
        User::where('email', 'user@example.test')->update(['is_active' => false]);

        $this->postJson('/api/login', ['email' => 'user@example.test', 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_a_non_admin_cannot_log_in_during_maintenance(): void
    {
        SystemSetting::current()->update(['maintenance_mode' => true]);

        $this->postJson('/api/login', ['email' => 'user@example.test', 'password' => 'password'])
            ->assertStatus(422);

        $this->postJson('/api/login', ['email' => 'system.admin@example.test', 'password' => 'password'])
            ->assertOk();
    }
}
