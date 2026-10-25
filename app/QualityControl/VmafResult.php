<?php

namespace App\QualityControl;

use RuntimeException;
use XMLReader;

class VmafResult
{
    /** @return array{frames: int, mean: float, min: float, max: float} */
    public function read(string $path, int $expectedFrames): array
    {
        if ($expectedFrames < 1 || ! is_readable($path)) {
            throw new RuntimeException('VMAF produced no readable frame results.');
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;
        try {
            if (! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('VMAF result could not be opened.');
            }
            $frames = 0;
            $total = 0.0;
            $minimum = INF;
            $maximum = -INF;
            $root = false;
            $closed = false;
            while ($reader->read()) {
                if (libxml_get_errors() !== [] || $reader->nodeType === XMLReader::DOC_TYPE) {
                    throw new RuntimeException('VMAF returned invalid XML.');
                }
                if ($reader->depth === 0 && $reader->name === 'VMAF') {
                    $root = $root || $reader->nodeType === XMLReader::ELEMENT;
                    $closed = $reader->nodeType === XMLReader::END_ELEMENT;
                }
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'frame') {
                    continue;
                }
                $value = $reader->getAttribute('vmaf');
                if (! $root || $reader->depth !== 2 || $reader->getAttribute('frameNum') !== (string) $frames
                    || ! is_numeric($value) || ! is_finite((float) $value) || $frames >= $expectedFrames) {
                    throw new RuntimeException('VMAF returned missing, invalid or out-of-order frame scores.');
                }
                $score = (float) $value;
                $total += $score;
                $minimum = min($minimum, $score);
                $maximum = max($maximum, $score);
                $frames++;
            }
            if (! $closed || $frames !== $expectedFrames || libxml_get_errors() !== [] || ! is_finite($total)) {
                throw new RuntimeException('VMAF returned an incomplete result or a different number of frames.');
            }

            return ['frames' => $frames, 'mean' => $total / $frames, 'min' => $minimum, 'max' => $maximum];
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
