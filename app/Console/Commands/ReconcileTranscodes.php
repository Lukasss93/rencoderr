<?php

namespace App\Console\Commands;

use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Transcoding\Actions\RecoverTranscodes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('transcodes:reconcile {--apply : Reconcile interrupted work} {--json : Print JSON} {--plain : Print plain text}')]
#[Description('Inspect transcode recovery; use --apply to resume interrupted publication and eligible work')]
class ReconcileTranscodes extends Command
{
    public function handle(RecoverTranscodes $recover): int
    {
        $apply = (bool) $this->option('apply');
        $recovered = $apply ? $recover->handle() : 0;
        $data = ['dry_run' => ! $apply, 'active_runs' => TranscodeRun::query()->active()->count(),
            'active_files' => MediaTranscode::query()->active()->count(), 'recovered_files' => $recovered];
        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_THROW_ON_ERROR));
        } elseif ($this->option('plain')) {
            foreach ($data as $key => $value) {
                $this->line($key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : $value));
            }
        } else {
            $this->info($apply ? 'Transcode reconciliation complete.' : 'Dry run. Use --apply to reconcile interrupted work.');
            $this->table(['Active runs', 'Active files', 'Recovered files'], [[$data['active_runs'], $data['active_files'], $recovered]]);
        }

        return self::SUCCESS;
    }
}
