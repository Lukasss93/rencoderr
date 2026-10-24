<?php

use App\Models\Library;
use App\Models\MediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

it('retains transcode records and their settings when inventory is deleted', function (): void {
    $file = MediaFile::factory()->create();
    $library = $file->libraryPath()->firstOrFail()->library()->firstOrFail();
    $run = TranscodeRun::factory()->for($library)->create();
    $item = MediaTranscode::factory()->for($run, 'run')->for($file)->create();
    $item->settings()->create(['key' => 'media.minimum_vmaf', 'value' => 95]);
    $library->delete();
    expect($run->refresh()->library_id)->toBeNull();
    expect($item->refresh()->media_file_id)->toBeNull();
    expect($item->settings()->sole()->settingable_type)->toBe('media_transcode');
    expect($item->settings()->sole()->value)->toBe(95);
});

it('allows only one active run per library while retaining finished runs', function (TaskStatus $status): void {
    $library = Library::factory()->create();
    TranscodeRun::factory()->for($library)->create(['status' => TaskStatus::COMPLETED]);
    TranscodeRun::factory()->for($library)->create(['status' => $status]);
    expect(fn () => TranscodeRun::factory()->for($library)->create())->toThrow(UniqueConstraintViolationException::class);
})->with([TaskStatus::PENDING, TaskStatus::QUEUED, TaskStatus::RUNNING]);

it('admits a file only once in a run', function (): void {
    $item = MediaTranscode::factory()->create();
    expect(fn () => MediaTranscode::factory()->create([
        'transcode_run_id' => $item->transcode_run_id, 'media_file_id' => $item->media_file_id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('keeps identity then status then data then timestamps in transcode tables', function (): void {
    expect(Schema::getColumnListing('transcode_runs'))->toBe([
        'id', 'library_id', 'retry_of_run_id', 'request_token', 'status', 'library_name',
        'inventory_cutoff_id', 'inventory_cursor_id', 'excluded_metadata_count', 'retained_output_count', 'error',
        'enumeration_finished_at', 'cancel_requested_at', 'started_at', 'finished_at', 'created_at', 'updated_at',
    ]);
    expect(Schema::getColumnListing('media_transcodes'))->toBe([
        'id', 'transcode_run_id', 'media_file_id', 'file_identity_id', 'request_token', 'attempt_token', 'unique_lock_owner',
        'status', 'source_path', 'source_size_bytes', 'execution_limit_seconds', 'encoder', 'arguments',
        'stream_decisions', 'output_path', 'output_size_bytes', 'progress_percent', 'encoded_seconds',
        'diagnostic_tail', 'error', 'skip_reason', 'source_modified_at', 'dispatch_intended_at', 'dispatched_at',
        'execution_deadline_at', 'heartbeat_at', 'started_at', 'finished_at', 'created_at', 'updated_at',
    ]);
});
