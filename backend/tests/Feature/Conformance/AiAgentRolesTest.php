<?php

namespace Tests\Feature\Conformance;

use App\AI\Contracts\AiProvider;
use App\AI\Suggestion;
use App\Models\Document;
use App\Models\DocumentAiSuggestion;
use App\Models\Office;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiProvider;

/**
 * The documented AI agent roles (§F). Most are covered by their own
 * suites; this one locks in the two that were incomplete:
 *
 *   metadata extractor    — must cover title and office, not only
 *                           period / keywords / description / date
 *   audit trail assistant — must be able to summarise user activity and
 *                           document movement, not just list rows
 */
class AiAgentRolesTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function metadataSuggestion(array $fields): Suggestion
    {
        return new Suggestion(
            kind: Suggestion::KIND_METADATA,
            data: ['fields' => $fields],
            confidence: 0.9,
            rationale: 'Tidier values proposed.',
            model: 'fake',
        );
    }

    /* ---- Metadata extractor: title and office ---- */

    #[Test]
    public function accepting_a_metadata_suggestion_can_correct_the_title(): void
    {
        $document = $this->createDocument('user@example.test', ['title' => 'untitled scan 3']);

        $row = DocumentAiSuggestion::fromSuggestion($document, $this->metadataSuggestion([
            'title' => 'Annual Procurement Plan 2026',
        ]));
        $row->save();

        $this->asOfficeAdmin()
            ->postJson("/api/office-admin/ai-suggestions/{$row->id}/accept")
            ->assertOk();

        $this->assertSame('Annual Procurement Plan 2026', $document->fresh()->title);
    }

    #[Test]
    public function accepting_a_metadata_suggestion_can_correct_the_owning_office(): void
    {
        $document = $this->createDocument('user@example.test');
        $budget = Office::where('office_code', 'BO')->firstOrFail();

        $this->assertNotSame($budget->id, $document->office_id);

        $row = DocumentAiSuggestion::fromSuggestion($document, $this->metadataSuggestion([
            'office' => $budget->office_name,
        ]));
        $row->save();

        $this->asOfficeAdmin()
            ->postJson("/api/office-admin/ai-suggestions/{$row->id}/accept")
            ->assertOk();

        $this->assertSame($budget->id, $document->fresh()->office_id);
    }

    #[Test]
    public function an_office_the_extractor_invented_is_ignored_rather_than_guessed_at(): void
    {
        $document = $this->createDocument('user@example.test');
        $originalOffice = $document->office_id;

        $row = DocumentAiSuggestion::fromSuggestion($document, $this->metadataSuggestion([
            'office' => 'Office of Things That Do Not Exist',
        ]));
        $row->save();

        $this->asOfficeAdmin()
            ->postJson("/api/office-admin/ai-suggestions/{$row->id}/accept")
            ->assertOk();

        $this->assertSame($originalOffice, $document->fresh()->office_id);
    }

    /* ---- Audit trail assistant ---- */

    #[Test]
    public function the_audit_trail_offers_an_ai_narrative(): void
    {
        $fake = new FakeAiProvider;
        $fake->reportNarrative = new Suggestion(
            kind: Suggestion::KIND_REPORT_NARRATIVE,
            data: [
                'narrative' => 'Activity was concentrated in document uploads and reviews.',
                'key_points' => ['Uploads dominated the period.'],
            ],
            confidence: 0.8,
            rationale: '',
            model: 'fake',
        );
        $this->app->instance(AiProvider::class, $fake);

        // Generate some real movement to summarise.
        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated();

        $this->asSystemAdmin()
            ->postJson('/api/reports/audit-trail/narrative')
            ->assertOk()
            ->assertJsonStructure(['narrative', 'key_points', 'confidence', 'model']);

        $this->assertSame(1, $fake->narrateReportCalls);
    }

    #[Test]
    public function the_audit_narrative_is_given_activity_aggregates_not_just_a_row_count(): void
    {
        $fake = new FakeAiProvider;
        $fake->reportNarrative = new Suggestion(
            kind: Suggestion::KIND_REPORT_NARRATIVE,
            data: ['narrative' => 'n/a', 'key_points' => []],
            confidence: 0.5,
            rationale: '',
            model: 'fake',
        );
        $this->app->instance(AiProvider::class, $fake);

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated();

        $this->asSystemAdmin()->postJson('/api/reports/audit-trail/narrative')->assertOk();

        $summary = $fake->lastNarrativePayload['summary'] ?? [];

        // "Summarizes user activities and document movement history" needs
        // more than a total to work from.
        $this->assertArrayHasKey('by_action', $summary);
        $this->assertArrayHasKey('most_active_users', $summary);
        $this->assertArrayHasKey('document_movement', $summary);
        $this->assertGreaterThan(0, $summary['distinct_actors']);
        $this->assertArrayHasKey('document_uploaded', $summary['document_movement']);
    }

    #[Test]
    public function the_audit_narrative_respects_the_date_window_it_is_given(): void
    {
        $fake = new FakeAiProvider;
        $fake->reportNarrative = new Suggestion(
            kind: Suggestion::KIND_REPORT_NARRATIVE,
            data: ['narrative' => 'n/a', 'key_points' => []],
            confidence: 0.5,
            rationale: '',
            model: 'fake',
        );
        $this->app->instance(AiProvider::class, $fake);

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated();

        // A window that excludes everything must narrate nothing, rather
        // than silently describing all of history.
        $this->asSystemAdmin()->postJson('/api/reports/audit-trail/narrative', [
            'date_from' => now()->addYear()->toDateString(),
            'date_to' => now()->addYear()->addDay()->toDateString(),
        ])->assertOk();

        $this->assertSame(0, $fake->lastNarrativePayload['summary']['total_events']);
    }

    #[Test]
    public function a_raw_list_report_still_offers_no_narrative(): void
    {
        // Enabling audit-trail must not have opened the door for every
        // report — the retrieval log has nothing to add over its rows.
        $this->asSystemAdmin()
            ->postJson('/api/reports/retrieval-log/narrative')
            ->assertStatus(422);
    }
}
