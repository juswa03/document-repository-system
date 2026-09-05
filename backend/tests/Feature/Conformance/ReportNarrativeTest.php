<?php

namespace Tests\Feature\Conformance;

use App\AI\Contracts\AiProvider;
use App\AI\Suggestion;
use App\Models\Document;
use App\Models\DocumentAiSuggestion;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiProvider;

/**
 * §F "report generation assistant" — an AI-drafted paragraph over a
 * report's own already-computed figures. Nothing is applied to any
 * record (there's nothing to accept/dismiss), but it still rides the
 * document_ai_suggestions table for spend accounting (BR-03's cap).
 */
class ReportNarrativeTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function narrative(): Suggestion
    {
        return new Suggestion(
            kind: Suggestion::KIND_REPORT_NARRATIVE,
            data: [
                'narrative' => 'One document is well past its suggested lead time.',
                'key_points' => ['DOC is the most overdue item.'],
            ],
            confidence: 0.8,
            rationale: 'Drafted from the report figures.',
            model: 'claude-haiku-4-5',
            inputTokens: 500,
            outputTokens: 120,
        );
    }

    private function bind(FakeAiProvider $fake): void
    {
        $this->app->instance(AiProvider::class, $fake);
    }

    public function test_a_narratable_report_returns_a_drafted_narrative(): void
    {
        $doc = $this->createDocument('user@example.test', ['status' => 'pending']);
        $doc->update(['submitted_at' => now()->subDays(20)]);

        $fake = new FakeAiProvider;
        $fake->reportNarrative = $this->narrative();
        $this->bind($fake);

        $response = $this->asOsmAdmin()
            ->postJson('/api/reports/document-aging/narrative')
            ->assertOk();

        $response->assertJsonPath('narrative', 'One document is well past its suggested lead time.')
            ->assertJsonPath('confidence', 0.8)
            ->assertJsonPath('model', 'claude-haiku-4-5');

        $this->assertSame(1, $fake->narrateReportCalls);
        $this->assertNotNull($fake->lastNarrativePayload);
        $this->assertArrayHasKey('summary', $fake->lastNarrativePayload);
        $this->assertArrayHasKey('sample_rows', $fake->lastNarrativePayload);
    }

    public function test_it_is_recorded_for_spend_accounting_without_a_document(): void
    {
        $fake = new FakeAiProvider;
        $fake->reportNarrative = $this->narrative();
        $this->bind($fake);

        $this->asSystemAdmin()->postJson('/api/reports/document-aging/narrative')->assertOk();

        $this->assertDatabaseHas('document_ai_suggestions', [
            'document_id' => null,
            'report_key' => 'document-aging',
            'kind' => 'report_narrative',
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_report_narrative_generated']);
    }

    public function test_a_report_with_no_narrative_value_is_rejected(): void
    {
        $this->bind(new FakeAiProvider);

        $this->asOsmAdmin()
            ->postJson('/api/reports/document-inventory/narrative')
            ->assertStatus(422);
    }

    public function test_the_capability_toggle_turns_it_off(): void
    {
        SystemSetting::current()->update(['ai_capabilities' => ['classification']]);
        $this->bind(new FakeAiProvider);

        $this->asOsmAdmin()
            ->postJson('/api/reports/document-aging/narrative')
            ->assertStatus(422);
    }

    public function test_an_unconfigured_provider_is_rejected(): void
    {
        $fake = new FakeAiProvider;
        $fake->configured = false;
        $this->bind($fake);

        $this->asOsmAdmin()
            ->postJson('/api/reports/document-aging/narrative')
            ->assertStatus(422);
    }

    public function test_the_monthly_spend_cap_blocks_further_narratives(): void
    {
        SystemSetting::current()->update(['ai_monthly_cap_usd' => 1]);
        DocumentAiSuggestion::create([
            'document_id' => null,
            'report_key' => 'document-aging',
            'kind' => 'report_narrative',
            'data' => [],
            'confidence' => 0.9,
            'model' => 'claude-haiku-4-5',
            'cost_usd' => 5,
            'status' => 'accepted',
            'created_at' => now(),
        ]);

        $fake = new FakeAiProvider;
        $fake->reportNarrative = $this->narrative();
        $this->bind($fake);

        $this->asOsmAdmin()
            ->postJson('/api/reports/document-aging/narrative')
            ->assertStatus(422);

        $this->assertSame(0, $fake->narrateReportCalls);
    }

    public function test_a_user_cannot_request_a_narrative(): void
    {
        $this->bind(new FakeAiProvider);

        $this->asUser()
            ->postJson('/api/reports/document-aging/narrative')
            ->assertForbidden();
    }
}
