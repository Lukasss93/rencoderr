<?php

namespace App\Transcoding;

use RuntimeException;

class SourceByteInventory
{
    private string $pending = '';

    /** @var array<int, int> */
    private array $bytes = [];

    public function push(string $chunk): void
    {
        foreach (explode("\n", $chunk) as $index => $part) {
            if ($index > 0) {
                $this->line($this->pending);
                $this->pending = '';
            }
            $this->pending .= $part;
            if (strlen($this->pending) > 8192) {
                throw new RuntimeException('Packet inventory field exceeds its size limit.');
            }
        }
    }

    /** @return array<int, int> */
    public function finish(): array
    {
        $this->line($this->pending);
        $this->pending = '';
        if ($this->bytes === []) {
            throw new RuntimeException('Source packet inventory is empty.');
        }

        return $this->bytes;
    }

    private function line(string $line): void
    {
        if (trim($line) === '') {
            return;
        }
        if (! preg_match('/(?:^|\\|)stream_index=(\\d+)(?:\\||$)/', $line, $index)
            || ! preg_match('/(?:^|\\|)size=(\\d+)(?:\\||$)/', $line, $size)
            || (float) $size[1] > PHP_INT_MAX - ($this->bytes[(int) $index[1]] ?? 0)) {
            throw new RuntimeException('Source packet inventory contains an unknown or invalid size.');
        }
        $stream = (int) $index[1];
        $this->bytes[$stream] = ($this->bytes[$stream] ?? 0) + (int) $size[1];
    }
}
