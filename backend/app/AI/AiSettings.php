<?php

namespace App\AI;

use App\Models\SystemSetting;

/**
 * The effective AI configuration for a request: the admin-managed values
 * from `system_settings`, with the provider API key layered in from the
 * environment (config/ai.php) — the key is never stored in the database.
 */
final class AiSettings
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $provider,
        public readonly string $model,
        public readonly float $monthlyCapUsd,
        public readonly float $confidenceThreshold,
        public readonly ?string $apiKey,
        public readonly ?string $baseUrl = null,
    ) {}

    public static function fromCurrent(): self
    {
        $s = SystemSetting::current();
        $provider = $s->ai_provider ?: (string) config('ai.provider');

        return new self(
            enabled: (bool) $s->ai_enabled,
            provider: $provider,
            model: $s->ai_model ?: (string) config('ai.model'),
            monthlyCapUsd: (float) ($s->ai_monthly_cap_usd ?? config('ai.monthly_cap_usd')),
            confidenceThreshold: (float) ($s->ai_confidence_threshold ?? config('ai.confidence_threshold')),
            apiKey: config("ai.providers.{$provider}.key"),
            baseUrl: config("ai.providers.{$provider}.base_url"),
        );
    }

    public function hasKey(): bool
    {
        return filled($this->apiKey);
    }

    /**
     * Whether the layer should attempt a real provider call. Spend-cap
     * enforcement is added in Phase 5.3 once calls (and usage logging)
     * exist.
     */
    public function usable(): bool
    {
        return $this->enabled && $this->hasKey();
    }
}
