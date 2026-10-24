<?php

namespace App\Workers;

use App\Workers\Enums\WorkerState;

readonly class WorkerStatus
{
    public function __construct(public WorkerState $state, public ?int $limit = null, public ?int $workers = null) {}
}
