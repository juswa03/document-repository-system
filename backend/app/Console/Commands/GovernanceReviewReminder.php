<?php

namespace App\Console\Commands;

use App\Models\GovernanceReview;
use App\Models\Notification;
use App\Models\User;
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
        $now = now();
        $list = $overdue->pluck('scope')->implode(', ');

        Notification::insert($admins->map(fn (int $uid) => [
            'user_id' => $uid,
            'message' => "Governance review overdue for: {$list}. Record it in System / Governance.",
            'type' => 'governance_reminder',
            'is_read' => false,
            'created_at' => $now,
        ])->all());

        $this->info('Reminder sent.');

        return self::SUCCESS;
    }
}
