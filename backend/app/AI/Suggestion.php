<?php

namespace App\AI;

/**
 * One AI finding about a document — a suggestion, never an applied
 * change (BR-03). The job that produces these persists them as
 * `document_ai_suggestions` rows for a human to accept or dismiss.
 */
final class Suggestion
{
    public const KIND_CLASSIFICATION = 'classification';

    public const KIND_COMPLETENESS = 'completeness';

    public const KIND_METADATA = 'metadata';

    public const KIND_CONFIDENTIALITY = 'confidentiality';

    public const KIND_SUMMARY = 'summary';

    public const KIND_NEAR_DUPLICATE = 'near_duplicate';

    public function __construct(
        public readonly string $kind,
        /** @var array<string, mixed> kind-specific payload */
        public readonly array $data,
        public readonly float $confidence,
        public readonly string $rationale,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    /**
     * Indicative USD cost from token counts and the per-model $/1M rates
     * in config/ai.php. Used for spend-cap accounting, not billing.
     */
    public function estimatedUsd(): float
    {
        $rates = null;
        foreach ((array) config('ai.providers') as $provider) {
            if (is_array($provider['models'][$this->model] ?? null)) {
                $rates = $provider['models'][$this->model];
                break;
            }
        }

        if (! is_array($rates)) {
            return 0.0;
        }

        return round(
            ($this->inputTokens / 1_000_000) * ($rates['input'] ?? 0)
            + ($this->outputTokens / 1_000_000) * ($rates['output'] ?? 0),
            4,
        );
    }
}
