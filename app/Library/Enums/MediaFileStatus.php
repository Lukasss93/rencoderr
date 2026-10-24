<?php

namespace App\Library\Enums;

enum MediaFileStatus: string
{
    case PRESENT = 'present';
    case MISSING = 'missing';
    case EXCLUDED = 'excluded';
}
