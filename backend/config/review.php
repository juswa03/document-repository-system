<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Review routing (PF-08, Phase 4.3)
    |--------------------------------------------------------------------------
    | How a new/resubmitted submission is routed into the review queue.
    |
    |   office_queue — leave it unassigned; it surfaces in the "unassigned"
    |                  view for OSM admins in the same office as the
    |                  submitter (falls back to all OSM admins when the
    |                  submitter has no office). A reviewer then claims it.
    |   round_robin  — assign it immediately to the active OSM admin who
    |                  currently holds the fewest open items.
    */
    'routing' => [
        'strategy' => env('REVIEW_ROUTING', 'office_queue'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reviewer completeness checklist (PF-09, Phase 4.3)
    |--------------------------------------------------------------------------
    | The reviewer must confirm every `required` item before a submission
    | can be APPROVED (return / reject are not gated). AI-04 pre-fills the
    | document list from its completeness suggestion where one exists.
    | Keyed by submission kind.
    */
    'checklists' => [
        'document' => [
            ['key' => 'metadata_complete', 'label' => 'Required metadata is present and accurate', 'required' => true],
            ['key' => 'classification_correct', 'label' => 'Category and document type are correct', 'required' => true],
            ['key' => 'access_level_appropriate', 'label' => 'Proposed access level fits the content sensitivity', 'required' => true],
            ['key' => 'file_verified', 'label' => 'The attached file opens and is the intended document', 'required' => true],
            ['key' => 'not_unintended_duplicate', 'label' => 'Not an unintended duplicate of an existing record', 'required' => false],
        ],
        'request' => [
            ['key' => 'details_clear', 'label' => 'Request details and justification are clear', 'required' => true],
            ['key' => 'needed_by_realistic', 'label' => 'The needed-by date leaves time to process', 'required' => true],
            ['key' => 'amount_supported', 'label' => 'Any amount is supported and within limits', 'required' => false],
        ],
    ],

];
