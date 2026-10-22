<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class BlockingContainerJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function handle(): void
    {
        Log::info('Container worker log probe');
        file_put_contents('/config/storage/framework/blocking-job-started', 'started');
        sleep(1800);
    }
}
