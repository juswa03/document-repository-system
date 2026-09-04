<?php

namespace App\AI\Contracts;

use App\AI\DocumentContext;
use App\AI\Exceptions\AiNotConfiguredException;
use App\AI\Suggestion;

/**
 * The AI agent layer (§F) talks to exactly one provider at a time,
 * behind this interface, so the provider is swappable and the rest of
 * the app never imports a vendor SDK directly.
 *
 * Phase 5.2 establishes the contract and the wiring. The document
 * classifier, metadata extractor, completeness and confidentiality
 * checkers, and the semantic near-duplicate check are added in Phase 5.3
 * as further methods here — every one of them suggest-only, never
 * writing to a document until a human confirms (BR-03).
 */
interface AiProvider
{
    /**
     * Whether a real call can be made right now — the layer is switched
     * on, a key is present, and any spend cap still has headroom. When
     * false, callers must fall back to manual entry and persist nothing.
     */
    public function isConfigured(): bool;

    /**
     * A minimal round-trip to the provider that the admin panel uses to
     * confirm the credentials and the selected model actually work.
     * Returns a short human-readable status line.
     *
     * @throws AiNotConfiguredException when isConfigured() is false
     * @throws \RuntimeException when the provider rejects the call
     */
    public function healthCheck(): string;

    /** The model id this provider will use for requests. */
    public function model(): string;

    /**
     * Suggest the category and document type for a document from its
     * metadata (PF-05 / AI-01 / AI-02). Returns null when the layer is
     * not configured or the model gives nothing usable.
     *
     * @param  list<string>  $categories  the category names to choose from
     */
    public function classify(DocumentContext $document, array $categories): ?Suggestion;

    /**
     * Flag gaps or inconsistencies in the supplied metadata a reviewer
     * should look at before approving (PF-07 / AI-04). Advisory only.
     */
    public function assessCompleteness(DocumentContext $document): ?Suggestion;

    /**
     * Suggest tidier values for the descriptive metadata the uploader
     * typed — a canonical reporting period, sharper keywords, a fuller
     * description, the document date (AI-05 / AI-06). Suggest-only.
     */
    public function extractMetadata(DocumentContext $document): ?Suggestion;

    /**
     * Judge whether the stated access level looks right for what the
     * description implies, and suggest a level if not (AI-08 / BR-04 /
     * BR-08). Suggest-only — a reviewer still sets the real level.
     *
     * @param  list<string>  $accessLevels  the levels to choose from
     */
    public function checkConfidentiality(DocumentContext $document, array $accessLevels): ?Suggestion;
}
