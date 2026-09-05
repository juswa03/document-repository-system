<?php

namespace App\Console\Commands;

use App\LeadTime\Target;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Console\Command;

/**
 * Phase 7.1 / 7.3 — nudge stale reviews. For every pending or in-revision
 * document past its advisory lead-time target (config/lead_times.php),
 * notify the assignee (or, if unassigned, every active OSM admin) and
 * write an audit row. Advisory: nothing is blocked or auto-decided.
 * Scheduled daily in routes/console.php.
 */
class EscalateStaleReviews extends Command
{
    protected $signature = 'documents:escalate-stale {--dry-run : List the overdue items without notifying}';

    protected $description = 'Notify reviewers of documents past their suggested lead time (NFR / decision 0.9)';

    public function handle(): int
    {
        $stale = Document::whereIn('status', ['pending', 'revision'])
            ->with(['review', 'assignee'])
            ->get()
            ->filter(fn (Document $d) => Target::isOverdue($d));

        $this->info("Overdue documents: {$stale->count()}");

        if ($stale->isEmpty() || $this->option('dry-run')) {
            $stale->each(fn (Document $d) => $this->line(
                '  '.$d->tracking_no.'  '.$d->title.'  ('.Target::daysOverdue($d).' day(s) over)'
            ));

            return self::SUCCESS;
        }

        $pool = User::where('role', User::ROLE_OSM_ADMIN)->where('is_active', true)->pluck('id');

        foreach ($stale as $document) {
            $recipients = $document->assigned_to ? [$document->assigned_to] : $pool->all();
            $over = Target::daysOverdue($document);

            // In-app + live push only — never emailed, even though
            // "review_pending" is an emailable type elsewhere, so a daily
            // escalation sweep can't turn into a daily inbox blast.
            Notifier::inAppOnlyMany(
                $recipients,
                'review_pending',
                "Document {$document->tracking_no} is {$over} day(s) past its suggested lead time and still awaiting a decision.",
                '/osm-admin',
            );

            AuditLog::record(
                null,
                'document_escalated',
                "Escalated {$document->tracking_no} — {$over} day(s) past the suggested lead time.",
                Document::class,
                $document->id,
                ['days_overdue' => $over, 'automated' => true],
            );
        }

        $this->info("Escalated {$stale->count()} document(s).");

        return self::SUCCESS;
    }
}
