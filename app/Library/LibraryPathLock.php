<?php

namespace App\Library;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class LibraryPathLock
{
    public static function forPath(int $pathId, int $seconds = 0): Lock
    {
        return Cache::lock('library-path:'.$pathId, $seconds);
    }
}
