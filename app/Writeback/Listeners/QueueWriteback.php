<?php

namespace App\Writeback\Listeners;

use App\Models\MediaQualityCheck;
use App\QualityControl\Events\QualityCheckPassed;
use App\Writeback\Actions\RequestWriteback;

class QueueWriteback
{
    public function __construct(private RequestWriteback $requests) {}

    public function handle(QualityCheckPassed $event): void
    {
        $check = MediaQualityCheck::query()->find($event->checkId);
        if ($check !== null) {
            $this->requests->handle($check);
        }
    }
}
