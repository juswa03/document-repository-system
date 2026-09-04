<?php

namespace App\Dedup;

use App\Models\Document;

/**
 * Word-trigram Jaccard similarity over extracted document text — a
 * deterministic near-duplicate check that needs no embedding store
 * (PF-06 / AI-03, Phase 10). Compares only within the same category and
 * office and only against active documents, so it never leaks the
 * existence of another unit's work.
 */
class TextSimilarity
{
    /** Candidate pool size — newest first. */
    private const CANDIDATE_LIMIT = 200;

    public function threshold(): float
    {
        return ((int) config('documents.near_duplicate_threshold', 65)) / 100;
    }

    /**
     * The most similar existing document, if it clears the threshold.
     *
     * @return array{document: Document, score: float}|null
     */
    public function nearest(Document $document): ?array
    {
        $mine = $this->trigrams((string) $document->extracted_text);

        if (count($mine) < 5) {
            return null;
        }

        $best = null;

        Document::query()
            ->where('id', '!=', $document->id)
            ->where('retention_status', 'active')
            ->whereNotNull('extracted_text')
            ->when($document->category_id, fn ($q, $v) => $q->where('category_id', $v))
            ->when($document->office_id, fn ($q, $v) => $q->where('office_id', $v))
            ->latest('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get(['id', 'tracking_no', 'title', 'status', 'extracted_text'])
            ->each(function (Document $candidate) use ($mine, &$best) {
                $score = $this->jaccard($mine, $this->trigrams((string) $candidate->extracted_text));
                if ($best === null || $score > $best['score']) {
                    $best = ['document' => $candidate, 'score' => $score];
                }
            });

        return ($best !== null && $best['score'] >= $this->threshold()) ? $best : null;
    }

    /** @return array<string, true> */
    private function trigrams(string $text): array
    {
        $words = preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        for ($i = 0, $n = count($words) - 2; $i < $n; $i++) {
            $out[$words[$i].' '.$words[$i + 1].' '.$words[$i + 2]] = true;
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    public function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($a, $b));
        $union = count($a + $b);

        return $union > 0 ? round($intersection / $union, 4) : 0.0;
    }
}
