<?php

namespace App\Transcoding;

enum TranscodeOutcome
{
    case COMPLETED;
    case FAILED;
    case SKIPPED;
    case CANCELLED;
    case INTERRUPTED;
    case STALE;
}
