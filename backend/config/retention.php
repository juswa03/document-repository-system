<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Records retention (DR-14, decision 0.4)
    |--------------------------------------------------------------------------
    |
    | PLACEHOLDER VALUES. The OSM has not yet supplied the real retention
    | schedule (decision 0.4 is still open). These make the lifecycle
    | testable and demonstrable now; swap in the approved figures — per
    | category, keyed by `category_code` — when they arrive. Nothing in
    | the code hard-codes a number.
    |
    */

    // Months a document is retained, measured from its `document_date`,
    // before it becomes ELIGIBLE for archival. `default` covers any
    // category not listed.
    'periods_months' => [
        'default' => 60,   // 5 years
        // 'STRAT' => 120,
        // 'COMP'  => 84,
        // 'ACCR'  => 84,
        // 'ADMIN' => 36,
    ],

    // Months an archived document sits before it becomes ELIGIBLE for
    // disposal. Disposal is never automatic — this only drives the
    // "due for disposal" list an admin reviews.
    'disposal_grace_months' => 24,

];
