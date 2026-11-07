<?php

namespace App\Writeback\Enums;

enum WritebackMode: string
{
    case APPROVAL = 'approval';
    case REPLACE = 'replace';

    public function label(): string
    {
        return $this === self::APPROVAL ? 'Copy alongside for approval' : 'Replace automatically';
    }
}
