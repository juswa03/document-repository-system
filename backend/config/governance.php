<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Governance review cadence (BR-07, Phase 7.2)
    |--------------------------------------------------------------------------
    | OSM periodically reviews the controlled vocabularies and the
    | retention picture. Months between reviews, per scope. The
    | governance:remind command notifies when a scope is overdue; a
    | recorded review sets the next due date from here.
    */

    'scopes' => ['categories', 'access_levels', 'retention'],

    'cadence_months' => [
        'categories' => 12,
        'access_levels' => 12,
        'retention' => 6,
    ],

    'default_cadence_months' => 12,

];
