<?php

namespace App\Dedup;

use App\Extraction\TextExtractor;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Steps 5 and 6 of the process flow, run BEFORE the document is saved.
 *
 * The uploader picks a file, and this looks at it against what is already
 * on file — byte-identical content, near-identical text, and a similar
 * title on an existing record — then answers the flow's question:
 * is this a duplicate, a new version of something, or a genuinely new
 * file? Nothing here writes: the caller returns the verdict to the
 * uploader, who confirms or overrides it (BR-03).
 */
class SubmissionPreflight
{
    public const VERDICT_NEW = 'new';

    public const VERDICT_DUPLICATE = 'duplicate';

    public const VERDICT_NEW_VERSION = 'new_version';

    /** Title similarity at or above which two records are "the same document". */
    private const TITLE_MATCH = 0.62;

    public function __construct(
        private readonly TextSimilarity $similarity,
        private readonly TextExtractor $extractor,
    ) {}

    /**
     * @param  array{title?: string|null, category_id?: int|null}  $metadata
     * @return array<string, mixed>
     */
    public function inspect(UploadedFile $file, User $user, array $metadata = []): array
    {
        $hash = hash_file('sha256', $file->getPathname()) ?: null;
        $text = $this->extractor->extract($file->getPathname(), $this->format($file));

        $exact = $hash === null ? null : Document::query()
            ->possibleDuplicateOf($hash, $user)
            ->first();

        $candidates = $this->candidates($user, $metadata['category_id'] ?? null);

        $near = $text === null ? null : $this->nearestByText($text, $candidates);
        $byTitle = $this->nearestByTitle($metadata['title'] ?? null, $candidates);

        return $this->verdict($exact, $near, $byTitle, $hash !== null);
    }

    /**
     * An exact content match is a duplicate. Otherwise, strongly similar
     * text or a matching title on an existing record means the uploader
     * is probably filing a revision of it — which is the case the flow
     * calls "new version", and which the system can act on by routing the
     * upload through the version chain instead of creating a stray record.
     *
     * @param  array{document: Document, score: float}|null  $near
     * @param  array{document: Document, score: float}|null  $byTitle
     * @return array<string, mixed>
     */
    private function verdict(?Document $exact, ?array $near, ?array $byTitle, bool $hashed): array
    {
        if ($exact !== null) {
            return [
                'verdict' => self::VERDICT_DUPLICATE,
                'confidence' => 1.0,
                'match' => $this->describe($exact),
                'signal' => 'content_hash',
                'rationale' => "This file is byte-for-byte identical to {$exact->tracking_no} "
                    ."(\"{$exact->title}\"), which is already on file.",
                'text_compared' => $hashed,
            ];
        }

        // Prefer whichever signal is stronger; text is the more reliable
        // of the two when both fire.
        $best = $near;
        $signal = 'text_similarity';

        if ($best === null || ($byTitle !== null && $byTitle['score'] > $best['score'])) {
            if ($byTitle !== null) {
                $best = $byTitle;
                $signal = 'title_similarity';
            }
        }

        if ($best === null) {
            return [
                'verdict' => self::VERDICT_NEW,
                'confidence' => 1.0,
                'match' => null,
                'signal' => null,
                'rationale' => 'Nothing on file looks like this document.',
                'text_compared' => $hashed,
            ];
        }

        $match = $best['document'];
        $pct = round($best['score'] * 100);

        return [
            'verdict' => self::VERDICT_NEW_VERSION,
            'confidence' => (float) $best['score'],
            'match' => $this->describe($match),
            'signal' => $signal,
            'rationale' => $signal === 'title_similarity'
                ? "The title closely matches {$match->tracking_no} (\"{$match->title}\"). "
                    .'If this is an updated copy of it, file it as a new version.'
                : "{$pct}% of the text overlaps {$match->tracking_no} (\"{$match->title}\"). "
                    .'If this is an updated copy of it, file it as a new version.',
            'text_compared' => $hashed,
        ];
    }

    /**
     * The pool a preflight may compare against — the same visibility rule
     * as scopePossibleDuplicateOf, so a check never reveals that another
     * office holds a similar document.
     *
     * @return Collection<int, Document>
     */
    private function candidates(User $user, ?int $categoryId): Collection
    {
        return Document::query()
            ->where('retention_status', 'active')
            // Never compare against a draft — an unsubmitted work in
            // progress is not "already on file".
            ->where('status', '!=', Document::STATUS_DRAFT)
            ->where(function ($q) use ($user) {
                $q->where('uploaded_by', $user->id)
                    ->when($user->office_id, fn ($qq, $office) => $qq->orWhere('office_id', $office));
            })
            ->when($categoryId, fn ($q, $v) => $q->where('category_id', $v))
            ->latest('id')
            ->limit(200)
            ->get(['id', 'tracking_no', 'title', 'status', 'version_number', 'extracted_text']);
    }

    /**
     * @param  Collection<int, Document>  $candidates
     * @return array{document: Document, score: float}|null
     */
    private function nearestByText(string $text, Collection $candidates): ?array
    {
        $mine = $this->trigramsOf($text);

        if (count($mine) < 5) {
            return null;
        }

        $best = null;

        foreach ($candidates as $candidate) {
            if (! filled($candidate->extracted_text)) {
                continue;
            }

            $score = $this->similarity->jaccard($mine, $this->trigramsOf((string) $candidate->extracted_text));

            if ($best === null || $score > $best['score']) {
                $best = ['document' => $candidate, 'score' => $score];
            }
        }

        return ($best !== null && $best['score'] >= $this->similarity->threshold()) ? $best : null;
    }

    /**
     * @param  Collection<int, Document>  $candidates
     * @return array{document: Document, score: float}|null
     */
    private function nearestByTitle(?string $title, Collection $candidates): ?array
    {
        $title = trim((string) $title);

        if ($title === '') {
            return null;
        }

        $mine = $this->wordsOf($title);

        if ($mine === []) {
            return null;
        }

        $best = null;

        foreach ($candidates as $candidate) {
            $theirs = $this->wordsOf((string) $candidate->title);

            if ($theirs === []) {
                continue;
            }

            $score = $this->similarity->jaccard($mine, $theirs);

            if ($best === null || $score > $best['score']) {
                $best = ['document' => $candidate, 'score' => $score];
            }
        }

        return ($best !== null && $best['score'] >= self::TITLE_MATCH) ? $best : null;
    }

    /** @return array<string, true> */
    private function trigramsOf(string $text): array
    {
        $words = preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        for ($i = 0, $n = count($words) - 2; $i < $n; $i++) {
            $out[$words[$i].' '.$words[$i + 1].' '.$words[$i + 2]] = true;
        }

        return $out;
    }

    /**
     * Bag of significant words, for comparing two titles. Version markers
     * are dropped so "Annual Report 2026 v2" still matches "Annual Report
     * 2026" — that pair is exactly the case worth catching.
     *
     * @return array<string, true>
     */
    private function wordsOf(string $title): array
    {
        $noise = ['v', 'ver', 'version', 'rev', 'revised', 'final', 'draft', 'copy', 'updated', 'new'];
        $words = preg_split('/\W+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($words as $word) {
            if (in_array($word, $noise, true) || preg_match('/^v?\d+(\.\d+)?$/', $word)) {
                continue;
            }
            $out[$word] = true;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function describe(Document $d): array
    {
        return [
            'id' => $d->id,
            'ref' => $d->tracking_no,
            'title' => $d->title,
            'status' => $d->status,
            'version_number' => $d->version_number,
        ];
    }

    private function format(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin'));
    }
}
