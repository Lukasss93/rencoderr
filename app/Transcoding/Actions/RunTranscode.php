<?php

namespace App\Transcoding\Actions;

use App\Encoding\Enums\HdrMode;
use App\Encoding\MediaPreferences;
use App\Library\MediaFilePath;
use App\Models\MediaFile;
use App\Models\MediaStream;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Events\TranscodeStaged;
use App\Transcoding\HdrInspection;
use App\Transcoding\SkippedTranscode;
use App\Transcoding\StagingArea;
use App\Transcoding\TranscodeInterrupted;
use App\Transcoding\TranscodeOutcome;
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
    ) {}

    public function stop(): void
    {
        $this->process->stop();
    }

    public function handle(int $transcodeId, string $requestToken, int $executionLimitSeconds, Closure $interrupted): TranscodeOutcome
    {
        $item = $this->claim($transcodeId, $requestToken, $executionLimitSeconds);
        if ($item === null) {
            return TranscodeOutcome::STALE;
        }
        $attempt = (string) $item->attempt_token;
        $current = fn (): Builder => MediaTranscode::query()->forRequest($transcodeId, $requestToken)->where('attempt_token', $attempt)->where('status', TaskStatus::RUNNING);
        $outcome = TranscodeOutcome::FAILED;
        $attributes = [];
        $accepted = false;
        $prepared = false;
        $safeToClean = true;

        $lastCheck = 0.0;
        $stop = function () use ($item, $current, $interrupted, &$lastCheck): bool {
            if ($interrupted()) {
                return true;
            }
            if (microtime(true) - $lastCheck < 1) {
                return false;
            }
            $lastCheck = microtime(true);

            return ! $current()->exists() || $this->cancelled($item);
        };
        $lastProgress = 0.0;
        $progress = function (?float $percent, float $seconds, string $diagnostics) use ($current, &$lastProgress): void {
            if (microtime(true) - $lastProgress < 5) {
                return;
            }
            $lastProgress = microtime(true);
            $current()->update(['progress_percent' => $percent, 'encoded_seconds' => $seconds,
                'diagnostic_tail' => substr($diagnostics, -65536), 'heartbeat_at' => now()]);
        };
        try {
            $file = $item->mediaFile()->with(['libraryPath.library', 'probe.streams'])->first();
            if ($file === null || $this->cancelled($item)) {
                $outcome = TranscodeOutcome::CANCELLED;
            } elseif (! $this->unchanged($item, $file)) {
                $outcome = TranscodeOutcome::SKIPPED;
                $attributes = ['skip_reason' => 'source_changed', 'error' => 'The source or its metadata changed after admission.'];
            } else {
                $library = $file->libraryPath?->library;
                if ($library === null) {
                    throw new TranscodeInterrupted('The source library was deleted.');
                }
                $settings = $this->preferences->forLibrary($library);
                DB::transaction(function () use ($current, $settings): void {
                    $owned = $current()->lockForUpdate()->firstOrFail();
                    $owned->settings()->delete();
                    foreach ($settings->toArray() as $field => $value) {
                        $owned->settings()->create(['key' => 'media.'.$field, 'value' => $value]);
                    }
                });
                $video = $file->probe?->streams->first(fn (MediaStream $stream): bool => $stream->codec_type === 'video' && ! $stream->is_attached_picture);
                $inspection = null;
                if ($settings->hdrMode === HdrMode::PRESERVE && $video !== null
                    && ! in_array($video->codec_name, $settings->skipVideoCodecs, true)
                    && in_array($video->color_transfer, ['smpte2084', 'arib-std-b67'], true)) {
                    $inspection = $this->process->inspectHdr($item->source_path, $video->stream_index, $this->remaining($item), $stop, $progress);
                    if (! $inspection->unsupported()) {
                        $this->includeFrameMetadata($file, $video->stream_index, $inspection);
                    }
                }
                $plan = $inspection?->unsupported()
                    ? new SkippedTranscode('unsupported_hdr_metadata', 'Dynamic or changing HDR metadata cannot be preserved safely.')
                    : $this->builder->handle($file, $settings);
                if (! $plan instanceof SkippedTranscode && ($plan->sourcePath !== $item->source_path
                    || $plan->sourceSizeBytes !== $item->source_size_bytes || ! $plan->sourceModifiedAt->equalTo($item->source_modified_at))) {
                    $plan = new SkippedTranscode('source_changed', 'The source changed during preparation.');
                }
                if ($plan instanceof SkippedTranscode) {
                    $outcome = TranscodeOutcome::SKIPPED;
                    $attributes = ['skip_reason' => $plan->reason, 'error' => $plan->message];
                } else {
                    DB::transaction(function () use ($current, $plan): void {
                        $owned = $current()->lockForUpdate()->firstOrFail();
                        $owned->update(['encoder' => $plan->encoder->value, 'arguments' => $plan->arguments, 'stream_decisions' => $plan->streamDecisions]);
                    });
                    $output = $this->staging->prepare($transcodeId, $attempt);
                    $prepared = true;
                    $remaining = $this->remaining($item);
                    $result = $this->process->run($plan->arguments, $output, $remaining,
                        $file->probe?->duration_seconds === null ? null : (float) $file->probe->duration_seconds, $stop, $progress);
                    $attributes['diagnostic_tail'] = substr($result->diagnosticTail, -65536);
                    if ($this->cancelled($item)) {
                        $outcome = TranscodeOutcome::CANCELLED;
                    } elseif ($interrupted()) {
                        $outcome = TranscodeOutcome::INTERRUPTED;
                    } elseif ($result->exitCode !== 0) {
                        throw new RuntimeException('FFmpeg exited with code '.$result->exitCode.'. '.$result->diagnosticTail);
                    } else {
                        $outcome = $this->accept($item, $current, $attributes);
                        $accepted = $outcome === TranscodeOutcome::COMPLETED;
                    }
                }
            }
        } catch (TranscodeInterrupted $exception) {
            $outcome = $this->cancelled($item) ? TranscodeOutcome::CANCELLED
                : ($interrupted() ? TranscodeOutcome::INTERRUPTED : TranscodeOutcome::STALE);
        } catch (Throwable $exception) {
            // A commit may have succeeded before a connection error reached the caller.
            $safeToClean = false;
            $accepted = MediaTranscode::query()->forRequest($transcodeId, $requestToken)->where('attempt_token', $attempt)->where('status', TaskStatus::COMPLETED)->exists();
            $safeToClean = true;
            $outcome = $accepted ? TranscodeOutcome::COMPLETED : TranscodeOutcome::FAILED;
            $attributes['error'] = Str::limit($exception->getMessage(), 4000);
        } finally {
            if (! $accepted && $safeToClean) {
                if ($prepared) {
                    $this->staging->clean($transcodeId, $attempt);
                }
                $status = match ($outcome) {
                    TranscodeOutcome::INTERRUPTED => TaskStatus::QUEUED,
                    TranscodeOutcome::CANCELLED => TaskStatus::CANCELLED,
                    TranscodeOutcome::SKIPPED => TaskStatus::SKIPPED,
                    default => TaskStatus::FAILED,
                };
                if ($outcome === TranscodeOutcome::INTERRUPTED) {
                    $attributes['dispatched_at'] = null;
                    $attributes['dispatch_intended_at'] = now();
                }
                $current()->update([...$attributes, 'status' => $status,
                    'finished_at' => $outcome === TranscodeOutcome::INTERRUPTED ? null : now()]);
            }
            $this->finalize->handle($item->transcode_run_id);
        }

        return $outcome;
    }

    private function remaining(MediaTranscode $item): int
    {
        $remaining = (int) floor(($item->execution_deadline_at?->getTimestamp() ?? 0) - microtime(true));
        if ($remaining < 1) {
            throw new RuntimeException('The transcode execution deadline expired during preparation.');
        }

        return $remaining;
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

    private function claim(int $id, string $token, int $budget): ?MediaTranscode
    {
        return DB::transaction(function () use ($id, $token, $budget): ?MediaTranscode {
            $runId = MediaTranscode::query()->whereKey($id)->value('transcode_run_id');
            $run = TranscodeRun::query()->whereKey($runId)->lockForUpdate()->first();
            $item = MediaTranscode::query()->forRequest($id, $token)->where('status', TaskStatus::QUEUED)
                ->where('execution_limit_seconds', $budget)->lockForUpdate()->first();
            if ($item === null || $run === null || $run->cancel_requested_at !== null || $run->finished_at !== null) {
                return null;
            }
            $item->update(['status' => TaskStatus::RUNNING, 'attempt_token' => (string) Str::uuid(),
                'started_at' => now(), 'execution_deadline_at' => now()->addSeconds($budget), 'heartbeat_at' => now(),
                'progress_percent' => null, 'encoded_seconds' => null, 'error' => null, 'diagnostic_tail' => null, 'finished_at' => null]);

            return $item;
        });
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

    /** @param Closure(): Builder<MediaTranscode> $current
     * @param  array<string, mixed>  $attributes
     */
    private function accept(MediaTranscode $item, Closure $current, array $attributes): TranscodeOutcome
    {
        return DB::transaction(function () use ($item, $current, $attributes): TranscodeOutcome {
            $run = TranscodeRun::query()->whereKey($item->transcode_run_id)->lockForUpdate()->firstOrFail();
            $file = MediaFile::query()->with(['libraryPath', 'probe'])->lockForUpdate()->find($item->media_file_id);
            $owned = $current()->lockForUpdate()->first();
            if ($owned === null) {
                return TranscodeOutcome::STALE;
            }
            if ($run->cancel_requested_at !== null || $run->library_id === null || $file === null) {
                return TranscodeOutcome::CANCELLED;
            }
            if (! $this->unchanged($item, $file)) {
                $owned->update(['skip_reason' => 'source_changed', 'error' => 'The source changed during encoding.']);

                return TranscodeOutcome::SKIPPED;
            }
            if ($owned->execution_deadline_at === null || $owned->execution_deadline_at->isPast()) {
                throw new RuntimeException('The transcode execution deadline expired.');
            }
            $output = $this->staging->accept($item->id, (string) $item->attempt_token);
            $owned->update([...$attributes, 'status' => TaskStatus::COMPLETED, 'output_path' => $output,
                'output_size_bytes' => $this->staging->disk()->size($output), 'progress_percent' => 100, 'finished_at' => now(), 'heartbeat_at' => now()]);
            DB::afterCommit(fn () => rescue(fn () => TranscodeStaged::dispatch($item->id)));

            return TranscodeOutcome::COMPLETED;
        });
    }
}
