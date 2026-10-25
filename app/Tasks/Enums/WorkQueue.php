<?php

namespace App\Tasks\Enums;

enum WorkQueue: string
{
    case SCANS = 'scans';
    case PROBES = 'probes';
    case DEFAULT = 'default';
    case TRANSCODES = 'transcodes';
    case QUALITY_CONTROL = 'quality-control';
}
