<?php

namespace App\Console\Commands;

use App\Library\Enums\MediaFileStatus;
use App\Models\MediaFile;
use App\Probe\Actions\RequestProbe;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('media:probe {fileId : The media file ID to probe or retry}')]
#[Description('Request a probe for a present media file')]
class ProbeMedia extends Command
{
    public function handle(RequestProbe $requests): int
    {
        $id = filter_var($this->argument('fileId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $file = $id === false ? null : MediaFile::query()->find($id);
        if ($file === null || $file->status !== MediaFileStatus::PRESENT) {
            $this->error('Choose an existing, present media file.');

            return self::FAILURE;
        }
        if ($requests->retry($file->id)) {
            $probe = $file->probe()->firstOrFail();
            if ($probe->error !== null) {
                $this->error('The probe could not be dispatched. Its pending request was retained.');

                return self::FAILURE;
            }
            $this->info('Media probe requested.');
        } else {
            $this->info('A media probe is already active.');
        }

        return self::SUCCESS;
    }
}
