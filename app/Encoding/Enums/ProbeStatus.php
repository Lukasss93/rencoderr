<?php

namespace App\Encoding\Enums;

enum ProbeStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Probing = 'probing';
    case Completed = 'completed';
    case Failed = 'failed';
}
