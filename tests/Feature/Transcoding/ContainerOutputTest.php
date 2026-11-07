<?php

use App\Jobs\TranscodeMediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\QualityControl\Actions\RequestQualityCheck;
use App\QualityControl\QualityWorkspace;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Actions\RequestTranscodeRun;
use App\Transcoding\OutputContainer;
use App\Transcoding\StagingArea;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TranscodeFixtures.php';

it('accepts a staged container and opens it for quality control', function (OutputContainer $container): void {
    Queue::fake();
    Storage::fake('transcodes');
    $file = transcodeSource();
    $attempt = (string) Str::uuid();
    $item = MediaTranscode::factory()->for($file)->create(['attempt_token' => $attempt]);
    $staging = app(StagingArea::class);

    $partial = $staging->prepare($item->id, $attempt, $container);
    expect($partial)->toEndWith('/output.partial.'.$container->value);
    file_put_contents($partial, 'encoded output');
    $relative = $staging->accept($item->id, $attempt, $container);
    expect($relative)->toBe($item->id.'/'.$attempt.'/output.'.$container->value);
    $item->update(['status' => TaskStatus::COMPLETED, 'output_path' => $relative, 'output_size_bytes' => 14,
        'source_path' => $file->libraryPath()->firstOrFail()->path.'/'.$file->relative_path,
        'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at]);
    $check = app(RequestQualityCheck::class)->handle($item);
    $check->update(['attempt_token' => (string) Str::uuid()]);

    $workspace = app(QualityWorkspace::class)->open($check);
    expect($workspace['output'])->toBe(realpath(Storage::disk('transcodes')->path($relative)));
    app(QualityWorkspace::class)->clean($check);
    $staging->clean($item->id, $attempt);
    Storage::disk('transcodes')->assertMissing($relative);
})->with([OutputContainer::MP4, OutputContainer::MKV]);

it('reuses only retained output in the container selected for an MP4 source', function (string $extension, int $retained): void {
    Queue::fake();
    Storage::fake('transcodes');
    $file = transcodeSource();
    $root = $file->libraryPath()->firstOrFail();
    rename($root->path.'/movie.mkv', $root->path.'/movie.mp4');
    $file->update(['relative_path' => 'movie.mp4']);
    $file->probe()->update(['format' => 'mov,mp4,m4a,3gp,3g2,mj2']);
    $previous = TranscodeRun::factory()->for($root->library()->firstOrFail())->create(['status' => TaskStatus::COMPLETED, 'finished_at' => now()]);
    $output = 'retained/output.'.$extension;
    MediaTranscode::factory()->for($previous, 'run')->for($file)->create([
        'status' => TaskStatus::COMPLETED, 'source_path' => $root->path.'/movie.mp4', 'source_size_bytes' => $file->size_bytes,
        'source_modified_at' => $file->filesystem_modified_at, 'output_path' => $output,
    ]);
    Storage::disk('transcodes')->put($output, 'retained');

    $run = app(RequestTranscodeRun::class)->forFile($file->refresh());

    expect($run->retained_output_count)->toBe($retained)->and($run->transcodes()->count())->toBe(1 - $retained);
    Queue::assertPushed(TranscodeMediaFile::class, 1 - $retained);
})->with([['mp4', 1], ['mkv', 0]]);

it('rejects staged paths that are not an exact accepted output filename', function (string $filename): void {
    Queue::fake();
    Storage::fake('transcodes');
    $file = transcodeSource();
    $attempt = (string) Str::uuid();
    $item = MediaTranscode::factory()->for($file)->create(['attempt_token' => $attempt, 'status' => TaskStatus::COMPLETED,
        'source_path' => $file->libraryPath()->firstOrFail()->path.'/'.$file->relative_path,
        'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at]);
    $path = $item->id.'/'.$attempt.'/'.$filename;
    Storage::disk('transcodes')->put($path, 'output');
    $item->update(['output_path' => $path, 'output_size_bytes' => 6]);
    $check = app(RequestQualityCheck::class)->handle($item);
    $check->update(['attempt_token' => (string) Str::uuid()]);

    expect(fn () => app(QualityWorkspace::class)->open($check))->toThrow(RuntimeException::class);
})->with(['output.partial.mp4', 'nested/output.mp4', 'output.avi']);
