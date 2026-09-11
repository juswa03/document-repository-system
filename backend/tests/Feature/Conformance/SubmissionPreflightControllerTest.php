<?php

namespace Tests\Feature\Conformance;

use App\Dedup\SubmissionPreflight;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Steps 5 & 6 of the process flow — the pre-submission duplicate/new-version
 * check the uploader sees before anything is saved (previously untested as
 * its own controller, only indirectly via DuplicateDetectionTest's
 * post-submission behaviour).
 */
class SubmissionPreflightControllerTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    /** A real, caller-controlled PDF — fake()->create() writes empty bytes, which would hash alike for every call. */
    private function pdf(string $name, string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF\n{$marker}");
    }

    #[Test]
    public function a_brand_new_file_is_verdict_new(): void
    {
        $this->asUser()->post('/api/dashboard/documents/preflight', [
            'file' => $this->pdf('fresh.pdf', 'nothing-like-this-exists-yet'),
            'title' => 'A completely new report',
        ])->assertOk()
            ->assertJsonPath('duplicate_check.verdict', SubmissionPreflight::VERDICT_NEW)
            ->assertJsonPath('duplicate_check.match', null);
    }

    #[Test]
    public function an_exact_byte_for_byte_match_is_verdict_duplicate(): void
    {
        // Duplicate matching keys off the stored content_hash column, not
        // a live re-hash of the file on disk — so the fixture's hash must
        // match the bytes being uploaded here.
        $content = "%PDF-1.4\n%%EOF\nidentical-bytes";
        $existing = $this->createDocument('user@example.test', [
            'status' => 'approved',
            'retention_status' => 'active',
            'content_hash' => hash('sha256', $content),
        ]);

        $this->asUser()->post('/api/dashboard/documents/preflight', [
            'file' => $this->pdf('dup.pdf', 'identical-bytes'),
        ])->assertOk()
            ->assertJsonPath('duplicate_check.verdict', SubmissionPreflight::VERDICT_DUPLICATE)
            ->assertJsonPath('duplicate_check.match.ref', $existing->tracking_no)
            ->assertJsonPath('duplicate_check.signal', 'content_hash');
    }

    #[Test]
    public function a_closely_matching_title_is_verdict_new_version(): void
    {
        $this->createDocument('user@example.test', [
            'title' => 'Annual Accreditation Report 2026',
            'status' => 'approved',
            'retention_status' => 'active',
        ]);

        $this->asUser()->post('/api/dashboard/documents/preflight', [
            'file' => $this->pdf('v2.pdf', 'different-content-entirely'),
            'title' => 'Annual Accreditation Report 2026 (Revised)',
        ])->assertOk()
            ->assertJsonPath('duplicate_check.verdict', SubmissionPreflight::VERDICT_NEW_VERSION)
            ->assertJsonPath('duplicate_check.signal', 'title_similarity');
    }

    #[Test]
    public function the_access_policy_block_reflects_the_chosen_categorys_rules(): void
    {
        $perfCategoryId = \App\Models\Category::where('category_code', 'PERF')->value('id');

        $this->asUser()->post('/api/dashboard/documents/preflight', [
            'file' => $this->pdf('policy-check.pdf', 'x'),
            'category_id' => $perfCategoryId,
            'access_level' => 'public',
        ])->assertOk()
            ->assertJsonPath('access_policy.chosen_is_allowed', false)
            ->assertJsonPath('access_policy.allowed', ['restricted', 'confidential']);
    }

    #[Test]
    public function a_run_is_recorded_in_the_audit_trail_without_persisting_a_document(): void
    {
        $before = Document::count();

        $this->asUser()->post('/api/dashboard/documents/preflight', [
            'file' => $this->pdf('audit.pdf', 'trail'),
        ])->assertOk();

        $this->assertSame($before, Document::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'submission_preflight']);
    }

    #[Test]
    public function an_office_admin_cannot_run_the_uploader_only_preflight_check(): void
    {
        $this->asOfficeAdmin()->post('/api/dashboard/documents/preflight', [
            'file' => $this->pdf('blocked.pdf', 'x'),
        ])->assertStatus(403);
    }
}
