<?php

namespace App\Workers\Enums;

enum WorkerPool: string
{
    case STANDARD = 'standard';
    case TRANSCODES = 'transcodes';
    case QUALITY_CONTROL = 'quality-control';

    public function supervisor(): string
    {
        return match ($this) {
            self::STANDARD => 'supervisor-1',
            self::TRANSCODES => 'supervisor-transcodes',
            self::QUALITY_CONTROL => 'supervisor-quality-control',
        };
    }

    public function settingKey(): string
    {
        return match ($this) {
            self::STANDARD => 'queue_max_workers',
            self::TRANSCODES => 'transcode_max_workers',
            self::QUALITY_CONTROL => 'quality_control_max_workers',
        };
    }
}
