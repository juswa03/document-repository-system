<?php

namespace Tests\Feature\Conformance;

use App\AI\Suggestion;
use App\Models\Document;
use App\Models\DocumentAiSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The seven documented control points, asserted as controls rather than
 * as features. Each already holds via some mechanism elsewhere in the
 * suite; these exist so that removing that mechanism fails loudly here,
 * naming the control it broke.
 */
class ControlPointsTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    /* CP1 — only authorized users may upload, approve, retrieve, archive */

    #[Test]
    public function cp1_each_privileged_action_refuses_the_wrong_role(): void
    {
        $document = $this->createDocument('user@example.test');

        // Upload belongs to a user or an office admin uploading to the
        // repository they help review — not a system admin, who manages
        // the platform rather than filing documents into it.
        $this->asSystemAdmin()
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertForbidden();

        // Approval belongs to the reviewer, not the uploader.
        $this->asUser()->postJson('/api/office-admin/reviews', [
            'kind' => 'document', 'id' => $document->id, 'decision' => 'approved',
        ])->assertForbidden();

        // Archival belongs to the reviewer.
        $this->asUser()
            ->postJson("/api/office-admin/documents/{$document->id}/archive")
            ->assertForbidden();

        // Retrieval of the repository is closed to plain users.
        $this->asUser()->getJson('/api/repository/documents')->assertForbidden();
    }

    /* CP2 — complete metadata before final submission */

    #[Test]
    public function cp2_no_route_reaches_review_without_complete_metadata(): void
    {
        // Direct submission: BR-02 rejects a partial payload.
        $this->asUser()->postJson('/api/dashboard/documents', [
            'title' => 'Missing almost everything',
            'category_id' => $this->categoryId(),
        ])->assertStatus(422);

        // Draft promotion: the same bar applies at the moment the draft
        // becomes a submission, not while it is being written.
        $draftId = $this->asUser()->postJson('/api/dashboard/documents/draft', [
            'title' => 'Half-written draft',
        ])->assertCreated()->json('id');

        $this->asUser()
            ->postJson("/api/dashboard/documents/{$draftId}/submit")
            ->assertStatus(422);

        $this->assertSame(
            Document::STATUS_DRAFT,
            Document::find($draftId)->status,
            'An incomplete draft must not reach the review queue.',
        );
    }

    /* CP3 — AI output must be validated by a human */

    #[Test]
    public function cp3_an_ai_suggestion_changes_nothing_until_a_human_accepts_it(): void
    {
        $document = $this->createDocument('user@example.test', ['title' => 'Original title']);

        $row = DocumentAiSuggestion::fromSuggestion($document, new Suggestion(
            kind: Suggestion::KIND_METADATA,
            data: ['fields' => ['title' => 'AI rewrote this']],
            confidence: 0.99,
            rationale: 'Very confident.',
            model: 'fake',
        ));
        $row->save();

        // Existing at 0.99 confidence must still change nothing.
        $this->assertSame('Original title', $document->fresh()->title);
        $this->assertSame('pending', $row->fresh()->status);

        $this->asOfficeAdmin()
            ->postJson("/api/office-admin/ai-suggestions/{$row->id}/accept")
            ->assertOk();

        $this->assertSame('AI rewrote this', $document->fresh()->title);
        $this->assertNotNull($row->fresh()->resolved_by, 'The accepting human must be recorded.');
    }

    /* CP4 — restricted / confidential require access approval */

    #[Test]
    public function cp4_a_confidential_document_is_closed_without_an_explicit_grant(): void
    {
        $document = $this->createDocument('user@example.test', ['access_level' => 'confidential']);

        // Another ordinary user: same office, but no grant and no ownership.
        $stranger = User::factory()->create([
            'role' => User::ROLE_USER,
            'office_id' => $this->user('user@example.test')->office_id,
        ]);

        $this->actingAsEmail($stranger->email)
            ->get("/api/documents/{$document->id}/file")
            ->assertForbidden();
    }

    /* CP5 — superseded stays traceable but is not the current version */

    #[Test]
    public function cp5_a_superseded_version_is_retrievable_but_not_current(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'revision', 'remarks' => 'Redo the period.',
        ])->assertCreated();

        $this->asUser()->postJson("/api/dashboard/documents/{$id}/resubmit", [
            'reporting_period' => 'Q4 2026',
        ])->assertOk();

        // Traceable: version 1 is still on record.
        $this->assertDatabaseHas('document_versions', ['document_id' => $id, 'version_number' => 1]);

        // Not current: the live record has moved on.
        $this->assertSame(2, Document::find($id)->version_number);
    }

    /* CP6 — the full action set is audited */

    #[Test]
    public function cp6_every_named_lifecycle_action_reaches_the_audit_trail(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'revision', 'remarks' => 'Fix it.',
        ])->assertCreated();

        $this->asUser()->postJson("/api/dashboard/documents/{$id}/resubmit", [
            'reporting_period' => 'Q4 2026',
        ])->assertOk();

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $this->asUser()->get("/api/documents/{$id}/file")->assertOk();
        $this->asOfficeAdmin()->postJson("/api/office-admin/documents/{$id}/archive")->assertOk();

        foreach ([
            'document_uploaded',      // upload
            'review_revision',        // review
            'document_resubmitted',   // revision
            'review_approved',        // approval
            'document_downloaded',    // download
            'document_archived',      // archive
        ] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'subject_id' => $id]);
        }
    }

    /* CP7 — categories, access levels and retention are reviewed on a cadence */

    #[Test]
    public function cp7_each_governed_scope_has_a_due_date_and_a_recorded_review(): void
    {
        $status = $this->asSystemAdmin()
            ->getJson('/api/admin/governance-reviews')
            ->assertOk()->json('status');

        // Exactly the three things the control point names.
        $this->assertEqualsCanonicalizing(
            ['categories', 'access_levels', 'retention'],
            collect($status)->pluck('scope')->all(),
        );

        foreach ($status as $row) {
            $this->assertNotNull($row['next_due_at'], "{$row['scope']} has no next review date.");
            $this->assertGreaterThan(0, $row['cadence_months']);
        }

        // Recording a review leaves a trail.
        $this->asSystemAdmin()->postJson('/api/admin/governance-reviews', [
            'scope' => 'access_levels',
            'notes' => 'Confirmed the category access policy still fits.',
        ])->assertCreated();

        $this->assertDatabaseHas('governance_reviews', ['scope' => 'access_levels']);
    }
}
