<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email delivery
    |--------------------------------------------------------------------------
    | In-app notifications are always written to the `notifications` table
    | (the bell reads that). When `email_enabled` is on, the types listed
    | in `email_types` are ALSO sent to the recipient's email address —
    | after the HTTP response is flushed, so SMTP latency never delays an
    | upload or a review decision.
    |
    | Keep the noisy fan-out types (e.g. the review-queue broadcast to the
    | whole OSM pool) OUT of `email_types`; only direct, actionable events
    | should reach an inbox.
    */

    'email_enabled' => (bool) env('NOTIFY_EMAIL_ENABLED', true),

    'email_types' => [
        'submission_confirmation', // your upload/request was received
        'review_decision',         // approved / rejected / needs revision
        'review_pending',          // a specific item was assigned to you
    ],

];
