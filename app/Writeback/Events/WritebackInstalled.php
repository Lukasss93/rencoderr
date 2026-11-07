<?php

namespace App\Writeback\Events;

readonly class WritebackInstalled
{
    public function __construct(public int $writebackId) {}
}
