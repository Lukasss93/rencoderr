<?php

namespace App\Transcoding;

use RuntimeException;

class HdrInspection
{
    private string $pending = '';

    private ?string $section = null;

    /** @var array<string, mixed> */
    private array $side = [];

    /** @var array<string, array<string, mixed>> */
    private array $static = [];

    private bool $unsupported = false;

    private bool $frames = false;

    public function push(string $chunk): void
    {
        foreach (explode("\n", $chunk) as $index => $part) {
            if ($index > 0) {
                $this->line($this->pending);
                $this->pending = '';
            }
            $this->pending .= $part;
            if (strlen($this->pending) > 4096) {
                throw new RuntimeException('The HDR inspection returned an oversized metadata field.');
            }
        }
    }

    public function finish(): void
    {
        if ($this->pending !== '') {
            $this->line($this->pending);
            $this->pending = '';
        }
        $this->saveSide();
        $this->section = null;
        $this->side = [];
    }

    public function unsupported(): bool
    {
        return $this->unsupported;
    }

    public function hasFrames(): bool
    {
        return $this->frames;
    }

    /** @return list<array<string, mixed>> */
    public function sideData(): array
    {
        return array_values($this->static);
    }

    /** @return array<string, array<string, mixed>> */
    public function normalizedSideData(): array
    {
        $result = array_map($this->normalize(...), $this->static);
        ksort($result);

        return $result;
    }

    /** @param array<string, mixed> $side
     * @return array<string, mixed>
     */
    private function normalize(array $side): array
    {
        foreach ($side as &$value) {
            if (is_string($value) && preg_match('/^(-?\d+)\/([1-9]\d*)$/D', $value, $parts)) {
                $value = round((float) $parts[1] / (float) $parts[2], 9);
            }
        }
        unset($value);
        ksort($side);

        return $side;
    }

    private function line(string $line): void
    {
        if (str_starts_with($line, 'frames.frame.')) {
            $this->frames = true;
        }
        if (! preg_match('/^((?:frames\.frame|streams\.stream)\.\d+\.side_data_list\.side_data\.\d+)\.(\w+)=(.*)$/D', $line, $parts)) {
            return;
        }
        if ($parts[1] !== $this->section) {
            $this->saveSide();
            $this->side = [];
            $this->section = $parts[1];
        }
        $value = json_decode($parts[3], true, flags: JSON_THROW_ON_ERROR);
        $this->side[$parts[2]] = $value;
        if ($parts[2] === 'side_data_type' && is_string($value) && preg_match('/hdr|dovi|dolby|smpte.?2094/i', $value)) {
            $this->unsupported = true;
        }
    }

    private function saveSide(): void
    {
        $type = $this->side['side_data_type'] ?? null;
        if (! is_string($type) || ! in_array($type, ['Mastering display metadata', 'Content light level metadata'], true)) {
            return;
        }
        if (isset($this->static[$type]) && $this->normalize($this->static[$type]) !== $this->normalize($this->side)) {
            $this->unsupported = true;
        }
        $this->static[$type] = $this->side;
    }
}
