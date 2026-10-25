<?php

return [
    'timeout_seconds' => max(1, (int) env('TRANSCODE_TIMEOUT_SECONDS', 604800)),
    'staging_disk' => env('TRANSCODE_STAGING_DISK', 'transcodes'),
];
