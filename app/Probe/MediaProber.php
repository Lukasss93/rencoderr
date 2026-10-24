<?php

namespace App\Probe;

use FFMpeg\Driver\FFProbeDriver;

class MediaProber
{
    public const int TIMEOUT_SECONDS = 120;

    public function __construct(private FFProbeDriver $driver) {}

    public function probe(string $path): ProbeResult
    {
        return ProbeResult::fromJson($this->driver->command([
            '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', '-show_chapters', $path,
        ]));
    }
}
