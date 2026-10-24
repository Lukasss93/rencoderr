<?php

namespace App\Ffmpeg;

use Illuminate\Support\Str;

readonly class CommandResult
{
    public function __construct(public bool $successful, public string $output, public string $error) {}

    /**
     * Needs the command to write progress with -progress pipe:1.
     */
    public function producedFrame(): bool
    {
        return $this->successful && preg_match('/^frame=\s*[1-9]\d*\s*$/m', $this->output) === 1;
    }

    public function errorMessage(string $fallback): string
    {
        return Str::limit(trim($this->error) ?: $fallback, 2000);
    }
}
