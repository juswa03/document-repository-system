<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Suggested lead times (decision 0.9 — advisory, not enforced)
    |--------------------------------------------------------------------------
    | Working-day targets per activity. Nothing blocks on these; they
    | drive the "overdue" badge on the queue/dashboard (Phase 7.1) and
    | the Document Aging report (RPT-08, Phase 6.2). "Standard processing
    | time" for RPT-08 = the applicable review target below.
    |
    | Targets are in WORKING days — weekends and the holidays listed
    | below are not counted (App\LeadTime\Target::workingDaysBetween).
    |
    | A submission is "complex / sensitive" when the AI confidentiality
    | flag is set, its access level is restricted/confidential, or a
    | reviewer marks it so; everything else is "simple".
    |
    | The published table states ranges (1-2, 3-5 days). `min` is the
    | expected turnaround and `max` the point past which the item is
    | flagged — nothing is late until it exceeds max.
    */

    'review_days' => [
        'simple' => 2,   // review & approval — simple document (1-2 days)
        'complex' => 5,  // review & approval — complex / sensitive (3-5 days)
    ],

    /*
    | The full published table, as {min, max} working days. 0 means the
    | activity is expected to complete the same day / immediately.
    | Surfaced read-only through GET /api/lead-times so the interface can
    | tell a user what to expect instead of hard-coding it.
    */
    'activities' => [
        'upload_encoding' => [
            'label' => 'Upload and metadata encoding',
            'min' => 0, 'max' => 0,
            'note' => 'Same day.',
        ],
        'ai_classification' => [
            'label' => 'AI classification and metadata extraction',
            'min' => 0, 'max' => 0,
            'note' => 'Same day — runs automatically on upload.',
        ],
        'completeness_check' => [
            'label' => 'Completeness check',
            'min' => 0, 'max' => 1,
            'note' => 'Within 1 working day.',
        ],
        'review_simple' => [
            'label' => 'Review and approval of simple documents',
            'min' => 1, 'max' => 2,
            'note' => '1-2 working days.',
        ],
        'review_complex' => [
            'label' => 'Review of complex or sensitive documents',
            'min' => 3, 'max' => 5,
            'note' => '3-5 working days. Restricted/confidential documents, '
                .'or any the AI flagged as sensitive.',
        ],
        'search_retrieval' => [
            'label' => 'Document retrieval through search',
            'min' => 0, 'max' => 0,
            'note' => 'Immediate, if authorized.',
        ],
        'manual_retrieval' => [
            'label' => 'Manual retrieval assistance',
            'min' => 0, 'max' => 1,
            'note' => '1 working day.',
        ],
        'report_generation' => [
            'label' => 'Report generation',
            'min' => 0, 'max' => 1,
            'note' => 'Immediate to 1 working day.',
        ],
        'compliance_report' => [
            'label' => 'Complex compliance report generation',
            'min' => 2, 'max' => 5,
            'note' => '2-5 working days.',
        ],
    ],

    /*
    | What the published table says lead time depends on. Shown alongside
    | the targets so the figures are never read as a guarantee.
    */
    'depends_on' => [
        'Document completeness',
        'Accuracy of metadata',
        'Sensitivity of information',
        'The need for further validation by the OSM Head, document controller, '
            .'or concerned data owner',
    ],

    /*
    | Non-working dates (YYYY-MM-DD) excluded from working-day counts,
    | on top of weekends. Set LEAD_TIME_HOLIDAYS as a comma-separated
    | list, or edit here.
    */
    'holidays' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('LEAD_TIME_HOLIDAYS', '')),
    ))),

];
