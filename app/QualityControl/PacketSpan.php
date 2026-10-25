<?php

namespace App\QualityControl;

use RuntimeException;

class PacketSpan
{
    private string $pending = '';

    private ?int $index = null;

    /** @var array<string, float> */
    private array $packet = [];

    private ?float $start = null;

    private ?float $end = null;

    public function push(string $chunk): void
    {
        foreach (explode("\n", $chunk) as $index => $part) {
            if ($index > 0) {
                $this->line($this->pending);
                $this->pending = '';
            }
            $this->pending .= $part;
            if (strlen($this->pending) > 4096) {
                throw new RuntimeException('Audio inspection returned an oversized packet field.');
            }
        }
    }

    /** @return array{start: float, end: float} */
    public function finish(): array
    {
        $this->line($this->pending);
        $this->save();
        if ($this->start === null || $this->end === null || $this->end <= $this->start) {
            throw new RuntimeException('Audio packet timing is unavailable.');
        }

        return ['start' => $this->start, 'end' => $this->end];
    }

    private function line(string $line): void
    {
        if (! preg_match('/^packets\.packet\.(\d+)\.(pts_time|duration_time)=(.*)$/D', $line, $match)) {
            return;
        }
        $index = (int) $match[1];
        if ($index !== $this->index) {
            $this->save();
            $this->index = $index;
            $this->packet = [];
        }
        $value = json_decode($match[3], true, flags: JSON_THROW_ON_ERROR);
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new RuntimeException('Audio packet timing is invalid.');
        }
        $this->packet[$match[2]] = (float) $value;
    }

    private function save(): void
    {
        if ($this->index === null) {
            return;
        }
        $pts = $this->packet['pts_time'] ?? null;
        $duration = $this->packet['duration_time'] ?? null;
        if ($pts === null || $duration === null || $duration <= 0) {
            throw new RuntimeException('Audio packet timing is incomplete.');
        }
        $this->start = min($this->start ?? $pts, $pts);
        $this->end = max($this->end ?? $pts, $pts + $duration);
    }
}
