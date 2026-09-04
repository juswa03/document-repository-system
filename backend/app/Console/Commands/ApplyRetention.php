<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Console\Command;

/**
 * Reports which documents have reached their retention period and are
 * due for archival, and which archived documents are past the disposal
 * grace period. With --archive it performs the archival; it NEVER
 * disposes — disposal stays a deliberate, individually-audited human
 * action through the API.
 *
 * Scheduled monthly (report only) in routes/console.php.
 */
class ApplyRetention extends Command
{
    protected $signature = 'documents:apply-retention
        {--archive : Archive the documents that are due (otherwise report only)}
        {--dry-run : Force report-only even with --archive}';

    protected $description = 'Report — and optionally apply — retention-period archival (DR-14)';

    public function handle(): int
    {
        $dueForArchival = Document::with('category')->retainable()->get()->filter->isRetentionDue()->values();
        $dueForDisposal = Document::with('category')->archived()->get()->filter->isDisposalDue()->values();

        $this->info("Due for archival: {$dueForArchival->count()}");
        foreach ($dueForArchival as $d) {
            $this->line("  {$d->tracking_no}  {$d->title}  (retention reached {$d->retentionDueAt()?->toDateString()})");
        }

        $this->info("Archived, past the disposal grace period: {$dueForDisposal->count()}");
        foreach ($dueForDisposal as $d) {
            $this->line("  {$d->tracking_no}  {$d->title}  (eligible since {$d->disposalDueAt()?->toDateString()})");
        }
        if ($dueForDisposal->isNotEmpty()) {
            $this->comment('  Dispose of these individually via POST /api/osm-admin/documents/{id}/dispose.');
        }

        $apply = $this->option('archive') && ! $this->option('dry-run');

        if (! $apply) {
            $this->newLine();
            $this->comment('Report only. Re-run with --archive to archive the documents listed above.');

            return self::SUCCESS;
        }

        foreach ($dueForArchival as $d) {
            $d->archive();
            AuditLog::record(
                null,
                'document_archived',
                "Archived {$d->tracking_no} ({$d->title}) — retention period reached (documents:apply-retention).",
                Document::class,
                $d->id,
                ['retention_due_at' => $d->retentionDueAt()?->toDateString(), 'automated' => true],
            );
        }

        $this->newLine();
        $this->info("Archived {$dueForArchival->count()} document(s).");

        return self::SUCCESS;
    }
}
