<?php

namespace Tests\Feature\Conformance;

use App\Models\SystemSetting;
use App\Models\User;

/**
 * Task F-02 — is_active and maintenance_mode were enforced only at login.
 *
 * GREEN after remediation Phase 1.3: the `active` middleware re-checks
 * both (from a fresh read) on every authenticated request.
 * Requirement anchors: BR-01, D-4, D-5.
 */
class SessionEnforcementTest extends ConformanceTestCase
{
    public function test_a_live_session_stops_working_once_the_account_is_deactivated(): void
    {
        $this->asUser()->getJson('/api/me')->assertOk();

        User::where('email', 'user@example.test')->update(['is_active' => false]);

        $this->asUser()->getJson('/api/me')->assertUnauthorized();
        $this->asUser()->postJson('/api/dashboard/documents')->assertUnauthorized();
    }

    public function test_a_live_non_admin_session_is_blocked_during_maintenance(): void
    {
        $this->asUser()->getJson('/api/me')->assertOk();

        SystemSetting::current()->update(['maintenance_mode' => true]);

        $this->asUser()->getJson('/api/dashboard/submissions')->assertStatus(503);
    }

    public function test_a_live_admin_session_still_works_during_maintenance(): void
    {
        SystemSetting::current()->update(['maintenance_mode' => true]);

        $this->asSystemAdmin()->getJson('/api/admin/users')->assertOk();
    }
}
