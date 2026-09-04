<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Suggested lead times (decision 0.9 — advisory, not enforced)
    |--------------------------------------------------------------------------
    | Working-day targets per stage. Nothing blocks on these; they drive
    | the "overdue" badge on the queue/dashboard (Phase 7.1) and the
    | Document Aging report (RPT-08, Phase 6.2). "Standard processing
    | time" for RPT-08 = the applicable review target below.
    |
    | A submission is "complex / sensitive" when the AI confidentiality
    | flag is set, its access level is restricted/confidential, or a
    | reviewer marks it so; everything else is "simple".
    */

    'review_days' => [
        'simple' => 2,   // review & approval — simple document
        'complex' => 5,  // review & approval — complex / sensitive
    ],

    // Other stages, kept for the badge logic / future reporting.
    'stage_days' => [
        'metadata_encoding' => 1,
        'ai_classification' => 1,
        'completeness_check' => 1,
        'manual_retrieval' => 1,
        'report_generation' => 1,
        'compliance_report' => 5,
    ],

];
