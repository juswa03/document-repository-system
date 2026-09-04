<?php

namespace App\Http\Controllers\Api\Admin;

use App\AI\AiSettings;
use App\AI\Contracts\AiProvider;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentAiSuggestion;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * System-admin management of the AI agent layer (§F): the on/off switch,
 * provider, model, spend cap and confidence threshold. The provider API
 * key is NOT here — it comes from the environment only. This endpoint
 * reports whether a key is present, never its value.
 */
class AiSettingController extends Controller
{
    private const EDITABLE = [
        'ai_enabled', 'ai_provider', 'ai_model',
        'ai_monthly_cap_usd', 'ai_confidence_threshold', 'ai_capabilities',
    ];

    public function show(): mixed
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): mixed
    {
        $providers = array_keys(config('ai.providers'));
        $provider = $request->input('ai_provider', SystemSetting::current()->ai_provider);
        $models = array_keys(config("ai.providers.{$provider}.models", []));

        $data = $request->validate([
            'ai_enabled' => ['sometimes', 'boolean'],
            'ai_provider' => ['sometimes', Rule::in($providers)],
            'ai_model' => ['sometimes', 'string', Rule::in($models)],
            'ai_monthly_cap_usd' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'ai_confidence_threshold' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'ai_capabilities' => ['sometimes', 'array'],
            'ai_capabilities.*' => ['string', Rule::in(array_keys(config('ai.capabilities')))],
        ], [
            'ai_model.in' => 'That model is not offered by the selected provider.',
            'ai_provider.in' => 'Unknown provider.',
            'ai_capabilities.*.in' => 'Unknown AI capability.',
        ]);

        if (array_key_exists('ai_capabilities', $data)) {
            // Store in the canonical config order, de-duplicated.
            $data['ai_capabilities'] = array_values(array_intersect(
                array_keys(config('ai.capabilities')),
                $data['ai_capabilities'],
            ));
        }

        if (array_key_exists('ai_enabled', $data)) {
            $data['ai_enabled'] = filter_var($data['ai_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        $settings = SystemSetting::current();
        $before = $settings->only(array_keys($data));
        $settings->update($data);
        $after = $settings->only(array_keys($data));

        if ($before !== $after) {
            AuditLog::record(
                $request->user()->id,
                'ai_settings_updated',
                'Updated AI settings: '.implode(', ', array_keys($data)).'.',
                SystemSetting::class,
                $settings->id,
                ['before' => $before, 'after' => $after],
            );
        }

        return response()->json($this->payload());
    }

    /**
     * POST /api/admin/ai-settings/test — a live round-trip to the
     * provider so the admin can confirm the key and model work.
     */
    public function test(Request $request, AiProvider $provider): mixed
    {
        try {
            $message = $provider->healthCheck();
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 200);
        }

        AuditLog::record(
            $request->user()->id,
            'ai_settings_tested',
            'Ran the AI provider health check.',
            SystemSetting::class,
            SystemSetting::current()->id,
        );

        return response()->json(['ok' => true, 'message' => $message]);
    }

    private function payload(): array
    {
        $settings = SystemSetting::current();
        $effective = AiSettings::fromCurrent();

        $modelsFor = fn (string $provider) => collect(config("ai.providers.{$provider}.models", []))
            ->map(fn (array $m, string $id) => ['id' => $id] + $m)
            ->values();

        return [
            'ai_enabled' => (bool) $settings->ai_enabled,
            'ai_provider' => $settings->ai_provider,
            'ai_model' => $settings->ai_model,
            'ai_monthly_cap_usd' => (float) $settings->ai_monthly_cap_usd,
            'ai_confidence_threshold' => (float) $settings->ai_confidence_threshold,
            'key_present' => $effective->hasKey(),
            'operational' => app(AiProvider::class)->isConfigured(),
            'spend_this_month_usd' => DocumentAiSuggestion::spendThisMonth(),
            'providers' => array_keys(config('ai.providers')),
            'available_models' => $modelsFor($settings->ai_provider),
            'models_by_provider' => collect(config('ai.providers'))
                ->mapWithKeys(fn ($_, $p) => [$p => $modelsFor($p)]),
            // null in the DB means every capability is on.
            'ai_capabilities' => $settings->ai_capabilities ?? array_keys(config('ai.capabilities')),
            'ai_capability_options' => collect(config('ai.capabilities'))
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
        ];
    }
}
