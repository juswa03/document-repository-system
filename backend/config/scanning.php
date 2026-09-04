<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload malware scanning (PF-03, Phase 7.3)
    |--------------------------------------------------------------------------
    | driver     — null (no-op, always clean) or clamav (talk to clamd).
    | fail_open  — when the scanner is unreachable, still accept the upload
    |              (true, default for dev) or reject it (false). Either way
    |              the outcome is audited.
    */

    'driver' => env('SCAN_DRIVER', 'null'),

    'fail_open' => (bool) env('SCAN_FAIL_OPEN', true),

    'clamav' => [
        'socket' => env('CLAMAV_SOCKET'),          // e.g. /var/run/clamav/clamd.ctl
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],

];
