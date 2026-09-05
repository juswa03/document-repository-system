<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The framework ships sane defaults for this (paths: api/*,
    | sanctum/csrf-cookie; allowed_origins: *) even without this file
    | present, which is how the SPA's cross-origin calls to /api/* have
    | always worked. This file exists ONLY to add one more path:
    | broadcasting/* — the private-channel auth endpoint Phase 35's
    | real-time push added (Broadcast::routes() in bootstrap/app.php).
    | Without it here, the browser's CORS preflight for that endpoint
    | has nothing granting it Access-Control-Allow-Origin and the
    | request is silently blocked client-side — curl/PHPUnit never see
    | this failure mode since CORS is enforced by the browser, not the
    | server (caught by a real Playwright walkthrough, Phase 38).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
