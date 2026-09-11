<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The documented status vocabulary, which spans three independent
 * dimensions rather than one column:
 *
 *   review     draft → pending (Submitted / For Review) → revision
 *                    → approved | rejected
 *   retention  active → superseded → archived → disposed
 *   access     public | internal | restricted | confidential
 *
 * A document carries one value from each at all times.
 */
class StatusLabelsTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function draftPayload(array $overrides = []): array
    {
        return array_merge(['title' => 'Half-written plan'], $overrides);
    }

    /* ---- Draft: encoded but not yet submitted ---- */

    #[Test]
    public function a_draft_can_be_saved_with_only_a_title(): void
    {
        $this->asUser()->postJson('/api/dashboard/documents/draft', $this->draftPayload())
            ->assertCreated()
            ->assertJsonPath('status', 'draft');

        $draft = Document::first();
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->tracking_no, 'A draft must not consume a tracking number.');
        $this->assertNull($draft->submitted_at, 'A draft has not been submitted.');
    }

    #[Test]
    public function a_draft_is_invisible_to_reviewers(): void
    {
        $this->asUser()->postJson('/api/dashboard/documents/draft', $this->draftPayload())
            ->assertCreated();

        // Not in the queue…
        $queue = $this->asOfficeAdmin()->getJson('/api/office-admin/queue')->assertOk()->json('data');
        $this->assertSame([], $queue);

        // …not in the decided list…
        $decided = $this->asOfficeAdmin()->getJson('/api/office-admin/decided')->assertOk()->json('data');
        $this->assertSame([], $decided);

        // …not counted as awaiting review…
        $this->asOfficeAdmin()->getJson('/api/office-admin/stats')
            ->assertOk()
            ->assertJsonPath('awaiting_review', 0);

        // …and not in the repository.
        $repo = $this->asOfficeAdmin()->getJson('/api/repository/documents')->assertOk()->json('data');
        $this->assertSame([], $repo);
    }

    #[Test]
    public function a_draft_is_visible_to_its_own_author(): void
    {
        $this->asUser()->postJson('/api/dashboard/documents/draft', $this->draftPayload())
            ->assertCreated();

        $mine = $this->asUser()->getJson('/api/dashboard/submissions')->assertOk()->json();

        $this->assertCount(1, $mine);
        $this->assertSame('draft', $mine[0]['status']);
    }

    #[Test]
    public function a_draft_can_be_edited_and_then_discarded(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents/draft', $this->draftPayload())
            ->assertCreated()->json('id');

        $this->asUser()->postJson("/api/dashboard/documents/{$id}/draft", [
            'title' => 'Renamed while still a draft',
        ])->assertOk()->assertJsonPath('title', 'Renamed while still a draft');

        $this->asUser()->deleteJson("/api/dashboard/documents/{$id}/draft")->assertOk();

        $this->assertSame(0, Document::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_draft_discarded']);
    }

    #[Test]
    public function an_incomplete_draft_cannot_be_submitted(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents/draft', $this->draftPayload())
            ->assertCreated()->json('id');

        // BR-02 bites at the submit boundary, not while drafting.
        $this->asUser()->postJson("/api/dashboard/documents/{$id}/submit", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id', 'document_date', 'file_path']);

        $this->assertSame('draft', Document::find($id)->status);
    }

    #[Test]
    public function a_completed_draft_is_submitted_and_gets_a_tracking_number(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents/draft', $this->draftPayload())
            ->assertCreated()->json('id');

        $this->asUser()->post("/api/dashboard/documents/{$id}/submit", [
            'title' => 'Board minutes',
            'category_id' => $this->categoryId(),
            'document_type' => 'minutes',
            'document_date' => now()->subDays(3)->toDateString(),
            'reporting_period' => 'Q3 2026',
            'access_level' => 'internal',
            'keywords' => 'board, minutes',
            'description' => 'Minutes of the Q3 2026 board meeting: priorities and approvals.',
            'file' => UploadedFile::fake()->create('minutes.pdf', 12, 'application/pdf'),
        ])->assertOk()->assertJsonPath('status', 'pending');

        $document = Document::find($id);
        $this->assertNotNull($document->tracking_no, 'Submission issues the tracking number.');
        $this->assertNotNull($document->submitted_at);

        // Now it is in the reviewer's queue.
        $queue = $this->asOfficeAdmin()->getJson('/api/office-admin/queue')->assertOk()->json('data');
        $this->assertCount(1, $queue);
    }

    #[Test]
    public function a_draft_belongs_only_to_its_author(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents/draft', $this->draftPayload())
            ->assertCreated()->json('id');

        // Another user's draft is not theirs to submit or discard.
        $this->actingAsEmail('system.admin@example.test')
            ->deleteJson("/api/dashboard/documents/{$id}/draft")
            ->assertForbidden();
    }

    #[Test]
    public function a_draft_is_never_offered_as_a_duplicate_or_version_match(): void
    {
        // A draft is not "already on file", so it must not trigger either
        // signal in the pre-submission check.
        $this->asUser()->postJson('/api/dashboard/documents/draft', [
            'title' => 'Annual Accomplishment Report 2026',
        ])->assertCreated();

        $this->asUser()->post('/api/dashboard/documents/preflight', [
            'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
            'title' => 'Annual Accomplishment Report 2026',
            'category_id' => $this->categoryId(),
        ])->assertOk()->assertJsonPath('duplicate_check.verdict', 'new');
    }

    #[Test]
    public function a_draft_carries_the_fields_the_drafts_screen_renders(): void
    {
        $id = $this->asUser()->post('/api/dashboard/documents/draft', [
            'title' => 'Partly filled in',
            'category_id' => $this->categoryId(),
            'file' => UploadedFile::fake()->create('wip.pdf', 8, 'application/pdf'),
        ])->assertCreated()->json('id');

        $draft = collect($this->asUser()->getJson('/api/dashboard/submissions')->json())
            ->firstWhere('id', $id);

        // The Drafts table shows title / category / attachment, and the
        // resume modal repopulates from category_id + file_format.
        $this->assertSame('draft', $draft['status']);
        $this->assertNotNull($draft['category']);
        $this->assertNotNull($draft['category_id']);
        $this->assertSame('pdf', $draft['file_format']);
        $this->assertNull($draft['ref'], 'A draft has no tracking number to show.');
        $this->assertFalse($draft['overdue'], 'A draft can never be overdue — it was never submitted.');
    }

    #[Test]
    public function a_draft_keeps_its_file_when_resubmitted_without_a_new_one(): void
    {
        // The modal leaves the file input empty when resuming a draft that
        // already has one; submitting must not wipe it.
        $id = $this->asUser()->post('/api/dashboard/documents/draft', [
            'title' => 'Has a file already',
            'file' => UploadedFile::fake()->create('kept.pdf', 8, 'application/pdf'),
        ])->assertCreated()->json('id');

        $storedPath = Document::find($id)->file_path;
        $this->assertNotNull($storedPath);

        $this->asUser()->post("/api/dashboard/documents/{$id}/submit", [
            'title' => 'Has a file already',
            'category_id' => $this->categoryId(),
            'document_type' => 'report',
            'document_date' => now()->subDay()->toDateString(),
            'reporting_period' => 'Q3 2026',
            'access_level' => 'internal',
            'keywords' => 'kept, file',
            'description' => 'Submitting without re-attaching the file that is already stored.',
        ])->assertOk();

        $this->assertSame($storedPath, Document::find($id)->file_path);
    }

    /* ---- Submitted vs For Review ---- */

    #[Test]
    public function a_pending_document_reads_as_submitted_until_a_reviewer_is_assigned(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');

        // Unassigned — nobody has picked it up.
        $this->assertSame('submitted', Document::find($id)->reviewStage());

        $this->asOfficeAdmin()->postJson("/api/office-admin/documents/{$id}/assign", [
            'assignee_id' => $this->userId('office.admin@example.test'),
        ])->assertOk();

        // Claimed — now genuinely under review.
        $this->assertSame('for_review', Document::find($id)->reviewStage());
    }

    #[Test]
    public function review_stage_falls_through_to_the_real_status_once_decided(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'document',
            'id' => $id,
            'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $this->assertSame('approved', Document::find($id)->reviewStage());
    }

    #[Test]
    public function the_submissions_payload_exposes_the_review_stage(): void
    {
        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())->assertCreated();

        $this->asUser()->getJson('/api/dashboard/submissions')
            ->assertOk()
            ->assertJsonPath('0.review_stage', 'submitted');
    }

    /* ---- The three dimensions stay independent ---- */

    #[Test]
    public function retention_and_access_are_tracked_separately_from_review_status(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'access_level' => 'restricted',
        ]))->assertCreated()->json('id');

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'document',
            'id' => $id,
            'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $document = Document::find($id);

        // One value from each dimension, all at once.
        $this->assertSame('approved', $document->status);
        $this->assertSame('active', $document->retention_status);
        $this->assertSame('restricted', $document->access_level);

        // Archiving changes retention only — the review verdict stands.
        $this->asOfficeAdmin()->postJson("/api/office-admin/documents/{$id}/archive", [
            'reason' => 'Superseded by the 2027 edition.',
        ])->assertOk();

        $document->refresh();
        $this->assertSame('archived', $document->retention_status);
        $this->assertSame('approved', $document->status, 'Archiving must not rewrite the review verdict.');
    }
}
