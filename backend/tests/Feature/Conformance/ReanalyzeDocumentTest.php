<?php

namespace Tests\Feature\Conformance;

use App\AI\Contracts\AiProvider;
use App\AI\Suggestion;
use App\Models\Document;
use App\Models\DocumentAiSuggestion;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiProvider;

/**
 * `documents:reanalyze` — the manual fallback for a document whose
 * automatic analysis silently produced nothing (e.g. a one-off
 * connection failure talking to the provider).
 */
class ReanalyzeDocumentTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function fakeWithClassification(): FakeAiProvider
    {
        $fake = new FakeAiProvider;
        $fake->classification = new Suggestion(
            kind: Suggestion::KIND_CLASSIFICATION,
            data: ['category' => 'Governance', 'document_type' => 'minutes'],
            confidence: 0.8,
            rationale: 'Looks like board minutes.',
            model: 'claude-haiku-4-5',
        );
        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    public function test_it_reanalyzes_a_document_by_tracking_number(): void
    {
        $this->fakeWithClassification();
        $document = $this->createDocument();

        $this->artisan('documents:reanalyze', ['document' => $document->tracking_no])
            ->assertSuccessful();

        $this->assertDatabaseHas('document_ai_suggestions', [
            'document_id' => $document->id,
            'kind' => 'classification',
        ]);
    }

    public function test_it_reanalyzes_a_document_by_id(): void
    {
        $this->fakeWithClassification();
        $document = $this->createDocument();

        $this->artisan('documents:reanalyze', ['document' => (string) $document->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('document_ai_suggestions', ['document_id' => $document->id]);
    }

    public function test_an_unknown_reference_fails_cleanly(): void
    {
        $this->fakeWithClassification();

        $this->artisan('documents:reanalyze', ['document' => 'NOPE-0000'])
            ->assertFailed();
    }

    public function test_no_argument_and_no_missing_flag_fails(): void
    {
        $this->fakeWithClassification();

        $this->artisan('documents:reanalyze')->assertFailed();
    }

    public function test_dry_run_does_not_call_the_provider(): void
    {
        $fake = $this->fakeWithClassification();
        $document = $this->createDocument();

        $this->artisan('documents:reanalyze', ['document' => $document->tracking_no, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, $fake->classifyCalls);
        $this->assertDatabaseMissing('document_ai_suggestions', ['document_id' => $document->id]);
    }

    public function test_missing_only_reanalyzes_documents_with_no_suggestions_yet(): void
    {
        $fake = $this->fakeWithClassification();

        $untouched = $this->createDocument('user@example.test', ['tracking_no' => 'UNTOUCHED-001']);
        $already = $this->createDocument('user@example.test', ['tracking_no' => 'ALREADY-001']);
        DocumentAiSuggestion::create([
            'document_id' => $already->id,
            'kind' => 'classification',
            'data' => [],
            'confidence' => 0.9,
            'model' => 'claude-haiku-4-5',
            'status' => 'pending',
            'created_at' => now(),
        ]);

        $this->artisan('documents:reanalyze', ['--missing' => true])->assertSuccessful();

        $this->assertSame(1, $fake->classifyCalls);
        $this->assertDatabaseHas('document_ai_suggestions', ['document_id' => $untouched->id]);
        $this->assertSame(1, DocumentAiSuggestion::where('document_id', $already->id)->count());
    }
}
