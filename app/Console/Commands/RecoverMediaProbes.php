<?php

namespace App\Console\Commands;

use App\Encoding\Actions\RecoverMediaProbes as RecoverProbes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('media:recover-probes')]
#[Description('Recover unfinished probes during exclusive startup, before starting workers')]
class RecoverMediaProbes extends Command
{
    public function handle(RecoverProbes $recover): int
    {
        try {
            $this->info('Recovered '.$recover->handle().' media probes.');
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Probe recovery failed. Startup must be retried before starting workers.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
