<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tasks F-06 through F-10 and F-meta — the submit / review / resubmit spine.
 *
 * Mix of green (locks current correct behaviour) and red (Phase 1 / 2 / 3 / 5
 * targets). Anchors: PF-03, PF-04, PF-06, PF-11, BR-02, E-8, F-07..F-10.
 */
class SubmissionWorkflowTest extends ConformanceTestCase
{
    private function fakeDisks(): void
    {
        Storage::fake(Document::DISK);
        Storage::fake('public');
    }

    private function uploadAsUser(array $overrides = []): TestResponse
    {
        return $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload($overrides));
    }

    private function reviewAsOsm(int $id, string $decision, ?string $remarks = null): TestResponse
    {
        return $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', array_filter([
            'kind' => 'document',
            'id' => $id,
            'decision' => $decision,
            'remarks' => $remarks,
            'checklist' => $this->completeChecklist(),
        ], fn ($v) => $v !== null));
    }

    /* ---- GREEN: current correct behaviour ---- */

    public function test_a_complete_document_submission_is_accepted_and_gets_a_tracking_number(): void
    {
        $this->fakeDisks();
        $res = $this->uploadAsUser()->assertCreated()->assertJsonPath('status', 'pending');

        $this->assertIsString($res->json('ref'));
        $this->assertStringContainsString('-', $res->json('ref'));
    }

    public function test_a_reviewer_cannot_review_their_own_submission(): void
    {
        $this->fakeDisks();
        $id = $this->asOsmAdmin()
            ->postJson('/api/dashboard/documents', $this->documentPayload(['title' => 'OSM own doc']))
            ->assertCreated()->json('id');

        $this->reviewAsOsm($id, 'approved')->assertStatus(422);
    }

    public function test_disallowed_file_types_and_oversize_files_are_rejected(): void
    {
        $this->fakeDisks();
        // FR-03 — PDF and Word only.
        $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        $this->uploadAsUser(['file' => UploadedFile::fake()->create('note.txt', 2, 'text/plain')])->assertStatus(422);
        $this->uploadAsUser(['file' => UploadedFile::fake()->image('scan.png')])->assertStatus(422);
        $this->uploadAsUser(['file' => UploadedFile::fake()->create('data.xlsx', 4, $xlsx)])->assertStatus(422);
        $this->uploadAsUser(['file' => UploadedFile::fake()->create('big.pdf', 25000, 'application/pdf')])->assertStatus(422);
        $this->uploadAsUser(['file' => UploadedFile::fake()->create('memo.docx', 8, $docx)])->assertCreated();
    }

    /* ---- Metadata completeness (BR-02 / PF-04, Phase 2a) ---- */

    public function test_submission_requires_the_documented_minimum_metadata(): void
    {
        $this->fakeDisks();

        // Title + category + file only — no document type, date, reporting
        // period, access level, keywords or description. Per BR-02 / PF-04
        // this must be rejected as incomplete.
        $this->asUser()->postJson('/api/dashboard/documents', [
            'title' => 'Board minutes',
            'category_id' => $this->categoryId(),
            'file' => UploadedFile::fake()->create('minutes.pdf', 12, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors([
            'document_type', 'document_date', 'reporting_period',
            'access_level', 'keywords', 'description',
        ]);
    }

    /* ---- RED: Phase 5 (duplicate detection) ---- */

    #[Group('remediation-target')]
    public function test_an_identical_re_upload_is_flagged_as_a_possible_duplicate(): void
    {
        $this->fakeDisks();
        $file = UploadedFile::fake()->create('same.pdf', 10, 'application/pdf');

        $this->uploadAsUser(['file' => $file])->assertCreated();
        $second = $this->uploadAsUser(['file' => $file]);

        $this->assertNotNull(
            $second->json('duplicate') ?? $second->json('duplicate_of'),
            'A duplicate upload should surface a duplicate-detection result (PF-06 / AI-03).'
        );
    }

    /* ---- Review state machine (decision 0.1, Phase 1.6) ---- */

    public function test_a_settled_document_cannot_be_reviewed_again(): void
    {
        $this->fakeDisks();
        $id = $this->uploadAsUser()->assertCreated()->json('id');

        $this->reviewAsOsm($id, 'approved')->assertCreated();
        $this->reviewAsOsm($id, 'rejected', 'changed mind')->assertStatus(422);

        $this->assertSame('approved', Document::find($id)->status);
    }

    public function test_a_rejected_submission_is_terminal(): void
    {
        $this->fakeDisks();
        $id = $this->uploadAsUser()->assertCreated()->json('id');

        $this->reviewAsOsm($id, 'rejected', 'Out of scope for this repository.')->assertCreated();

        // No resubmission…
        $this->asUser()->postJson("/api/dashboard/documents/{$id}/resubmit", [
            'title' => 'Please reconsider',
            'category_id' => $this->categoryId(),
        ])->assertStatus(422);

        // …and no further review.
        $this->reviewAsOsm($id, 'approved')->assertStatus(422);

        $this->assertSame('rejected', Document::find($id)->status);
    }

    /* ---- Resubmit no longer destroys the prior file (Phase 3.1) ---- */

    public function test_resubmitting_with_a_new_file_keeps_the_previous_file(): void
    {
        $this->fakeDisks();
        $id = $this->uploadAsUser()->assertCreated()->json('id');

        $this->reviewAsOsm($id, 'revision', 'fix the period')->assertCreated();
        $original = Document::find($id)->file_path;

        $this->asUser()->postJson("/api/dashboard/documents/{$id}/resubmit", [
            'title' => 'Board minutes (rev 2)',
            'category_id' => $this->categoryId(),
            'file' => UploadedFile::fake()->create('minutes-v2.pdf', 12, 'application/pdf'),
        ])->assertOk();

        $current = Document::find($id)->file_path;
        $this->assertNotSame($original, $current);

        $this->assertTrue(
            Storage::disk(Document::DISK)->exists($original),
            'The previous version file must be retained after resubmission (FR-11, PF-17).'
        );
        $this->assertTrue(
            Storage::disk(Document::DISK)->exists($current),
            'The new file should be stored on the private disk.'
        );

        // The superseded path is recoverable from the audit trail until
        // the Phase 3 version model records it structurally.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_resubmitted',
            'subject_id' => $id,
        ]);
        $this->assertSame(
            $original,
            \App\Models\AuditLog::where('action', 'document_resubmitted')->where('subject_id', $id)->value('properties')['superseded_file']
        );
    }

    public function test_resubmitting_without_a_new_file_keeps_the_existing_one(): void
    {
        $this->fakeDisks();
        $id = $this->uploadAsUser()->assertCreated()->json('id');
        $this->reviewAsOsm($id, 'revision', 'just fix the title')->assertCreated();
        $original = Document::find($id)->file_path;

        $this->asUser()->postJson("/api/dashboard/documents/{$id}/resubmit", [
            'title' => 'Board minutes (retitled)',
            'category_id' => $this->categoryId(),
        ])->assertOk();

        $this->assertSame($original, Document::find($id)->file_path);
        $this->assertTrue(Storage::disk(Document::DISK)->exists($original));
    }
}
