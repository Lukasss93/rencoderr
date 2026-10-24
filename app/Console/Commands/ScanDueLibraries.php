<?php

namespace App\Console\Commands;

use App\Library\Actions\RequestLibraryScan;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('libraries:scan-due')]
#[Description('Queue scans for libraries whose configured interval has elapsed')]
class ScanDueLibraries extends Command
{
    public function handle(RequestLibraryScan $requests): int
    {
        $this->info('Queued '.$requests->due().' folder scans.');

        return self::SUCCESS;
    }
}
