<?php

return [
    'staging_reserve_bytes' => (int) env('TRANSCODE_STAGING_RESERVE_BYTES', 268435456),
    'optimization' => [
        'starting_quality' => [
            'libx265' => (int) env('TRANSCODE_OPTIMIZATION_LIBX265_START_QUALITY', 28),
            'hevc_nvenc' => (int) env('TRANSCODE_OPTIMIZATION_HEVC_NVENC_START_QUALITY', 28),
            'hevc_qsv' => (int) env('TRANSCODE_OPTIMIZATION_HEVC_QSV_START_QUALITY', 28),
            'hevc_vaapi' => (int) env('TRANSCODE_OPTIMIZATION_HEVC_VAAPI_START_QUALITY', 28),
            'libsvtav1' => (int) env('TRANSCODE_OPTIMIZATION_LIBSVTAV1_START_QUALITY', 32),
            'av1_nvenc' => (int) env('TRANSCODE_OPTIMIZATION_AV1_NVENC_START_QUALITY', 28),
            'av1_qsv' => (int) env('TRANSCODE_OPTIMIZATION_AV1_QSV_START_QUALITY', 28),
            'av1_vaapi' => (int) env('TRANSCODE_OPTIMIZATION_AV1_VAAPI_START_QUALITY', 112),
        ],
        'maximum_candidates' => (int) env('TRANSCODE_OPTIMIZATION_CANDIDATES', 8),
        'seconds' => (int) env('TRANSCODE_OPTIMIZATION_SECONDS', 1200),
        'temporary_bytes' => (int) env('TRANSCODE_OPTIMIZATION_TEMPORARY_BYTES', 4294967296),
    ],
    'timeout_seconds' => max(1, (int) env('TRANSCODE_TIMEOUT_SECONDS', 604800)),
    'staging_disk' => env('TRANSCODE_STAGING_DISK', 'transcodes'),
];
