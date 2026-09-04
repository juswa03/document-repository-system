<?php

namespace Tests\Support;

use App\AI\Contracts\AiProvider;
use App\AI\DocumentContext;
use App\AI\Suggestion;

/**
 * Test double for the AI layer — no network, fully controllable. Bind it
 * with `$this->app->instance(AiProvider::class, $fake)`.
 */
class FakeAiProvider implements AiProvider
{
    public bool $configured = true;

    public string $healthMessage = 'Connected — fake provider.';

    public ?Suggestion $classification = null;

    public ?Suggestion $completeness = null;

    public ?Suggestion $metadata = null;

    public ?Suggestion $confidentiality = null;

    public int $classifyCalls = 0;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function healthCheck(): string
    {
        return $this->healthMessage;
    }

    public function model(): string
    {
        return 'claude-haiku-4-5';
    }

    public function classify(DocumentContext $document, array $categories): ?Suggestion
    {
        $this->classifyCalls++;

        return $this->classification;
    }

    public function assessCompleteness(DocumentContext $document): ?Suggestion
    {
        return $this->completeness;
    }

    public function extractMetadata(DocumentContext $document): ?Suggestion
    {
        return $this->metadata;
    }

    public function checkConfidentiality(DocumentContext $document, array $accessLevels): ?Suggestion
    {
        return $this->confidentiality;
    }
}
