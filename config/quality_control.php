<?php

return [
    'mode' => env('QUALITY_CONTROL_MODE', 'sampled'),
    'sample_positions' => explode(',', (string) env('QUALITY_CONTROL_SAMPLE_POSITIONS', '0.15,0.50,0.85')),
    'sample_seconds' => (float) env('QUALITY_CONTROL_SAMPLE_SECONDS', 30),
    'model_path' => env('QUALITY_CONTROL_MODEL_PATH', '/opt/ffmpeg/share/vmaf/vmaf_v0.6.1.json'),
    'width' => (int) env('QUALITY_CONTROL_WIDTH', 1920),
    'height' => (int) env('QUALITY_CONTROL_HEIGHT', 1080),
    'duration_tolerance_seconds' => (float) env('QUALITY_CONTROL_DURATION_TOLERANCE_SECONDS', 0.5),
    'timeout_seconds' => max(1, (int) env('QUALITY_CONTROL_TIMEOUT_SECONDS', 86400)),
    'threads' => max(1, (int) env('QUALITY_CONTROL_THREADS', 2)),
];
