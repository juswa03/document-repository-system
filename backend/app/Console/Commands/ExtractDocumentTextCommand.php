<?php

namespace App\Console\Commands;

use App\Jobs\ExtractDocumentText;
use App\Models\Document;
use Illuminate\Console\Command;

/**
 * Backfill / re-run text extraction. Use after deploying Phase 8 to an
 * environment that already holds uploads, or after fixing the extractor.
 */
class ExtractDocumentTextCommand extends Command
{
    protected $signature = 'documents:extract-text
        {--missing : Only documents with no extracted text yet}
        {--sync : Run inline instead of queueing}';

    protected $description = 'Extract (or re-extract) the readable text of stored documents';

    public function handle(): int
    {
        $query = Document::query()->whereNotNull('file_path');

        if ($this->option('missing')) {
            $query->whereNull('text_extracted_at');
        }

        $total = $query->count();
        $this->info("Scheduling text extraction for {$total} document(s)".($this->option('missing') ? ' with no text yet' : '').'.');

        $query->select('id', 'file_path', 'file_format', 'tracking_no')->chunkById(200, function ($docs) {
            foreach ($docs as $doc) {
                $this->option('sync')
                    ? ExtractDocumentText::dispatchSync($doc)
                    : ExtractDocumentText::dispatch($doc);
                $this->line("  {$doc->tracking_no}");
            }
        });

        $this->info('Done.');

        return self::SUCCESS;
    }
}
