<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * PF-02 / PF-04 / BR-02 and DR-02, DR-05–DR-10, DR-17, DR-18 — the
 * documented minimum metadata is collected, enforced, and the
 * system-derived file fields are captured (Phase 2a).
 */
class MetadataTest extends ConformanceTestCase
{
    private function submit(array $overrides = [])
    {
        Storage::fake(Document::DISK);

        return $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload($overrides));
    }

    public function test_a_complete_submission_persists_every_metadata_field(): void
    {
        $id = $this->submit([
            'title' => 'Q3 2026 Performance Report',
            'document_type' => 'report',
            'document_date' => '2026-07-15',
            'reporting_period' => 'AY 2025–2026',
            'access_level' => 'restricted',
            'keywords' => 'performance, KPI, Q3',
            'description' => 'Institutional performance against the Q3 2026 monitoring targets.',
        ])->assertCreated()->json('id');

        $doc = Document::find($id);
        $this->assertSame('report', $doc->document_type);
        $this->assertSame('2026-07-15', $doc->document_date->toDateString());
        $this->assertSame('AY 2025–2026', $doc->reporting_period);
        $this->assertSame('restricted', $doc->access_level);
        $this->assertSame('performance, KPI, Q3', $doc->keywords);
        $this->assertNotEmpty($doc->description);
        $this->assertSame('pdf', $doc->file_format);
        $this->assertGreaterThan(0, $doc->file_size);
        $this->assertSame('active', $doc->retention_status);
        $this->assertSame(1, $doc->version_number);
    }

    public function test_access_level_defaults_are_respected_and_validated(): void
    {
        // omitted → still required (DR-07, uploader proposes it)
        $this->submit(['access_level' => null])->assertJsonValidationErrors(['access_level']);

        // bad value → rejected
        $this->submit(['access_level' => 'top-secret'])->assertJsonValidationErrors(['access_level']);
    }

    public function test_document_type_must_be_one_of_the_defined_set(): void
    {
        $this->submit(['document_type' => 'spreadsheet'])->assertJsonValidationErrors(['document_type']);
        $this->submit(['document_type' => 'minutes'])->assertCreated();
    }

    public function test_a_too_short_description_is_rejected(): void
    {
        $this->submit(['description' => 'n/a'])->assertJsonValidationErrors(['description']);
    }

    public function test_the_upload_size_limit_comes_from_config(): void
    {
        config(['documents.max_upload_kb' => 100]);

        $this->submit(['file' => UploadedFile::fake()->create('big.pdf', 200, 'application/pdf')])
            ->assertJsonValidationErrors(['file']);
    }
}
