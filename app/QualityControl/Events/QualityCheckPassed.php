<?php

namespace App\QualityControl\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

readonly class QualityCheckPassed implements ShouldDispatchAfterCommit
{
    public function __construct(public int $checkId) {}
}
