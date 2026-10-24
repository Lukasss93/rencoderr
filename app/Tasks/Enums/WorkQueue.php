<?php

namespace App\Tasks\Enums;

/**
 * Workers take jobs from these queues in case order.
 */
enum WorkQueue: string
{
    case SCANS = 'scans';
    case PROBES = 'probes';
    case DEFAULT = 'default';
}
