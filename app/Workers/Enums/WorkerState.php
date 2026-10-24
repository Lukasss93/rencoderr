<?php

namespace App\Workers\Enums;

enum WorkerState: string
{
    case RUNNING = 'running';
    case PAUSED = 'paused';

    case OFFLINE = 'offline';

    case UNAVAILABLE = 'unavailable';

    public function label(): string
    {
        return $this === self::UNAVAILABLE ? 'Status unavailable' : ucfirst($this->value);
    }
}
