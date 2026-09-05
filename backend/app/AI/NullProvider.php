<?php

namespace App\AI;

use App\AI\Contracts\AiProvider;
use App\AI\Exceptions\AiNotConfiguredException;

/**
 * The provider in force whenever the AI layer is off or unconfigured.
 * Every capability is a no-op / null result, so callers degrade to
 * manual entry without special-casing.
 */
class NullProvider implements AiProvider
{
    public function __construct(private readonly string $model = 'none') {}

    public function isConfigured(): bool
    {
        return false;
    }

    public function healthCheck(): string
    {
        throw AiNotConfiguredException::default();
    }

    public function model(): string
    {
        return $this->model;
    }

    public function classify(DocumentContext $document, array $categories): ?Suggestion
    {
        return null;
    }

    public function assessCompleteness(DocumentContext $document): ?Suggestion
    {
        return null;
    }

    public function extractMetadata(DocumentContext $document): ?Suggestion
    {
        return null;
    }

    public function checkConfidentiality(DocumentContext $document, array $accessLevels): ?Suggestion
    {
        return null;
    }

    public function summarize(DocumentContext $document, ?string $text): ?Suggestion
    {
        return null;
    }

    public function interpretSearch(string $query, array $categories, array $offices): ?array
    {
        return null;
    }

    public function narrateReport(string $reportLabel, array $payload): ?Suggestion
    {
        return null;
    }
}
