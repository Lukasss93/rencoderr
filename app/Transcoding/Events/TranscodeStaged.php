<?php

namespace App\Transcoding\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TranscodeStaged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $transcodeId) {}
}
