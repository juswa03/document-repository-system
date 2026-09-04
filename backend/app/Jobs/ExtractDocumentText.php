<?php

namespace App\Jobs;

use App\Extraction\TextExtractor;
use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Pulls the readable text out of a document's file and stores it on the
 * row. Best-effort — a file the extractor can't read leaves
 * `extracted_text` NULL and everything downstream simply skips it.
 * Dispatched on upload and on a file-replacing resubmission.
 */
class ExtractDocumentText implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Document $document) {}

    public function handle(TextExtractor $extractor): void
    {
        $disk = Storage::disk(Document::DISK);

        if (! $this->document->file_path || ! $disk->exists($this->document->file_path)) {
            return;
        }

        $text = $extractor->extract(
            $disk->path($this->document->file_path),
            (string) $this->document->file_format,
        );

        $this->document->forceFill([
            'extracted_text' => $text,
            'text_extracted_at' => now(),
        ])->save();

        AuditLog::record(
            null,
            'document_text_extracted',
            $text === null
                ? "No readable text could be extracted from {$this->document->tracking_no}."
                : 'Extracted '.mb_strlen($text)." characters of text from {$this->document->tracking_no}.",
            Document::class,
            $this->document->id,
        );
    }
}
