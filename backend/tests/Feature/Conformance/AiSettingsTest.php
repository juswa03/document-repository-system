<?php

namespace Tests\Feature\Conformance;

use App\AI\AnthropicProvider;
use App\AI\Contracts\AiProvider;
use App\AI\NullProvider;
use App\Models\SystemSetting;
use Tests\Support\FakeAiProvider;

/**
 * §F / AI-09 — the AI agent layer is admin-managed. Phase 5.2: a system
 * admin sets the provider, model, spend cap and confidence threshold;
 * the API key stays in the environment and is never exposed. With the
 * layer off or keyless, the resolved provider is inert (NullProvider).
 */
class AiSettingsTest extends ConformanceTestCase
{
    public function test_only_a_system_admin_can_read_or_change_ai_settings(): void
    {
        $this->asUser()->getJson('/api/admin/ai-settings')->assertForbidden();
        $this->asOsmAdmin()->getJson('/api/admin/ai-settings')->assertForbidden();
        $this->asUser()->patchJson('/api/admin/ai-settings', ['ai_enabled' => true])->assertForbidden();
    }

    public function test_the_settings_payload_reports_key_presence_without_leaking_the_key(): void
    {
        config(['ai.providers.anthropic.key' => 'sk-ant-super-secret']);

        $body = $this->asSystemAdmin()->getJson('/api/admin/ai-settings')
            ->assertOk()
            ->assertJsonStructure([
                'spend_this_month_usd', 'models_by_provider',
                'ai_enabled', 'ai_provider', 'ai_model', 'ai_monthly_cap_usd',
                'ai_confidence_threshold', 'key_present', 'operational',
                'providers', 'available_models',
                'ai_capabilities', 'ai_capability_options' => [['key', 'label']],
            ])
            ->assertJsonPath('key_present', true)
            ->getContent();

        $this->assertStringNotContainsString('sk-ant-super-secret', $body);
    }

    public function test_the_default_model_is_the_cheapest_one(): void
    {
        $this->asSystemAdmin()->getJson('/api/admin/ai-settings')
            ->assertOk()
            ->assertJsonPath('ai_model', 'claude-haiku-4-5');
    }

    public function test_a_system_admin_can_update_the_settings_and_it_is_audited(): void
    {
        $this->asSystemAdmin()->patchJson('/api/admin/ai-settings', [
            'ai_enabled' => true,
            'ai_model' => 'claude-sonnet-5',
            'ai_monthly_cap_usd' => 50,
            'ai_confidence_threshold' => 0.75,
        ])->assertOk()
            ->assertJsonPath('ai_enabled', true)
            ->assertJsonPath('ai_model', 'claude-sonnet-5');

        $this->assertDatabaseHas('system_settings', [
            'ai_enabled' => true,
            'ai_model' => 'claude-sonnet-5',
            'ai_monthly_cap_usd' => 50.00,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_settings_updated']);
    }

    public function test_a_model_the_provider_does_not_offer_is_rejected(): void
    {
        $this->asSystemAdmin()->patchJson('/api/admin/ai-settings', [
            'ai_model' => 'gpt-4o',
        ])->assertStatus(422)->assertJsonValidationErrors('ai_model');
    }

    public function test_the_confidence_threshold_must_be_between_zero_and_one(): void
    {
        $this->asSystemAdmin()->patchJson('/api/admin/ai-settings', [
            'ai_confidence_threshold' => 1.5,
        ])->assertStatus(422)->assertJsonValidationErrors('ai_confidence_threshold');
    }

    public function test_the_provider_is_inert_when_the_layer_is_off(): void
    {
        $provider = app(AiProvider::class);

        $this->assertInstanceOf(NullProvider::class, $provider);
        $this->assertFalse($provider->isConfigured());
    }

    public function test_the_provider_is_inert_when_enabled_but_keyless(): void
    {
        config(['ai.providers.anthropic.key' => null]);
        SystemSetting::current()->update(['ai_enabled' => true]);

        $this->assertInstanceOf(NullProvider::class, app(AiProvider::class));
    }

    public function test_the_anthropic_provider_is_selected_when_enabled_with_a_key(): void
    {
        config(['ai.providers.anthropic.key' => 'sk-ant-test']);
        SystemSetting::current()->update(['ai_enabled' => true, 'ai_model' => 'claude-haiku-4-5']);

        $provider = app(AiProvider::class);

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertTrue($provider->isConfigured());
        $this->assertSame('claude-haiku-4-5', $provider->model());
    }

    public function test_the_test_endpoint_reports_a_failure_cleanly_when_not_configured(): void
    {
        $this->asSystemAdmin()->postJson('/api/admin/ai-settings/test')
            ->assertOk()
            ->assertJsonPath('ok', false);
    }

    public function test_the_test_endpoint_returns_the_provider_health_line(): void
    {
        $this->swap(AiProvider::class, new FakeAiProvider);
        app(AiProvider::class)->healthMessage = 'Connected — claude-haiku-4-5 replied "OK".';

        $this->asSystemAdmin()->postJson('/api/admin/ai-settings/test')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Connected — claude-haiku-4-5 replied "OK".');

        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_settings_tested']);
    }
}
