<?php

use App\Library\FileFingerprint;
use App\Models\MediaQualityCheck;
use App\Models\MediaWriteback;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\StagingArea;
use App\Writeback\Enums\WritebackMode;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function writebackFixture(string $extension = 'mkv', WritebackMode $mode = WritebackMode::REPLACE): MediaWriteback
{
    $directory = mediaDirectory(['movie.mkv' => 'original media']);
    $disk = Storage::fake('transcodes');
    $source = $directory.'/movie.mkv';
    $check = MediaQualityCheck::factory()->create(['status' => TaskStatus::COMPLETED, 'verdict' => QualityVerdict::PASSED]);
    $transcode = $check->transcode()->firstOrFail();
    $relative = app(StagingArea::class)->directory($transcode->id, $check->transcode_attempt_token).'/output.'.$extension;
    $disk->put($relative, 'encoded media');
    $output = $disk->path($relative);
    $transcode->update(['source_path' => $source, 'output_path' => $relative]);
    $transcode->mediaFile()->firstOrFail()->update(['relative_path' => 'movie.mkv']);
    $check->update(['source_path' => $source, 'output_path' => $relative,
        'source_fingerprint' => FileFingerprint::capture($source), 'output_fingerprint' => FileFingerprint::capture($output)]);
    $transcode->mediaFile()->firstOrFail()->libraryPath()->firstOrFail()->update(['path' => $directory]);
    $token = (string) Str::uuid();

    return MediaWriteback::query()->create([
        'media_transcode_id' => $transcode->id, 'media_quality_check_id' => $check->id,
        'status' => TaskStatus::QUEUED, 'mode' => $mode, 'request_token' => $token, 'comparison' => [],
        'source_path' => $source, 'staging_path' => $output,
        'candidate_path' => $directory.'/movie.rencoderr-'.$token.'.pending.'.$extension,
        'destination_path' => $directory.'/movie.'.$extension, 'backup_path' => $source.'.rencoderr-'.$token.'.backup',
        'source_fingerprint' => FileFingerprint::capture($source), 'output_fingerprint' => FileFingerprint::capture($output),
    ]);
}
