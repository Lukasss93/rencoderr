<?php

namespace App\QualityControl;

class QualityRejected extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
