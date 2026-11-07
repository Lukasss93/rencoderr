<?php

namespace App\Probe\Listeners;

use App\Library\Events\MediaFileReplaced;
use App\Probe\Actions\RequestProbe;

class QueueProbeAfterReplacement
{
    public function __construct(private RequestProbe $probes) {}

    public function handle(MediaFileReplaced $event): void
    {
        $this->probes->retry($event->mediaFileId);
    }
}
