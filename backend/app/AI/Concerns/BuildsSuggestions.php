<?php

namespace App\AI\Concerns;

use App\AI\AiSettings;
use App\AI\DocumentContext;
use App\AI\Suggestion;
use App\Models\Document;

/**
 * The four suggestion capabilities (§F), expressed once in terms of an
 * abstract forced-tool-call. Each provider only has to translate the
 * neutral tool spec below into its own wire format and return the parsed
 * arguments plus a token count.
 *
 * Every method returns null on any error — the AI layer is an assist,
 * never a hard dependency of the submission flow.
 *
 * @property-read AiSettings $settings
 */
trait BuildsSuggestions
{
    abstract public function isConfigured(): bool;

    /**
     * Run one forced-tool-call.
     *
     * @param  array{name: string, description: string, schema: array<string, mixed>}  $tool
     * @return array{0: array<string, mixed>, 1: array{0: int, 1: int}}|null [arguments, [inputTokens, outputTokens]]
     */
    abstract protected function structuredCall(string $system, string $user, array $tool): ?array;

    public function classify(DocumentContext $document, array $categories): ?Suggestion
    {
        if (! $this->isConfigured() || $categories === []) {
            return null;
        }

        $result = $this->structuredCall(
            system: 'You are a records officer for a university strategy-management office. '
                .'From the metadata you are given, pick the single best category and document type. '
                .'Do not invent categories. If unsure, say so with a low confidence.',
            user: $document->toPromptText()."\n\nCategories to choose from:\n- ".implode("\n- ", $categories),
            tool: [
                'name' => 'record_classification',
                'description' => 'Record the suggested classification.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string', 'enum' => $categories],
                        'document_type' => ['type' => 'string', 'enum' => Document::TYPES],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'rationale' => ['type' => 'string'],
                    ],
                    'required' => ['category', 'document_type', 'confidence', 'rationale'],
                ],
            ],
        );

        if ($result === null) {
            return null;
        }

        [$input, $usage] = $result;

        return new Suggestion(
            kind: Suggestion::KIND_CLASSIFICATION,
            data: [
                'category' => $input['category'] ?? null,
                'document_type' => $input['document_type'] ?? null,
            ],
            confidence: (float) ($input['confidence'] ?? 0),
            rationale: (string) ($input['rationale'] ?? ''),
            model: $this->settings->model,
            inputTokens: $usage[0],
            outputTokens: $usage[1],
        );
    }

    public function assessCompleteness(DocumentContext $document): ?Suggestion
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $result = $this->structuredCall(
            system: 'You review document metadata before it is filed. List concrete gaps, '
                .'vague phrasing or internal inconsistencies a reviewer should resolve. '
                .'If the metadata is sound, return an empty list and high confidence.',
            user: $document->toPromptText(),
            tool: [
                'name' => 'record_completeness',
                'description' => 'Record the completeness review.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'concerns' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'summary' => ['type' => 'string'],
                    ],
                    'required' => ['concerns', 'confidence', 'summary'],
                ],
            ],
        );

        if ($result === null) {
            return null;
        }

        [$input, $usage] = $result;

        return new Suggestion(
            kind: Suggestion::KIND_COMPLETENESS,
            data: ['concerns' => array_values((array) ($input['concerns'] ?? []))],
            confidence: (float) ($input['confidence'] ?? 0),
            rationale: (string) ($input['summary'] ?? ''),
            model: $this->settings->model,
            inputTokens: $usage[0],
            outputTokens: $usage[1],
        );
    }

    public function extractMetadata(DocumentContext $document): ?Suggestion
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $result = $this->structuredCall(
            system: 'You normalise document metadata. Propose tidier values only where an '
                .'improvement is clear: a canonical reporting period (e.g. "AY 2025-2026"), '
                .'5-8 specific lower-case keywords, a fuller one-paragraph description, and '
                .'the document date in YYYY-MM-DD if it is stated or strongly implied. Leave '
                .'a field out if the current value is already good.',
            user: $document->toPromptText(),
            tool: [
                'name' => 'record_metadata',
                'description' => 'Record the suggested metadata values.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reporting_period' => ['type' => 'string'],
                        'keywords' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'document_date' => ['type' => 'string'],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'summary' => ['type' => 'string'],
                    ],
                    'required' => ['confidence', 'summary'],
                ],
            ],
        );

        if ($result === null) {
            return null;
        }

        [$input, $usage] = $result;

        $fields = array_filter([
            'reporting_period' => $input['reporting_period'] ?? null,
            'keywords' => $input['keywords'] ?? null,
            'description' => $input['description'] ?? null,
            'document_date' => $input['document_date'] ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== '');

        if ($fields === []) {
            return null;
        }

        return new Suggestion(
            kind: Suggestion::KIND_METADATA,
            data: ['fields' => $fields],
            confidence: (float) ($input['confidence'] ?? 0),
            rationale: (string) ($input['summary'] ?? ''),
            model: $this->settings->model,
            inputTokens: $usage[0],
            outputTokens: $usage[1],
        );
    }

    public function checkConfidentiality(DocumentContext $document, array $accessLevels): ?Suggestion
    {
        if (! $this->isConfigured() || $accessLevels === []) {
            return null;
        }

        $result = $this->structuredCall(
            system: 'You are a data-protection reviewer. Judge whether the stated access level '
                .'fits what the description implies (personnel, disciplinary, financial detail, '
                .'legal, or executive-session content usually warrants "restricted" or '
                .'"confidential"). If it fits, set matches_stated true and echo the stated level.',
            user: $document->toPromptText(),
            tool: [
                'name' => 'record_confidentiality',
                'description' => 'Record the access-level assessment.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'suggested_access_level' => ['type' => 'string', 'enum' => $accessLevels],
                        'matches_stated' => ['type' => 'boolean'],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['suggested_access_level', 'matches_stated', 'confidence', 'reason'],
                ],
            ],
        );

        if ($result === null) {
            return null;
        }

        [$input, $usage] = $result;

        // Nothing to suggest when the model agrees with the uploader.
        if (($input['matches_stated'] ?? false) === true) {
            return null;
        }

        return new Suggestion(
            kind: Suggestion::KIND_CONFIDENTIALITY,
            data: ['access_level' => $input['suggested_access_level'] ?? null],
            confidence: (float) ($input['confidence'] ?? 0),
            rationale: (string) ($input['reason'] ?? ''),
            model: $this->settings->model,
            inputTokens: $usage[0],
            outputTokens: $usage[1],
        );
    }
}
