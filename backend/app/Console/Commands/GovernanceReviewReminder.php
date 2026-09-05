<?php

namespace App\Console\Commands;

use App\Models\GovernanceReview;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Console\Command;

/**
 * BR-07 / Phase 7.2 — remind the platform admins when a governance
 * review scope (categories / access levels / retention) is overdue.
 * Scheduled monthly in routes/console.php.
 */
class GovernanceReviewReminder extends Command
{
    protected $signature = 'governance:remind {--dry-run}';

    protected $description = 'Notify admins of overdue governance reviews (BR-07)';

    public function handle(): int
    {
        $overdue = collect(GovernanceReview::status())->where('overdue', true);

        $this->info("Overdue governance scopes: {$overdue->count()}");
        $overdue->each(fn ($s) => $this->line("  {$s['scope']} — due {$s['next_due_at']}"));

        if ($overdue->isEmpty() || $this->option('dry-run')) {
            return self::SUCCESS;
        }

        $admins = User::where('role', User::ROLE_SYSTEM_ADMIN)->where('is_active', true)->pluck('id');
        $list = $overdue->pluck('scope')->implode(', ');

        Notifier::sendMany(
            $admins,
            'governance_reminder',
            "Governance review overdue for: {$list}. Record it in System / Governance.",
            '/admin/governance',
        );

        $this->info('Reminder sent.');

        return self::SUCCESS;
    }
}
