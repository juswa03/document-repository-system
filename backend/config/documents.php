<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    | max_upload_kb  — decision 0.3 (20 MB).
    | allowed_mimes  — FR-03: "PDF and Word" only. Widen this list here
    |                  if the owner rules otherwise (spreadsheets for the
    |                  Dataset document type, say).
    */

    'max_upload_kb' => (int) env('DOCUMENTS_MAX_UPLOAD_KB', 20480),

    'allowed_mimes' => ['pdf', 'doc', 'docx'],

    /*
    |--------------------------------------------------------------------------
    | Controlled vocabularies (decision 0.10 / 0.4)
    |--------------------------------------------------------------------------
    | Categories stay a DB table (admin-editable). These three sets are
    | small and stable, so they live here and are mirrored by constants
    | on App\Models\Document.
    */

    'types' => ['report', 'memo', 'minutes', 'plan', 'template', 'evidence', 'dataset'],

    'access_levels' => ['public', 'internal', 'restricted', 'confidential'],

    'default_access_level' => 'internal',

    'retention_statuses' => ['active', 'superseded', 'archived', 'disposed'],

    /*
    |--------------------------------------------------------------------------
    | Near-duplicate detection (PF-06 / AI-03, Phase 10)
    |--------------------------------------------------------------------------
    | Word-trigram Jaccard similarity (0-100) at or above which a newly
    | analysed document is flagged as a possible near-duplicate of an
    | existing one in the same category and office. Advisory only.
    */

    'near_duplicate_threshold' => (int) env('DOCUMENTS_NEAR_DUPLICATE_THRESHOLD', 65),

];
