<?php

namespace App\Tasks\Enums;

enum TaskStatus: string
{
    case PENDING = 'pending';
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function isActive(): bool
    {
        return $this === self::QUEUED || $this === self::RUNNING;
    }
}
