<?php

namespace App\Console\Commands;

use App\Library\Actions\RecoverScans;
use App\Probe\Actions\RecoverProbes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tasks:recover')]
#[Description('Requeue interrupted scans and probes during exclusive startup, before starting workers')]
class RecoverTasks extends Command
{
    public function handle(RecoverScans $scans, RecoverProbes $probes): int
    {
        try {
            $this->info('Recovered '.$scans->handle().' folder scans.');
            $this->info('Recovered '.$probes->handle().' media probes.');
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Recovery failed. Startup must be retried before starting workers.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
