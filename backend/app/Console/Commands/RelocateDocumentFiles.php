<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off: move uploaded document files off the web-served `public`
 * disk (where earlier builds stored them) onto the private
 * Document::DISK. Safe to run more than once. Run this in any
 * environment that already has real uploads before deploying the
 * private-storage change.
 */
class RelocateDocumentFiles extends Command
{
    protected $signature = 'documents:relocate {--dry-run : List what would move without touching files}';

    protected $description = 'Move document files from the public disk to the private document disk';

    public function handle(): int
    {
        $from = Storage::disk('public');
        $to = Storage::disk(Document::DISK);
        $dry = (bool) $this->option('dry-run');

        if (Document::DISK === 'public') {
            $this->warn('Document::DISK is still "public" — nothing to relocate.');

            return self::SUCCESS;
        }

        $files = $from->exists('documents') ? $from->allFiles('documents') : [];

        if ($files === []) {
            $this->info('No files found under public/documents. Nothing to do.');

            return self::SUCCESS;
        }

        $moved = 0;

        foreach ($files as $path) {
            if ($to->exists($path)) {
                $this->line("skip (already present): {$path}");

                continue;
            }

            $this->line(($dry ? '[dry-run] ' : '')."move: public/{$path} -> ".Document::DISK."/{$path}");

            if (! $dry) {
                $to->put($path, $from->get($path));
                $from->delete($path);
                $moved++;
            }
        }

        $this->info($dry ? 'Dry run complete.' : "Relocated {$moved} file(s).");

        return self::SUCCESS;
    }
}
