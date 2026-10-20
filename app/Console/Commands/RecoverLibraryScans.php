<?php

namespace App\Console\Commands;

use App\Scan\Actions\RecoverLibraryScans as RecoverScans;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('libraries:recover-scans')]
#[Description('Recover pending scans during exclusive startup, before starting workers')]
class RecoverLibraryScans extends Command
{
    public function handle(RecoverScans $recoverScans): int
    {
        try {
            $this->info('Recovered '.$recoverScans->handle().' folder scans.');
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Scan recovery failed. Startup must be retried before starting workers.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
