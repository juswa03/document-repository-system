<?php

namespace Tests\Feature\Conformance;

use App\Models\SystemSetting;

/**
 * Phase 22 — the system-settings screen also surfaces the read-only
 * platform configuration and a custom maintenance message.
 */
class SystemSettingsTest extends ConformanceTestCase
{
    public function test_the_payload_exposes_the_read_only_platform_config(): void
    {
        $this->asSystemAdmin()->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonStructure([
                'maintenance_mode',
                'maintenance_message',
                'audit_logging_enabled',
                'platform' => [
                    'max_upload_mb',
                    'allowed_file_types',
                    'document_types',
                    'access_levels',
                    'near_duplicate_threshold',
                    'governance_cadence_months',
                    'token_expiration_minutes',
                ],
            ]);
    }

    public function test_a_custom_maintenance_message_is_shown_to_blocked_users(): void
    {
        $this->asSystemAdmin()
            ->patchJson('/api/admin/settings', [
                'maintenance_mode' => true,
                'maintenance_message' => 'Back at 5pm — records freeze for month-end.',
            ])
            ->assertOk()
            ->assertJsonPath('maintenance_message', 'Back at 5pm — records freeze for month-end.');

        $this->postJson('/api/login', ['email' => 'user@example.test', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Back at 5pm — records freeze for month-end.');
    }

    public function test_the_free_text_message_is_not_coerced_to_a_boolean(): void
    {
        $this->asSystemAdmin()
            ->patchJson('/api/admin/settings', ['maintenance_message' => '0 downtime expected'])
            ->assertOk();

        $this->assertSame('0 downtime expected', SystemSetting::current()->maintenance_message);
    }
}
