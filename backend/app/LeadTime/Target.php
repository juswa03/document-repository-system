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
     * Whole days the document has been in its current review stage —
     * measured from the last decision, else from submission.
     */
    public static function daysInStage(Document $document): int
    {
        $anchor = $document->review?->reviewed_at ?? $document->submitted_at;

        return $anchor ? (int) $anchor->diffInDays(Carbon::now()) : 0;
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
