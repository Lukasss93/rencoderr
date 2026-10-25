<?php

namespace App\QualityControl;

use App\Ffmpeg\MediaProcessInterrupted;
use Closure;
use RuntimeException;

class FrameTimeline
{
    /** @var resource */
    private $stream;

    private string $pending = '';

    private ?int $index = null;

    /** @var array<string, float> */
    private array $frame = [];

    private int $frames = 0;

    private ?float $first = null;

    private ?float $last = null;

    private float $lastDuration = 0.0;

    public function __construct(public readonly string $path, private float $fallbackDuration)
    {
        $stream = fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('Cannot create the frame timing manifest.');
        }
        $this->stream = $stream;
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function push(string $chunk): void
    {
        foreach (explode("\n", $chunk) as $index => $part) {
            if ($index > 0) {
                $this->line($this->pending);
                $this->pending = '';
            }
            $this->pending .= $part;
            if (strlen($this->pending) > 16384) {
                throw new RuntimeException('The frame inspection returned an oversized field.');
            }
        }
    }

    public function finish(): void
    {
        $this->line($this->pending);
        $this->pending = '';
        $this->save();
        $this->index = null;
        fflush($this->stream);
        $this->duration();
    }

    public function duration(): float
    {
        if ($this->first === null || $this->last === null || $this->lastDuration <= 0) {
            throw new RuntimeException('The decoded video duration could not be established.');
        }

        return $this->last + $this->lastDuration - $this->first;
    }

    public function origin(): float
    {
        if ($this->first === null) {
            throw new RuntimeException('The video has no decoded frames.');
        }

        return $this->first;
    }

    /**
     * @param  list<array{start: float, end: float}>  $windows
     * @param  (Closure(): bool)|null  $interrupted
     * @return list<array{start: float, end: float, frames: int, source_start: float, source_end: float, output_start: float, output_end: float}>
     */
    public function align(self $other, array $windows, float $tolerance, ?Closure $interrupted = null): array
    {
        $source = fopen($this->path, 'r');
        $output = fopen($other->path, 'r');
        if ($source === false || $output === false) {
            throw new RuntimeException('The frame timing manifests are unavailable.');
        }
        $ranges = array_map(fn (array $window): array => [...$window, 'frames' => 0, 'source_start' => 0.0, 'source_end' => 0.0, 'output_start' => 0.0, 'output_end' => 0.0], $windows);
        $previousSource = $previousOutput = null;
        $count = 0;
        try {
            while (($line = fgets($source)) !== false) {
                $paired = fgets($output);
                if ($paired === false) {
                    throw new QualityRejected('frame_count', 'The staged video has fewer decoded frames than the source.');
                }
                $a = (float) $line;
                $b = (float) $paired;
                $relative = $a - $this->origin();
                $step = $previousSource === null ? min($this->fallbackDuration, $other->fallbackDuration) : min($a - $previousSource, $b - $previousOutput);
                $allowed = max(0.000002, min($tolerance, max(0.000002, $step * 0.45)));
                if (abs($relative - ($b - $other->origin())) > $allowed) {
                    throw new QualityRejected('frame_alignment', 'Source and output video frames do not share the same timing.');
                }
                foreach ($ranges as &$range) {
                    if ($relative >= $range['start'] && $relative < $range['end']) {
                        if ($range['frames'] === 0) {
                            $range['source_start'] = $previousSource === null ? $a - 0.000001 : ($a + $previousSource) / 2;
                            $range['output_start'] = $previousOutput === null ? $b - 0.000001 : ($b + $previousOutput) / 2;
                        }
                        $range['frames']++;
                        $range['source_end'] = $a + $this->lastDuration / 2;
                        $range['output_end'] = $b + $other->lastDuration / 2;
                    } elseif ($range['frames'] > 0 && $previousSource !== null && $previousSource - $this->origin() < $range['end']) {
                        $range['source_end'] = ($a + $previousSource) / 2;
                        $range['output_end'] = ($b + $previousOutput) / 2;
                    }
                }
                unset($range);
                $previousSource = $a;
                $previousOutput = $b;
                if (++$count % 1000 === 0 && $interrupted !== null && $interrupted()) {
                    throw new MediaProcessInterrupted('Quality control was interrupted while comparing frame timing.');
                }
            }
            if (fgets($output) !== false) {
                throw new QualityRejected('frame_count', 'The staged video has additional decoded frames.');
            }
            foreach ($ranges as $range) {
                if ($range['frames'] < 1) {
                    throw new RuntimeException('A requested quality sample contains no frames.');
                }
            }

            return $ranges;
        } finally {
            fclose($source);
            fclose($output);
        }
    }

    private function line(string $line): void
    {
        if (! preg_match('/^frames\.frame\.(\d+)\.(best_effort_timestamp_time|duration_time|pkt_duration_time)=(.*)$/D', $line, $match)) {
            return;
        }
        $index = (int) $match[1];
        if ($this->index !== $index) {
            $this->save();
            if ($index !== $this->frames) {
                throw new RuntimeException('The probe returned discontinuous frame indices.');
            }
            $this->index = $index;
            $this->frame = [];
        }
        $value = json_decode($match[3], true, flags: JSON_THROW_ON_ERROR);
        if (is_numeric($value) && is_finite((float) $value)) {
            $this->frame[$match[2]] = (float) $value;
        }
    }

    private function save(): void
    {
        if ($this->index === null) {
            return;
        }
        $timestamp = $this->frame['best_effort_timestamp_time'] ?? null;
        if ($timestamp === null || ($this->last !== null && $timestamp <= $this->last)) {
            throw new RuntimeException('Decoded video timestamps are missing or not increasing.');
        }
        $this->first ??= $timestamp;
        $this->last = $timestamp;
        $this->lastDuration = $this->frame['duration_time'] ?? $this->frame['pkt_duration_time'] ?? $this->fallbackDuration;
        if ($this->lastDuration <= 0) {
            $this->lastDuration = $this->fallbackDuration;
        }
        $row = sprintf('%.9F', $timestamp)."\n";
        if (fwrite($this->stream, $row) !== strlen($row)) {
            throw new RuntimeException('Could not persist the frame timing manifest.');
        }
        $this->frames++;
    }
}
