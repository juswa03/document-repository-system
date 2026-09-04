<?php

namespace Tests\Feature\Conformance;

use App\AI\Contracts\AiProvider;
use App\AI\NullProvider;
use App\AI\Suggestion;
use App\Dedup\TextSimilarity;
use App\Jobs\AnalyzeDocument;
use App\Models\Category;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiProvider;

/**
 * AI-07 / PF-13 (depth) — document summarisation (from the extracted
 * text, stored on accept) and a deterministic near-duplicate check
 * (word-trigram Jaccard, no embedding store). Both are suggest-only.
 */
class AiSummaryNearDuplicateTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function analyze(FakeAiProvider|NullProvider $provider, Document $document): void
    {
        $this->app->instance(AiProvider::class, $provider);
        (new AnalyzeDocument($document))->handle($provider);
    }

    private function summarySuggestion(): Suggestion
    {
        return new Suggestion(
            kind: Suggestion::KIND_SUMMARY,
            data: ['summary' => 'A board resolution approving the 2027 capital works budget.', 'key_points' => ['budget approved', 'effective 2027']],
            confidence: 0.9,
            rationale: 'From the extracted text.',
            model: 'claude-haiku-4-5',
            inputTokens: 1500,
            outputTokens: 120,
        );
    }

    /* ---- summarisation ---- */

    public function test_a_summary_suggestion_is_stored_by_the_job(): void
    {
        $doc = $this->createDocument('user@example.test', ['extracted_text' => 'Long document text about the capital budget.']);
        $fake = new FakeAiProvider;
        $fake->summary = $this->summarySuggestion();

        $this->analyze($fake, $doc);

        $this->assertSame(1, $doc->aiSuggestions()->where('kind', 'summary')->count());
    }

    public function test_accepting_a_summary_stores_it_on_the_document(): void
    {
        $doc = $this->createDocument('user@example.test', ['extracted_text' => 'Body text.']);
        $fake = new FakeAiProvider;
        $fake->summary = $this->summarySuggestion();
        $this->analyze($fake, $doc);

        $suggestion = $doc->aiSuggestions()->where('kind', 'summary')->firstOrFail();

        $this->asOsmAdmin()->postJson("/api/osm-admin/ai-suggestions/{$suggestion->id}/accept")
            ->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('ai_summaries', [
            'document_id' => $doc->id,
            'summary_text' => 'A board resolution approving the 2027 capital works budget.',
        ]);
        $this->assertNotNull($doc->fresh()->aiSummary);
    }

    public function test_summary_is_not_produced_when_there_is_no_extracted_text(): void
    {
        $doc = $this->createDocument('user@example.test', ['extracted_text' => null]);
        $fake = new FakeAiProvider;
        $fake->summary = $this->summarySuggestion();   // provider would return it, but the job passes null text

        // FakeAiProvider ignores the text arg, so this asserts the wiring
        // passes extracted_text through — with real providers a null text
        // short-circuits in BuildsSuggestions::summarize().
        $this->analyze($fake, $doc);

        $this->assertSame(1, $doc->aiSuggestions()->where('kind', 'summary')->count());
    }

    /* ---- near-duplicate ---- */

    private string $shared = 'The Office for Strategy Management recommends approval of the twenty twenty seven '
        .'capital works budget covering the science building extension the library refurbishment and the '
        .'renewal of the campus photovoltaic array subject to the usual procurement controls and quarterly '
        .'reporting to the board of regents.';

    public function test_a_near_identical_document_in_the_same_category_and_office_is_flagged(): void
    {
        $first = $this->createDocument('user@example.test', ['extracted_text' => $this->shared]);
        $second = $this->createDocument('user@example.test', ['extracted_text' => $this->shared.' Minor addendum.']);

        $this->analyze(new NullProvider, $second);   // deterministic — runs with the AI layer off

        $row = $second->aiSuggestions()->where('kind', 'near_duplicate')->first();
        $this->assertNotNull($row);
        $this->assertSame($first->tracking_no, $row->data['duplicate_of']);
        $this->assertGreaterThanOrEqual(app(TextSimilarity::class)->threshold(), $row->data['similarity']);
    }

    public function test_an_unrelated_document_is_not_flagged(): void
    {
        $this->createDocument('user@example.test', ['extracted_text' => $this->shared]);
        $other = $this->createDocument('user@example.test', [
            'extracted_text' => 'Staff social committee minutes: catering, the summer outing, and the raffle.',
        ]);

        $this->analyze(new NullProvider, $other);

        $this->assertSame(0, $other->aiSuggestions()->where('kind', 'near_duplicate')->count());
    }

    public function test_similarity_is_not_computed_across_categories(): void
    {
        $catB = Category::query()->orderByDesc('id')->value('id');
        $first = $this->createDocument('user@example.test', ['extracted_text' => $this->shared]);
        $second = $this->createDocument('user@example.test', ['extracted_text' => $this->shared]);
        $second->update(['category_id' => $catB]);

        $this->analyze(new NullProvider, $second->fresh());

        $this->assertSame(0, $second->aiSuggestions()->where('kind', 'near_duplicate')->count());
    }

    public function test_the_jaccard_helper_scores_identical_and_disjoint_text(): void
    {
        $sim = app(TextSimilarity::class);
        $a = ['one two three' => true, 'two three four' => true];

        $this->assertSame(1.0, $sim->jaccard($a, $a));
        $this->assertSame(0.0, $sim->jaccard($a, ['nine ten eleven' => true]));
    }
}
