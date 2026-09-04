<?php

namespace Tests\Feature\Conformance;

use App\Extraction\TextExtractor;
use App\Jobs\ExtractDocumentText;
use App\Models\Document;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Phase 8 — the readable text of an uploaded document is pulled out on
 * submission so it can be searched (Phase 9) and summarised / compared
 * (Phase 10). Best-effort: an unreadable file leaves the column NULL.
 */
class TextExtractionTest extends ConformanceTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
        $this->tmp = sys_get_temp_dir().'/extract-'.uniqid();
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmp}/*") ?: []);
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /** A spec-valid minimal single-page PDF with a correct xref table. */
    private function minimalPdf(string $text): string
    {
        $stream = "BT /F1 18 Tf 20 120 Td ({$text}) Tj ET";
        $objs = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            '<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 160]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>',
            '<</Length '.strlen($stream).">>\nstream\n{$stream}\nendstream",
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= 'trailer<</Size '.(count($objs) + 1)."/Root 1 0 R>>\nstartxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    private function docx(string $text): string
    {
        $path = "{$this->tmp}/sample.docx";
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            ."<w:body><w:p><w:r><w:t>{$text}</w:t></w:r></w:p></w:body></w:document>");
        $zip->close();

        return $path;
    }

    public function test_pdf_text_is_extracted(): void
    {
        $file = "{$this->tmp}/board.pdf";
        file_put_contents($file, $this->minimalPdf('INFRASTRUCTURE FUNDING RESOLUTION'));

        $text = app(TextExtractor::class)->extract($file, 'pdf');

        $this->assertNotNull($text);
        $this->assertStringContainsString('INFRASTRUCTURE FUNDING RESOLUTION', $text);
    }

    public function test_docx_text_is_extracted(): void
    {
        $text = app(TextExtractor::class)->extract($this->docx('Board minutes Q3 budget approval'), 'docx');

        $this->assertSame('Board minutes Q3 budget approval', $text);
    }

    public function test_an_unreadable_file_yields_null(): void
    {
        $file = "{$this->tmp}/scan.bin";
        file_put_contents($file, random_bytes(64));

        $this->assertNull(app(TextExtractor::class)->extract($file, 'pdf'));
        $this->assertNull(app(TextExtractor::class)->extract($file, 'jpg'));
    }

    public function test_extracted_text_is_capped(): void
    {
        $file = "{$this->tmp}/big.txt";
        file_put_contents($file, str_repeat('lorem ipsum ', 40_000));   // ~480k chars

        $text = app(TextExtractor::class)->extract($file, 'txt');

        $this->assertLessThanOrEqual(TextExtractor::MAX_CHARS, mb_strlen($text));
    }

    public function test_the_job_stores_text_and_audits_it(): void
    {
        $doc = $this->createDocument();
        Storage::disk(Document::DISK)->put($doc->file_path, $this->minimalPdf('QUARTERLY PERFORMANCE REVIEW'));
        $doc->update(['file_format' => 'pdf']);

        (new ExtractDocumentText($doc))->handle(app(TextExtractor::class));

        $doc->refresh();
        $this->assertStringContainsString('QUARTERLY PERFORMANCE REVIEW', (string) $doc->extracted_text);
        $this->assertNotNull($doc->text_extracted_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_text_extracted', 'subject_id' => $doc->id]);
    }

    public function test_extraction_is_queued_on_upload(): void
    {
        Queue::fake();

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())->assertCreated();

        Queue::assertPushed(ExtractDocumentText::class);
    }

    public function test_the_backfill_command_only_touches_untouched_rows_with_the_flag(): void
    {
        $doc = $this->createDocument();
        Storage::disk(Document::DISK)->put($doc->file_path, $this->minimalPdf('STRATEGIC PLAN 2027'));
        $doc->update(['file_format' => 'pdf']);

        $this->artisan('documents:extract-text --missing --sync')->assertSuccessful();

        $this->assertStringContainsString('STRATEGIC PLAN 2027', (string) $doc->fresh()->extracted_text);
    }
}
