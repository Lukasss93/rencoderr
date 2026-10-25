<?php

namespace App\Transcoding\Actions;

use App\Encoding\Enums\HdrMode;
use App\Encoding\MediaPreferences;
use App\Library\MediaFilePath;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\MediaStream;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\QualityControl\QualityPolicy;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Events\TranscodeCompleted;
use App\Transcoding\HdrInspection;
use App\Transcoding\OptimizationPolicy;
use App\Transcoding\SkippedTranscode;
use App\Transcoding\StagingArea;
use App\Transcoding\TranscodeInterrupted;
use App\Transcoding\TranscodePlan;
use App\Transcoding\TranscodeProcess;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RunTranscode
{
    public function __construct(
        private BuildTranscodePlan $builder,
        private MediaPreferences $preferences,
        private MediaFilePath $paths,
        private TranscodeProcess $process,
        private StagingArea $staging,
        private FinalizeTranscodeRun $finalize,
        private OptimizeTranscode $optimizer,
        private OptimizationPolicy $optimizationPolicy,
        private QualityPolicy $qualityPolicy,
    ) {}

    public function handle(int $transcodeId, string $token): void
    {
        $attempt = (string) Str::uuid();
        $current = fn (): Builder => MediaTranscode::query()->forRequest($transcodeId, $token)
            ->where('attempt_token', $attempt)->where('status', TaskStatus::RUNNING);
        $claimed = MediaTranscode::query()->forRequest($transcodeId, $token)->where('status', TaskStatus::QUEUED)->update([
            'status' => TaskStatus::RUNNING, 'attempt_token' => $attempt, 'started_at' => now(),
            'phase' => 'preparing', 'optimization' => null,
            'execution_deadline_at' => now()->addSeconds((int) config('transcoding.timeout_seconds')),
            'progress_percent' => null, 'encoded_seconds' => null, 'error' => null, 'diagnostic_tail' => null, 'finished_at' => null,
        ]);
        $item = $claimed === 1 ? $current()->first() : null;
        if ($item === null) {
            return;
        }

        try {
            $skipped = $this->transcode($item, $current);
            $outcome = $skipped === null ? null
                : ['status' => TaskStatus::SKIPPED, 'skip_reason' => $skipped->reason, 'error' => $skipped->message];
        } catch (Throwable $exception) {
            $outcome = $this->cancelled($item) ? ['status' => TaskStatus::CANCELLED]
                : ['status' => TaskStatus::FAILED, 'error' => Str::limit($exception->getMessage(), 4000)];
        }
        if ($outcome !== null) {
            $this->staging->clean($item->id, $attempt);
            $current()->update([...$outcome, 'finished_at' => now()]);
        }
        $this->finalize->handle($item->transcode_run_id);
        if ($outcome === null && $item->refresh()->status === TaskStatus::COMPLETED) {
            DB::afterCommit(function () use ($item, $attempt): void {
                try {
                    event(new TranscodeCompleted($item->id, $attempt));
                } catch (Throwable $exception) {
                    // Recovery repairs the handoff; an accepted file must never enter encode cleanup.
                    report($exception);
                }
            });
        }
    }

    /**
     * Returns null once the output is accepted. Throws when the attempt fails, is cancelled or was replaced.
     *
     * @param  Closure(): Builder<MediaTranscode>  $current
     */
    private function transcode(MediaTranscode $item, Closure $current): ?SkippedTranscode
    {
        $file = $item->mediaFile()->with(['libraryPath.library', 'probe.streams'])->first();
        $library = $file?->libraryPath?->library;
        if ($file === null || $library === null || $this->cancelled($item)) {
            throw new TranscodeInterrupted('The run was cancelled or its source was deleted.');
        }
        if (! $this->unchanged($item, $file)) {
            return new SkippedTranscode('source_changed', 'The source or its metadata changed after admission.');
        }
        $plan = $this->plan($item, $file, $library, $current);
        if ($plan instanceof SkippedTranscode) {
            return $plan;
        }
        $output = $this->staging->prepare($item->id, (string) $item->attempt_token);
        $quality = $this->qualityPolicy->forEncoding($item);
        $quality['decoding'] = $plan->decoding?->toArray() ?? ['backend' => null, 'device' => null];
        $policy = $this->optimizationPolicy->snapshot($plan->settings);
        $this->updateOwned($current, ['optimization' => ['enabled' => $policy['enabled'], 'quality_policy' => $quality]]);
        if ($policy['enabled']) {
            $directory = dirname($output).'/optimization';
            try {
                $plan = $this->optimizer->handle([$plan], $policy, $quality, $directory,
                    microtime(true) + $this->remaining($item), $this->stopCheck($item, $current),
                    function (array $update) use ($current): void {
                        if (strlen(json_encode($update, JSON_THROW_ON_ERROR)) > 262144) {
                            throw new RuntimeException('The optimization audit exceeded its size limit.');
                        }
                        $this->updateOwned($current, $update);
                    });
            } finally {
                $relative = $this->staging->directory($item->id, (string) $item->attempt_token).'/optimization';
                if ($this->staging->disk()->directoryExists($relative) && ! $this->staging->disk()->deleteDirectory($relative)) {
                    throw new RuntimeException('Could not clean optimization samples.');
                }
            }
            if ($plan instanceof SkippedTranscode) {
                return $plan;
            }
        }
        $prepared = $current()->firstOrFail();
        $this->staging->ensureSpace(dirname($output), $prepared->optimization['winner']['bytes'] ?? $plan->sourceSizeBytes);
        $this->updateOwned($current, ['phase' => 'encoding', 'encoder' => $plan->encoder->value, 'arguments' => $plan->arguments, 'stream_decisions' => $plan->streamDecisions]);
        $duration = $file->probe?->duration_seconds;
        $result = $this->process->run($plan->arguments, $output, $this->remaining($item),
            $duration === null ? null : (float) $duration, $this->stopCheck($item, $current), $this->progress($current));
        if ($result->exitCode !== 0) {
            throw new RuntimeException('FFmpeg exited with code '.$result->exitCode.'. '.$result->diagnosticTail);
        }

        return $this->accept($item, $current);
    }

    /** @param Closure(): Builder<MediaTranscode> $current */
    private function plan(MediaTranscode $item, MediaFile $file, Library $library, Closure $current): TranscodePlan|SkippedTranscode
    {
        $settings = $this->preferences->forLibrary($library);
        DB::transaction(function () use ($current, $settings): void {
            $owned = $current()->lockForUpdate()->firstOrFail();
            $owned->settings()->delete();
            foreach ($settings->toArray() as $field => $value) {
                $owned->settings()->create(['key' => 'media.'.$field, 'value' => $value]);
            }
        });
        $video = $file->probe?->streams->first(fn (MediaStream $stream): bool => $stream->codec_type === 'video' && ! $stream->is_attached_picture);
        if ($settings->hdrMode === HdrMode::PRESERVE && $video !== null
            && ! in_array($video->codec_name, $settings->skipVideoCodecs, true)
            && in_array($video->color_transfer, ['smpte2084', 'arib-std-b67'], true)) {
            $inspection = $this->process->inspectHdr($item->source_path, $video->stream_index, $this->remaining($item),
                $this->stopCheck($item, $current), $this->progress($current));
            if ($inspection->unsupported()) {
                return new SkippedTranscode('unsupported_hdr_metadata', 'Dynamic or changing HDR metadata cannot be preserved safely.');
            }
            $this->includeFrameMetadata($file, $video->stream_index, $inspection);
        }
        $plan = $this->builder->handle($file, $settings);
        if ($plan instanceof TranscodePlan && ($plan->sourcePath !== $item->source_path
            || $plan->sourceSizeBytes !== $item->source_size_bytes || ! $plan->sourceModifiedAt->equalTo($item->source_modified_at))) {
            return new SkippedTranscode('source_changed', 'The source changed during preparation.');
        }

        return $plan;
    }

    /**
     * The encoder polls this every 100ms, so the database is checked only every five seconds.
     *
     * @param  Closure(): Builder<MediaTranscode>  $current
     * @return Closure(): bool
     */
    private function stopCheck(MediaTranscode $item, Closure $current): Closure
    {
        $lastCheck = 0.0;

        return function () use ($item, $current, &$lastCheck): bool {
            if (microtime(true) - $lastCheck < 5) {
                return false;
            }
            $lastCheck = microtime(true);

            return ! $current()->exists() || $this->cancelled($item);
        };
    }

    /**
     * @param  Closure(): Builder<MediaTranscode>  $current
     * @return Closure(?float, float, string): void
     */
    private function progress(Closure $current): Closure
    {
        return function (?float $percent, float $seconds, string $diagnostics) use ($current): void {
            $current()->update(['progress_percent' => $percent, 'encoded_seconds' => $seconds, 'diagnostic_tail' => substr($diagnostics, -65536)]);
        };
    }

    private function remaining(MediaTranscode $item): int
    {
        $remaining = (int) floor(($item->execution_deadline_at?->getTimestamp() ?? 0) - microtime(true));
        if ($remaining < 1) {
            throw new RuntimeException('The transcode execution deadline expired during preparation.');
        }

        return $remaining;
    }

    /** @param Closure(): Builder<MediaTranscode> $current
     * @param  array<string, mixed>  $attributes
     */
    private function updateOwned(Closure $current, array $attributes): void
    {
        $serialized = (new MediaTranscode)->fill($attributes)->getAttributes();
        if ($current()->update($serialized) !== 1) {
            throw new TranscodeInterrupted('The transcode attempt no longer owns this job.');
        }
    }

    private function includeFrameMetadata(MediaFile $file, int $streamIndex, HdrInspection $inspection): void
    {
        $probe = $file->probe;
        if ($probe === null) {
            return;
        }
        $metadata = $probe->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];
        $streams = $metadata['streams'] ?? [];
        $streams = is_array($streams) ? $streams : [];
        $found = false;
        foreach ($streams as &$stream) {
            if (is_array($stream) && ($stream['index'] ?? null) === $streamIndex) {
                $sides = $stream['side_data_list'] ?? [];
                $sides = is_array($sides) ? $sides : [];
                foreach ($inspection->sideData() as $side) {
                    $sides = array_values(array_filter($sides, fn ($existing): bool => ! is_array($existing) || ($existing['side_data_type'] ?? null) !== $side['side_data_type']));
                    $sides[] = $side;
                }
                $stream['side_data_list'] = $sides;
                $found = true;
            }
        }
        unset($stream);
        if (! $found) {
            $streams[] = ['index' => $streamIndex, 'side_data_list' => $inspection->sideData()];
        }
        $probe->setAttribute('metadata', [...$metadata, 'streams' => $streams]);
    }

    /** @phpstan-impure */
    private function cancelled(MediaTranscode $item): bool
    {
        return $item->run()->where(fn ($query) => $query->whereNotNull('cancel_requested_at')->orWhereNull('library_id'))->exists()
            || ! MediaFile::query()->whereKey($item->media_file_id)->exists();
    }

    private function unchanged(MediaTranscode $item, MediaFile $file): bool
    {
        if (! $file->hasCurrentProbe() || $file->size_bytes !== $item->source_size_bytes
            || ! $file->filesystem_modified_at->equalTo($item->source_modified_at)) {
            return false;
        }
        try {
            return $this->paths->resolve($file) === $item->source_path && $this->paths->isUnchanged($file, $item->source_path);
        } catch (RuntimeException|\InvalidArgumentException) {
            return false;
        }
    }

    /** @param Closure(): Builder<MediaTranscode> $current */
    private function accept(MediaTranscode $item, Closure $current): ?SkippedTranscode
    {
        return DB::transaction(function () use ($item, $current): ?SkippedTranscode {
            $run = TranscodeRun::query()->whereKey($item->transcode_run_id)->lockForUpdate()->firstOrFail();
            $file = MediaFile::query()->with(['libraryPath', 'probe'])->lockForUpdate()->find($item->media_file_id);
            $owned = $current()->lockForUpdate()->first();
            if ($owned === null || $run->cancel_requested_at !== null || $run->library_id === null || $file === null) {
                throw new TranscodeInterrupted('The attempt was cancelled or replaced before its output was accepted.');
            }
            if (! $this->unchanged($item, $file)) {
                return new SkippedTranscode('source_changed', 'The source changed during encoding.');
            }
            if ($owned->execution_deadline_at === null || $owned->execution_deadline_at->isPast()) {
                throw new RuntimeException('The transcode execution deadline expired.');
            }
            $output = $this->staging->accept($item->id, (string) $item->attempt_token);
            $owned->update(['status' => TaskStatus::COMPLETED, 'output_path' => $output,
                'output_size_bytes' => $this->staging->disk()->size($output), 'progress_percent' => 100, 'finished_at' => now()]);

            return null;
        });
    }
}
