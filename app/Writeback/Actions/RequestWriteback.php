<?php

namespace App\Writeback\Actions;

use App\Jobs\WriteMediaFile;
use App\Models\MediaQualityCheck;
use App\Models\MediaWriteback;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\OutputContainer;
use App\Transcoding\StagingArea;
use App\Writeback\Enums\WritebackMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RequestWriteback
{
    public function __construct(private StagingArea $staging) {}

    public function handle(MediaQualityCheck $check): ?MediaWriteback
    {
        return DB::transaction(function () use ($check): ?MediaWriteback {
            $check = MediaQualityCheck::query()->lockForUpdate()->findOrFail($check->id);
            $item = $check->transcode()->with('mediaFile.libraryPath')->firstOrFail();
            if ($check->status !== TaskStatus::COMPLETED || $check->verdict !== QualityVerdict::PASSED
                || $item->status !== TaskStatus::COMPLETED || ! $item->writeback_mode instanceof WritebackMode
                || ! is_string($check->source_fingerprint) || ! is_string($check->output_fingerprint)
                || $item->mediaFile?->libraryPath === null) {
                return null;
            }
            $existing = MediaWriteback::query()->where('media_transcode_id', $item->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $container = OutputContainer::tryFrom(pathinfo($check->output_path, PATHINFO_EXTENSION));
            $expected = $container === null ? null : $this->staging->directory($item->id, $check->transcode_attempt_token).'/'.$container->filename();
            $source = rtrim($item->mediaFile->libraryPath->path, '/').'/'.$item->mediaFile->relative_path;
            if ($expected === null || $check->output_path !== $expected || $item->output_path !== $expected
                || $item->attempt_token !== $check->transcode_attempt_token || $source !== $check->source_path) {
                throw new RuntimeException('The verified output or source no longer matches the transcode.');
            }
            $token = (string) Str::uuid();
            $stem = pathinfo($source, PATHINFO_FILENAME);
            $directory = dirname($source);
            $writeback = MediaWriteback::query()->create([
                'media_transcode_id' => $item->id, 'media_quality_check_id' => $check->id,
                'status' => TaskStatus::QUEUED, 'mode' => $item->writeback_mode, 'phase' => 'copying', 'request_token' => $token,
                'source_path' => $source, 'staging_path' => $this->staging->root().'/'.$expected,
                'candidate_path' => $directory.'/'.$stem.'.rencoderr-'.$token.'.pending.'.$container->value,
                'destination_path' => $directory.'/'.$stem.'.'.$container->value,
                'backup_path' => $source.'.rencoderr-'.$token.'.backup',
                'source_fingerprint' => $check->source_fingerprint, 'output_fingerprint' => $check->output_fingerprint,
                'comparison' => $check->results['writeback_comparison'] ?? [
                    'source' => ['path' => $source, 'size_bytes' => $check->source_size_bytes, 'container' => pathinfo($source, PATHINFO_EXTENSION), 'streams' => []],
                    'output' => ['path' => $directory.'/'.$stem.'.'.$container->value, 'size_bytes' => $check->output_size_bytes, 'container' => $container->value, 'streams' => []],
                    'savings_percent' => $check->savings_percent, 'vmaf_mean' => $check->vmaf_mean,
                ],
            ]);
            DB::afterCommit(fn () => rescue(fn () => WriteMediaFile::dispatch($writeback->id, $token)));

            return $writeback;
        });
    }
}
