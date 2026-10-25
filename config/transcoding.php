<?php

return [
    'staging_reserve_bytes' => (int) env('TRANSCODE_STAGING_RESERVE_BYTES', 268435456),
    'optimization' => [
        'maximum_candidates' => (int) env('TRANSCODE_OPTIMIZATION_CANDIDATES', 8),
        'seconds' => (int) env('TRANSCODE_OPTIMIZATION_SECONDS', 1200),
        'temporary_bytes' => (int) env('TRANSCODE_OPTIMIZATION_TEMPORARY_BYTES', 4294967296),
    ],
    'timeout_seconds' => max(1, (int) env('TRANSCODE_TIMEOUT_SECONDS', 604800)),
    'staging_disk' => env('TRANSCODE_STAGING_DISK', 'transcodes'),
];
