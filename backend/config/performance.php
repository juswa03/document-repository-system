<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Performance targets (NFR-06 "immediate response", NFR-08 "under load")
    |--------------------------------------------------------------------------
    | PLACEHOLDER thresholds, in milliseconds, for the hot read paths. The
    | `documents:benchmark` command times each of these against a seeded
    | data volume and fails if the p95 exceeds its target. Tune the
    | numbers to the deployment's own hardware and the OSM's agreed
    | service level; nothing in the app enforces them.
    */

    'sample_rows' => (int) env('PERF_SAMPLE_ROWS', 5000),

    'iterations' => (int) env('PERF_ITERATIONS', 30),

    'targets_ms' => [
        'repository_metadata_search' => 400,
        'repository_content_search' => 700,
        'review_queue' => 300,
        'dashboard_stats' => 250,
        'report_document_inventory' => 1200,
        'document_lookup_by_tracking_no' => 100,
    ],

];
