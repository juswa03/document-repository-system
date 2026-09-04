<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One periodic OSM governance review (BR-07, Phase 7.2).
 */
class GovernanceReview extends Model
{
    protected $fillable = ['reviewed_by', 'scope', 'performed_at', 'notes', 'next_due_at'];

    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
            'next_due_at' => 'date',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function cadenceMonths(string $scope): int
    {
        return (int) (config("governance.cadence_months.{$scope}")
            ?? config('governance.default_cadence_months'));
    }

    /**
     * Current state per scope: the last review, whether it is overdue,
     * and when the next one is due (from the first day the system ran if
     * a scope has never been reviewed).
     */
    public static function status(): array
    {
        $out = [];
        foreach (config('governance.scopes') as $scope) {
            $last = static::where('scope', $scope)->latest('performed_at')->first();
            $nextDue = $last?->next_due_at ?? Carbon::now()->subDay();

            $out[] = [
                'scope' => $scope,
                'last_reviewed_at' => $last?->performed_at?->toDateString(),
                'last_reviewed_by' => $last?->reviewer?->full_name,
                'next_due_at' => $nextDue->toDateString(),
                'overdue' => $nextDue->isPast(),
                'cadence_months' => static::cadenceMonths($scope),
            ];
        }

        return $out;
    }
}
