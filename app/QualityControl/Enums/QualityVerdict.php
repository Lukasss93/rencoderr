<?php

namespace App\QualityControl\Enums;

enum QualityVerdict: string
{
    case PASSED = 'passed';
    case REJECTED = 'rejected';
}
