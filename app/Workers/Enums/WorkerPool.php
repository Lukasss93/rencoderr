<?php

namespace App\Workers\Enums;

enum WorkerPool: string
{
    case STANDARD = 'standard';
    case TRANSCODES = 'transcodes';

    public function supervisor(): string
    {
        return match ($this) {
            self::STANDARD => 'supervisor-1',
            self::TRANSCODES => 'supervisor-transcodes',
        };
    }

    public function settingKey(): string
    {
        return match ($this) {
            self::STANDARD => 'queue_max_workers',
            self::TRANSCODES => 'transcode_max_workers',
        };
    }
}
