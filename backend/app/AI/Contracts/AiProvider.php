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

    /**
     * Summarise the document from its extracted text (AI-07 / PF-13).
     * Suggest-only — surfaced in the review queue; on accept it is stored
     * as the document's AI summary. Returns null with no text or no key.
     */
    public function summarize(DocumentContext $document, ?string $text): ?Suggestion;

    /**
     * Turn a natural-language repository query into structured search
     * filters (FR-10). Returns a loose map — {q, category, office,
     * status, date_from, date_to} — or null when the layer is off. The
     * caller resolves names to ids and discards anything it doesn't
     * recognise; a plain text search is always the fallback.
     *
     * @param  list<string>  $categories
     * @param  list<string>  $offices
     * @return array<string, mixed>|null
     */
    public function interpretSearch(string $query, array $categories, array $offices): ?array;

    /**
     * Draft a short prose narrative over a report's already-computed
     * aggregate figures — the "report generation assistant" role (§F).
     * Nothing here recomputes or is treated as authoritative: the
     * numbers it is given ARE the report; this only puts a sentence or
     * two of human-readable context next to them. Returns null when the
     * layer is off or the model gives nothing usable.
     *
     * @param  array<string, mixed>  $payload  {summary, columns, sample_rows}
     */
    public function narrateReport(string $reportLabel, array $payload): ?Suggestion;
}
