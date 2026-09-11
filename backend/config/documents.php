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

    /*
    |--------------------------------------------------------------------------
    | Access-level policy per category (PF-09 step 9 / BR-04 / BR-08)
    |--------------------------------------------------------------------------
    | Which access levels a document in a given category may carry, keyed
    | by category_code. This is the system-enforced half of "validate
    | document classification and access level" — the reviewer checklist
    | and the AI confidentiality hint are the advisory halves.
    |
    |   allowed — the only levels accepted for this category. A submission
    |             or an approval outside this set is rejected (422).
    |   default — pre-selected in the upload form.
    |
    | A category_code with no entry here falls back to `*`, i.e. any
    | level is acceptable — so adding a category never breaks uploads.
    |
    | THIS IS A BUSINESS RULE, NOT A TECHNICAL ONE. Every entry below
    | blocks real submissions, so the owner should confirm each line.
    | Loosening a category is always safe; tightening one can reject
    | uploads that were previously accepted.
    */

    'access_policy' => [
        // Governance papers and board minutes are never public.
        'GOV' => ['allowed' => ['internal', 'restricted', 'confidential'], 'default' => 'internal'],

        // Regulatory / compliance evidence is submitted to outside bodies
        // but is not public until the body publishes it.
        'COMP' => ['allowed' => ['internal', 'restricted', 'confidential'], 'default' => 'internal'],

        // Accreditation evidence spans both kinds: self-study chapters,
        // policy documents and process manuals name nobody and circulate
        // institution-wide, while individual-level evidence needs
        // restricting. Never public — it is written for an accrediting
        // body, not for release.
        'ACCR' => ['allowed' => ['internal', 'restricted', 'confidential'], 'default' => 'restricted'],

        // Performance monitoring (OPCR/IPCR) is individual performance
        // data — the most sensitive category in the repository, and
        // deliberately the strictest rule here: no OPCR/IPCR can ever be
        // filed as internal or public, even by mistake. An aggregate
        // performance summary that names nobody belongs under
        // Administrative or Strategic Planning instead.
        'PERF' => ['allowed' => ['restricted', 'confidential'], 'default' => 'confidential'],

        // Templates and blank controlled forms carry no content — they
        // are meant to be handed out.
        'TMPL' => ['allowed' => ['public', 'internal'], 'default' => 'internal'],

        // Rankings submissions are publicity material.
        'RANK' => ['allowed' => ['public', 'internal', 'restricted'], 'default' => 'internal'],

        // Anything not listed above — deliberately open. Strategic
        // Planning, Infrastructure, Administrative and Archived all
        // genuinely span the full range (a strategic plan may be
        // confidential in draft and public once approved), so the
        // uploader and reviewer judge each case.
        '*' => ['allowed' => ['public', 'internal', 'restricted', 'confidential'], 'default' => 'internal'],
    ],

];
