<?php

namespace App\Library\Events;

use Illuminate\Foundation\Events\Dispatchable;

class LibraryPathScanned
{
    use Dispatchable;

    public function __construct(public readonly int $pathId, public readonly string $token) {}
}
