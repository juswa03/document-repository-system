<?php

namespace Tests\Feature\Conformance;

use App\AI\Contracts\AiProvider;
use App\AI\Suggestion;
use App\Jobs\AnalyzeDocument;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiProvider;

/**
 * §F — metadata-normalisation (AI-05/06) and confidentiality (AI-08)
 * suggestions. Like every AI finding they are stored pending and only
 * touch the document when a reviewer accepts (BR-03).
 */
class AiMetadataSuggestionTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function analyze(FakeAiProvider $fake, Document $document): void
    {
        $this->app->instance(AiProvider::class, $fake);
        (new AnalyzeDocument($document))->handle($fake);
    }

    private function metadata(array $fields, float $confidence = 0.8): Suggestion
    {
        return new Suggestion(
            kind: Suggestion::KIND_METADATA,
            data: ['fields' => $fields],
            confidence: $confidence,
            rationale: 'Tidied the reporting period and keywords.',
            model: 'claude-haiku-4-5',
            inputTokens: 900,
            outputTokens: 200,
        );
    }

    private function confidentiality(string $level): Suggestion
    {
        return new Suggestion(
            kind: Suggestion::KIND_CONFIDENTIALITY,
            data: ['access_level' => $level],
            confidence: 0.82,
            rationale: 'Description references personnel disciplinary detail.',
            model: 'claude-haiku-4-5',
            inputTokens: 700,
            outputTokens: 120,
        );
    }

    public function test_the_job_stores_metadata_and_confidentiality_suggestions(): void
    {
        $document = $this->createDocument();
        $fake = new FakeAiProvider;
        $fake->metadata = $this->metadata(['reporting_period' => 'AY 2025-2026']);
        $fake->confidentiality = $this->confidentiality('restricted');

        $this->analyze($fake, $document);

        $this->assertEqualsCanonicalizing(
            ['metadata', 'confidentiality'],
            $document->aiSuggestions()->pluck('kind')->all(),
        );
    }

    public function test_accepting_a_metadata_suggestion_applies_the_fields(): void
    {
        $document = $this->createDocument('user@example.test', [
            'reporting_period' => 'q3', 'keywords' => 'x',
        ]);
        $fake = new FakeAiProvider;
        $fake->metadata = $this->metadata([
            'reporting_period' => 'AY 2025-2026',
            'keywords' => 'accreditation, self-study, evidence',
            'description' => 'A fuller, more specific description of the self-study evidence set.',
            'document_date' => '2026-02-14',
        ]);
        $this->analyze($fake, $document);

        $suggestion = $document->aiSuggestions()->firstOrFail();
        $this->asOsmAdmin()->postJson("/api/osm-admin/ai-suggestions/{$suggestion->id}/accept")->assertOk();

        $document->refresh();
        $this->assertSame('AY 2025-2026', $document->reporting_period);
        $this->assertSame('accreditation, self-study, evidence', $document->keywords);
        $this->assertSame('2026-02-14', $document->document_date->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_suggestion_accepted']);
    }

    public function test_a_junk_document_date_is_ignored_but_other_fields_still_apply(): void
    {
        $document = $this->createDocument();
        $originalDate = $document->document_date->toDateString();

        $fake = new FakeAiProvider;
        $fake->metadata = $this->metadata([
            'keywords' => 'strategy, governance, planning',
            'document_date' => 'sometime last spring',
        ]);
        $this->analyze($fake, $document);

        $suggestion = $document->aiSuggestions()->firstOrFail();
        $this->asOsmAdmin()->postJson("/api/osm-admin/ai-suggestions/{$suggestion->id}/accept")->assertOk();

        $document->refresh();
        $this->assertSame('strategy, governance, planning', $document->keywords);
        $this->assertSame($originalDate, $document->document_date->toDateString());
    }

    public function test_accepting_a_confidentiality_suggestion_raises_the_access_level(): void
    {
        $document = $this->createDocument('user@example.test', ['access_level' => 'internal']);
        $fake = new FakeAiProvider;
        $fake->confidentiality = $this->confidentiality('confidential');
        $this->analyze($fake, $document);

        $suggestion = $document->aiSuggestions()->firstOrFail();
        $this->asOsmAdmin()->postJson("/api/osm-admin/ai-suggestions/{$suggestion->id}/accept")
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $this->assertSame('confidential', $document->fresh()->access_level);
    }
}
