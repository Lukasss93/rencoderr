<?php

namespace App\Console\Commands;

use App\Library\Actions\RecoverScans;
use App\Library\LibraryPathLock;
use App\Models\LibraryPath;
use App\Probe\Actions\RecoverProbes;
use App\QualityControl\Actions\RecoverQualityChecks;
use App\Transcoding\Actions\RecoverTranscodes;
use App\Writeback\Actions\RecoverWritebacks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tasks:recover')]
#[Description('Requeue interrupted scans, probes, transcodes and quality checks during exclusive startup, before starting workers')]
class RecoverTasks extends Command
{
    public function handle(RecoverScans $scans, RecoverProbes $probes, RecoverTranscodes $transcodes, RecoverQualityChecks $quality, RecoverWritebacks $writebacks): int
    {
        try {
            foreach (LibraryPath::query()->select('id')->lazyById() as $path) {
                LibraryPathLock::forPath($path->id)->forceRelease();
            }
            $this->info('Recovered '.$scans->handle().' folder scans.');
            $this->info('Recovered '.$probes->handle().' media probes.');
            $this->info('Recovered '.$transcodes->handle().' media transcodes.');
            $this->info('Recovered '.$quality->handle().' quality checks.');
            $this->info('Recovered '.$writebacks->handle().' file write-backs.');
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Recovery failed. Startup must be retried before starting workers.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
