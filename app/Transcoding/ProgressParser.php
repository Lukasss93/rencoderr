<?php

namespace App\Transcoding;

class ProgressParser
{
    private string $pending = '';

    private float $elapsed = 0.0;

    private bool $discarding = false;

    public function __construct(private readonly ?float $duration) {}

    public function push(string $chunk): void
    {
        foreach (explode("\n", $chunk) as $index => $part) {
            if ($index > 0) {
                if (! $this->discarding && preg_match('/^out_time_(?:us|ms)=(-?\d+)\r?$/D', $this->pending, $matches) === 1) {
                    $this->elapsed = max($this->elapsed, (float) $matches[1] / 1000000);
                }

                $this->pending = '';
                $this->discarding = false;
            }

            if (! $this->discarding) {
                $this->pending .= $part;
                if (strlen($this->pending) > 1024) {
                    $this->pending = '';
                    $this->discarding = true;
                }
            }
        }
    }

    public function seconds(): float
    {
        return $this->elapsed;
    }

    public function percent(): ?float
    {
        return $this->duration !== null && $this->duration > 0
            ? min(100.0, $this->elapsed / $this->duration * 100)
            : null;
    }
}
