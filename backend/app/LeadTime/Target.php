<?php

namespace App\LeadTime;

use App\Models\Document;
use Illuminate\Support\Carbon;

/**
 * Advisory lead-time targets (decision 0.9). Classifies a document as
 * "simple" or "complex / sensitive" and answers how many working days
 * its review is meant to take, how long it has actually been waiting,
 * and whether that is over target. Nothing here blocks a workflow —
 * it feeds the aging report (RPT-08) and the queue "overdue" badge.
 */
final class Target
{
    public const SIMPLE = 'simple';
    public const COMPLEX = 'complex';

    /**
     * Ceiling on the day-by-day working-day walk. A submission sitting
     * this long is already far past any target; the exact number adds
     * nothing, so counting stops here rather than scanning years.
     */
    private const MAX_TRACKED_DAYS = 400;

    public static function complexity(Document $document): string
    {
        $sensitive = in_array($document->access_level, ['restricted', 'confidential'], true)
            || (bool) ($document->ai_confidential_flag ?? false);

        return $sensitive ? self::COMPLEX : self::SIMPLE;
    }

    public static function reviewDays(Document $document): int
    {
        return (int) config('lead_times.review_days.'.self::complexity($document));
    }

    /**
     * Working days the document has been in its current review stage —
     * measured from the last decision, else from submission.
     *
     * Working days, not calendar days: the targets are stated in working
     * days, so counting weekends would mark a Friday submission overdue
     * on Monday after one actual working day.
     */
    public static function daysInStage(Document $document): int
    {
        $anchor = $document->review?->reviewed_at ?? $document->submitted_at;

        return $anchor ? self::workingDaysBetween($anchor, Carbon::now()) : 0;
    }

    /**
     * Whole working days between two moments, excluding weekends and any
     * configured non-working dates (holidays).
     */
    public static function workingDaysBetween(Carbon $from, Carbon $to): int
    {
        if ($from->greaterThanOrEqualTo($to)) {
            return 0;
        }

        $holidays = array_flip((array) config('lead_times.holidays', []));

        $days = 0;
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        // A day-by-day walk is exact and cheap for the horizon that
        // matters; past it the precise figure stops informing anything,
        // so cap the work rather than walking years of backlog.
        if ($cursor->diffInDays($end) > self::MAX_TRACKED_DAYS) {
            $cursor = $end->copy()->subDays(self::MAX_TRACKED_DAYS);
            $days = self::MAX_TRACKED_DAYS;
        }

        while ($cursor->lessThan($end)) {
            $cursor->addDay();

            if ($cursor->isWeekend() || isset($holidays[$cursor->toDateString()])) {
                continue;
            }

            $days++;
        }

        return $days;
    }

    public static function isOverdue(Document $document): bool
    {
        return in_array($document->status, ['pending', 'revision'], true)
            && self::daysInStage($document) > self::reviewDays($document);
    }

    /** Negative = still within target; positive = days over. */
    public static function daysOverdue(Document $document): int
    {
        return self::daysInStage($document) - self::reviewDays($document);
    }
}
