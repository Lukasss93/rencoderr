<?php

namespace App\Probe\Exceptions;

use RuntimeException;
use Throwable;

class ProbeFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
