<?php

namespace Tests\Feature\Conformance;

use App\Models\Category;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The three end-to-end process-flow steps that had no implementation:
 *
 *  step 5  — duplicate AND version detection before submission
 *  step 6  — the UPLOADER (not just the reviewer) sees AI/dedup findings
 *            and confirms, edits, or overrides them
 *  step 9  — the system validates access level against the document's
 *            category, rather than leaving it to a reviewer tick-box
 */
class ProcessFlowGapsTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function categoryCode(string $code): int
    {
        return Category::where('category_code', $code)->value('id');
    }

    /* ---- Step 9: access level is validated against the category ---- */

    #[Test]
    public function an_access_level_the_category_forbids_is_refused_at_submission(): void
    {
        // PERF (performance monitoring — OPCR/IPCR) is restricted or
        // confidential only; "public" must not be accepted.
        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'category_id' => $this->categoryCode('PERF'),
            'access_level' => 'public',
        ]))->assertStatus(422)->assertJsonValidationErrors(['access_level']);

        $this->assertSame(0, Document::count(), 'Nothing may be stored when the classification is refused.');
    }

    #[Test]
    public function an_access_level_the_category_permits_is_accepted(): void
    {
        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'category_id' => $this->categoryCode('PERF'),
            'access_level' => 'confidential',
        ]))->assertCreated();

        $this->assertSame('confidential', Document::first()->access_level);
    }

    #[Test]
    public function a_reviewer_cannot_downgrade_access_below_what_the_category_allows(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'category_id' => $this->categoryCode('PERF'),
            'access_level' => 'confidential',
        ]))->assertCreated()->json('id');

        // The reviewer confirms the level at approval (BR-08) but the
        // category policy still binds them.
        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'document',
            'id' => $id,
            'decision' => 'approved',
            'access_level' => 'public',
            'checklist' => $this->completeChecklist(),
        ])->assertStatus(422)->assertJsonValidationErrors(['access_level']);

        $this->assertSame('pending', Document::find($id)->status);
        $this->assertSame('confidential', Document::find($id)->access_level);
    }

    #[Test]
    public function approval_revalidates_a_level_that_became_invalid_after_reclassification(): void
    {
        // "Validate category and access level" gates approval in the flow,
        // so it must run on every approval — not only when the reviewer
        // sends a new level. A document can become non-compliant without
        // its access_level changing: accepting an AI classification moves
        // it to a category whose policy forbids the level it already has.
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'category_id' => $this->categoryCode('TMPL'),   // allows public
            'access_level' => 'public',
        ]))->assertCreated()->json('id');

        // Reclassified into a category that permits neither public nor internal.
        Document::find($id)->update(['category_id' => $this->categoryCode('PERF')]);

        // The reviewer approves without touching the access level.
        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'document',
            'id' => $id,
            'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertStatus(422)->assertJsonValidationErrors(['access_level']);

        $this->assertSame('pending', Document::find($id)->status);
    }

    #[Test]
    public function returning_a_submission_is_never_blocked_by_the_access_policy(): void
    {
        // The "No" branch must always stay open: a reviewer has to be able
        // to bounce a non-compliant document back to the uploader, which is
        // exactly how the uploader is able to fix it.
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'category_id' => $this->categoryCode('TMPL'),
            'access_level' => 'public',
        ]))->assertCreated()->json('id');

        Document::find($id)->update(['category_id' => $this->categoryCode('PERF')]);

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'document',
            'id' => $id,
            'decision' => 'revision',
            'remarks' => 'Reclassified — please set an appropriate access level.',
        ])->assertCreated();

        $this->assertSame('revision', Document::find($id)->status);
    }

    #[Test]
    public function a_category_with_no_policy_entry_accepts_any_level(): void
    {
        // ADMIN has no explicit access_policy row, so it falls back to "*"
        // — adding a category must never start silently rejecting uploads.
        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'category_id' => $this->categoryCode('ADMIN'),
            'access_level' => 'public',
        ]))->assertCreated();
    }

    /* ---- Steps 5 & 6: the uploader's pre-submission check ---- */

    #[Test]
    public function preflight_tells_the_uploader_a_file_is_a_duplicate_before_they_submit(): void
    {
        $file = UploadedFile::fake()->create('same.pdf', 10, 'application/pdf');

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload(['file' => $file]))
            ->assertCreated();

        $this->asUser()->post('/api/dashboard/documents/preflight', [
            'file' => UploadedFile::fake()->create('same.pdf', 10, 'application/pdf'),
            'title' => 'Board minutes',
            'category_id' => $this->categoryId(),
        ])
            ->assertOk()
            ->assertJsonPath('duplicate_check.verdict', 'duplicate')
            ->assertJsonPath('duplicate_check.signal', 'content_hash');
    }

    #[Test]
    public function preflight_reports_a_clean_file_as_new(): void
    {
        $this->asUser()->post('/api/dashboard/documents/preflight', [
            'file' => UploadedFile::fake()->create('brand-new.pdf', 9, 'application/pdf'),
            'title' => 'Something nobody has filed before',
            'category_id' => $this->categoryId(),
        ])
            ->assertOk()
            ->assertJsonPath('duplicate_check.verdict', 'new');
    }

    #[Test]
    public function preflight_identifies_a_likely_new_version_from_a_matching_title(): void
    {
        $existing = $this->createDocument('user@example.test', [
            'title' => 'Annual Accomplishment Report 2026',
        ]);
        $existing->update(['status' => 'approved']);

        $this->asUser()->post('/api/dashboard/documents/preflight', [
            'file' => UploadedFile::fake()->create('report-v2.pdf', 11, 'application/pdf'),
            // Same document, later revision — the version markers must not
            // stop it matching.
            'title' => 'Annual Accomplishment Report 2026 v2 (final)',
            'category_id' => $existing->category_id,
        ])
            ->assertOk()
            ->assertJsonPath('duplicate_check.verdict', 'new_version')
            ->assertJsonPath('duplicate_check.match.ref', $existing->tracking_no);
    }

    #[Test]
    public function preflight_tells_the_uploader_which_access_levels_the_category_allows(): void
    {
        $this->asUser()->post('/api/dashboard/documents/preflight', [
            'file' => UploadedFile::fake()->create('perf.pdf', 9, 'application/pdf'),
            'title' => 'IPCR 2026',
            'category_id' => $this->categoryCode('PERF'),
            'access_level' => 'public',
        ])
            ->assertOk()
            ->assertJsonPath('access_policy.chosen_is_allowed', false)
            ->assertJsonPath('access_policy.allowed', ['restricted', 'confidential']);
    }

    #[Test]
    public function preflight_does_not_persist_anything(): void
    {
        $this->asUser()->post('/api/dashboard/documents/preflight', [
            'file' => UploadedFile::fake()->create('draft.pdf', 9, 'application/pdf'),
            'title' => 'Just checking first',
            'category_id' => $this->categoryId(),
        ])->assertOk();

        $this->assertSame(0, Document::count(), 'A preflight check must not create a document.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'submission_preflight']);
    }

    #[Test]
    public function an_office_admin_can_use_the_uploader_preflight_but_a_system_admin_cannot(): void
    {
        // Office admins upload documents too (labeled to their own office),
        // so they get the same pre-submission check a regular user does.
        $this->asOfficeAdmin()->post('/api/dashboard/documents/preflight', [
            'file' => UploadedFile::fake()->create('x.pdf', 9, 'application/pdf'),
        ])->assertOk();

        // A system admin manages the platform rather than filing documents
        // into it, so this stays closed to that role.
        $this->asSystemAdmin()->post('/api/dashboard/documents/preflight', [
            'file' => UploadedFile::fake()->create('x.pdf', 9, 'application/pdf'),
        ])->assertForbidden();
    }

    /* ---- Step 6 outcome: confirming "new version" files into the chain ---- */

    #[Test]
    public function confirming_a_new_version_continues_the_existing_document(): void
    {
        $existing = $this->createDocument('user@example.test', ['title' => 'Operational Plan 2026']);
        $existing->update(['status' => 'approved']);
        $originalRef = $existing->tracking_no;

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'title' => 'Operational Plan 2026 (revised)',
            'category_id' => $existing->category_id,
            'supersedes_id' => $existing->id,
        ]))->assertCreated()->assertJsonPath('ref', $originalRef);

        // One record, not two — and it is now version 2.
        $this->assertSame(1, Document::count());

        $fresh = Document::find($existing->id);
        $this->assertSame(2, $fresh->version_number);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('Operational Plan 2026 (revised)', $fresh->title);

        // Version 1 is retrievable.
        $this->assertDatabaseHas('document_versions', [
            'document_id' => $existing->id,
            'version_number' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_versioned']);
    }

    #[Test]
    public function a_new_version_cannot_be_filed_against_someone_elses_document(): void
    {
        // Owned by another user, in another office — outside both halves
        // of the "yours or your office's" visibility rule.
        $otherOffice = \App\Models\Office::create([
            'office_name' => 'Some Other Office',
            'office_code' => 'OTHER',
        ]);
        $stranger = \App\Models\User::factory()->create([
            'role' => \App\Models\User::ROLE_USER,
            'office_id' => $otherOffice->id,
        ]);

        $foreign = $this->createDocument('user@example.test', ['title' => 'Not yours']);
        $foreign->update([
            'status' => 'approved',
            'office_id' => $otherOffice->id,
            'uploaded_by' => $stranger->id,
        ]);

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'supersedes_id' => $foreign->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['supersedes_id']);
    }

    #[Test]
    public function a_new_version_cannot_be_filed_while_the_original_is_still_pending(): void
    {
        $pending = $this->createDocument('user@example.test', ['title' => 'Still in the queue']);

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'supersedes_id' => $pending->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['supersedes_id']);
    }
}
