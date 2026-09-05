<?php

namespace App\Console\Commands;

use App\AI\Contracts\AiProvider;
use App\Jobs\AnalyzeDocument;
use App\Models\Document;
use Illuminate\Console\Command;

/**
 * The manual fallback for a document whose automatic AI analysis
 * silently produced nothing — most often a one-off connection failure
 * talking to the provider (OpenAiCompatibleProvider now retries those
 * automatically; this is for whatever still slips through, or for
 * re-running after an admin changes the AI provider/model/capabilities).
 */
class ReanalyzeDocument extends Command
{
    protected $signature = 'documents:reanalyze
        {document? : A tracking number or numeric id}
        {--missing : Re-run every document that has zero AI suggestion rows}
        {--dry-run : List what would be re-analyzed without calling the AI provider}';

    protected $description = 'Re-run AI analysis for a document (or every document with no AI suggestions yet)';

    public function handle(AiProvider $provider): int
    {
        if ($this->option('missing')) {
            return $this->reanalyzeMissing($provider);
        }

        $ref = $this->argument('document');

        if (! $ref) {
            $this->error('Give a tracking number or id, or pass --missing.');

            return self::FAILURE;
        }

        $document = $this->find($ref);

        if ($document === null) {
            $this->error("No document matches \"{$ref}\".");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info("Would re-analyze {$document->tracking_no} ({$document->title}).");

            return self::SUCCESS;
        }

        $this->analyze($document, $provider);
        $this->info("Re-analyzed {$document->tracking_no}.");

        return self::SUCCESS;
    }

    private function reanalyzeMissing(AiProvider $provider): int
    {
        $documents = Document::query()->whereDoesntHave('aiSuggestions')->get(['id', 'tracking_no', 'title']);

        if ($documents->isEmpty()) {
            $this->info('Every document already has at least one AI suggestion row.');

            return self::SUCCESS;
        }

        $this->info("{$documents->count()} document(s) with no AI suggestions yet:");
        $documents->each(fn (Document $d) => $this->line("  {$d->tracking_no}  {$d->title}"));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($documents->count());
        $bar->start();

        foreach ($documents as $document) {
            $this->analyze($document, $provider);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function find(string $ref): ?Document
    {
        return is_numeric($ref)
            ? Document::find((int) $ref)
            : Document::where('tracking_no', $ref)->first();
    }

    private function analyze(Document $document, AiProvider $provider): void
    {
        (new AnalyzeDocument($document))->handle($provider);
    }
}
