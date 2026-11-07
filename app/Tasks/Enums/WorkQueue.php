<?php

namespace App\Tasks\Enums;

enum WorkQueue: string
{
    case SCANS = 'scans';
    case PROBES = 'probes';
    case DEFAULT = 'default';
    case WRITEBACK = 'writeback';
    case TRANSCODES = 'transcodes';
    case QUALITY_CONTROL = 'quality-control';
}
