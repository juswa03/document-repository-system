<?php

namespace Tests\Feature\Conformance;

use App\AI\Contracts\AiProvider;
use App\AI\Suggestion;
use App\Jobs\AnalyzeDocument;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentAiSuggestion;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiProvider;

/**
 * §F — the AI agent layer produces suggestions on upload; a reviewer
 * accepts (applies) or dismisses them. Nothing is written to a document
 * automatically (BR-03). Spend is capped.
 */
class AiCapabilitiesTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function classification(string $category, string $type = 'report', float $confidence = 0.9): Suggestion
    {
        return new Suggestion(
            kind: Suggestion::KIND_CLASSIFICATION,
            data: ['category' => $category, 'document_type' => $type],
            confidence: $confidence,
            rationale: 'Matches the strategic-planning pattern.',
            model: 'claude-haiku-4-5',
            inputTokens: 1200,
            outputTokens: 300,
        );
    }

    private function completeness(array $concerns = ['Reporting period is vague.']): Suggestion
    {
        return new Suggestion(
            kind: Suggestion::KIND_COMPLETENESS,
            data: ['concerns' => $concerns],
            confidence: 0.7,
            rationale: 'One field needs tightening.',
            model: 'claude-haiku-4-5',
            inputTokens: 800,
            outputTokens: 150,
        );
    }

    private function runJobWith(FakeAiProvider $fake, Document $document): void
    {
        $this->app->instance(AiProvider::class, $fake);
        (new AnalyzeDocument($document))->handle($fake);
    }

    public function test_upload_queues_an_analysis_job(): void
    {
        Queue::fake();

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())->assertCreated();

        Queue::assertPushed(AnalyzeDocument::class);
    }

    public function test_the_job_is_a_no_op_when_the_layer_is_off(): void
    {
        $document = $this->createDocument();
        $fake = new FakeAiProvider;
        $fake->configured = false;
        $fake->classification = $this->classification('Strategic Planning');

        $this->runJobWith($fake, $document);

        $this->assertSame(0, DocumentAiSuggestion::count());
    }

    public function test_the_job_stores_each_finding_as_a_pending_suggestion(): void
    {
        $document = $this->createDocument();
        $category = Category::query()->value('category_name');

        $fake = new FakeAiProvider;
        $fake->classification = $this->classification($category);
        $fake->completeness = $this->completeness();

        $this->runJobWith($fake, $document);

        $this->assertSame(2, $document->aiSuggestions()->count());
        $this->assertSame(2, $document->aiSuggestions()->where('status', 'pending')->count());
        $this->assertDatabaseHas('document_ai_suggestions', [
            'document_id' => $document->id,
            'kind' => 'classification',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_suggestion_created']);

        $row = $document->aiSuggestions()->where('kind', 'classification')->first();
        $this->assertGreaterThan(0, $row->cost_usd);          // priced from tokens
    }

    public function test_a_disabled_capability_is_skipped(): void
    {
        SystemSetting::current()->update(['ai_capabilities' => ['completeness', 'near_duplicate']]);

        $document = $this->createDocument();
        $category = Category::query()->value('category_name');

        $fake = new FakeAiProvider;
        $fake->classification = $this->classification($category);
        $fake->completeness = $this->completeness();

        $this->runJobWith($fake, $document);

        $this->assertSame(0, $document->aiSuggestions()->where('kind', 'classification')->count());
        $this->assertSame(1, $document->aiSuggestions()->where('kind', 'completeness')->count());
    }

    public function test_re_running_the_job_replaces_still_pending_suggestions(): void
    {
        $document = $this->createDocument();
        $category = Category::query()->value('category_name');

        $fake = new FakeAiProvider;
        $fake->classification = $this->classification($category);
        $this->runJobWith($fake, $document);
        $this->runJobWith($fake, $document);

        $this->assertSame(1, $document->aiSuggestions()->count());
    }

    public function test_analysis_stops_once_the_monthly_spend_cap_is_reached(): void
    {
        SystemSetting::current()->update(['ai_monthly_cap_usd' => 5]);
        DocumentAiSuggestion::create([
            'document_id' => $this->createDocument()->id,
            'kind' => 'classification', 'data' => [], 'model' => 'claude-haiku-4-5',
            'cost_usd' => 6, 'status' => 'accepted', 'created_at' => now(),
        ]);

        $document = $this->createDocument();
        $fake = new FakeAiProvider;
        $fake->classification = $this->classification(Category::query()->value('category_name'));

        $this->runJobWith($fake, $document);

        $this->assertSame(0, $document->aiSuggestions()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_spend_cap_reached']);
    }

    public function test_a_reviewer_lists_the_suggestions_for_a_document(): void
    {
        $document = $this->createDocument();
        $fake = new FakeAiProvider;
        $fake->classification = $this->classification(Category::query()->value('category_name'));
        $this->runJobWith($fake, $document);

        $this->asOsmAdmin()->getJson("/api/osm-admin/documents/{$document->id}/ai-suggestions")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.kind', 'classification')
            ->assertJsonPath('0.status', 'pending');
    }

    public function test_ordinary_users_cannot_reach_the_suggestion_endpoints(): void
    {
        $document = $this->createDocument();
        $fake = new FakeAiProvider;
        $fake->completeness = $this->completeness();
        $this->runJobWith($fake, $document);
        $suggestion = $document->aiSuggestions()->firstOrFail();

        $this->asUser()->getJson("/api/osm-admin/documents/{$document->id}/ai-suggestions")->assertForbidden();
        $this->asUser()->postJson("/api/osm-admin/ai-suggestions/{$suggestion->id}/accept")->assertForbidden();
    }

    public function test_accepting_a_classification_applies_it_to_the_document(): void
    {
        $document = $this->createDocument('user@example.test', ['document_type' => 'memo']);
        $target = Category::query()->orderByDesc('id')->first();

        $fake = new FakeAiProvider;
        $fake->classification = $this->classification($target->category_name, 'report');
        $this->runJobWith($fake, $document);

        $suggestion = $document->aiSuggestions()->firstOrFail();

        $this->asOsmAdmin()->postJson("/api/osm-admin/ai-suggestions/{$suggestion->id}/accept")
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $document->refresh();
        $this->assertSame($target->id, $document->category_id);
        $this->assertSame('report', $document->document_type);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_suggestion_accepted']);
    }

    public function test_accepting_a_completeness_note_changes_no_document_field(): void
    {
        $document = $this->createDocument();
        $before = $document->only(['category_id', 'document_type']);

        $fake = new FakeAiProvider;
        $fake->completeness = $this->completeness();
        $this->runJobWith($fake, $document);

        $suggestion = $document->aiSuggestions()->firstOrFail();
        $this->asOsmAdmin()->postJson("/api/osm-admin/ai-suggestions/{$suggestion->id}/accept")->assertOk();

        $this->assertSame($before, $document->fresh()->only(['category_id', 'document_type']));
    }

    public function test_a_suggestion_cannot_be_resolved_twice(): void
    {
        $document = $this->createDocument();
        $fake = new FakeAiProvider;
        $fake->completeness = $this->completeness();
        $this->runJobWith($fake, $document);

        $suggestion = $document->aiSuggestions()->firstOrFail();

        $this->asOsmAdmin()->postJson("/api/osm-admin/ai-suggestions/{$suggestion->id}/dismiss")->assertOk();
        $this->asOsmAdmin()->postJson("/api/osm-admin/ai-suggestions/{$suggestion->id}/accept")->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_suggestion_dismissed']);
    }
}
