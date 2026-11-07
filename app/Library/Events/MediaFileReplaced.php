<?php

namespace App\Library\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class MediaFileReplaced implements ShouldDispatchAfterCommit
{
    public function __construct(public int $mediaFileId) {}
}
