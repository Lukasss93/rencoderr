<?php

use App\Encoding\AccelerationPreference;
use App\Encoding\Data\Detection;
use App\Encoding\Data\Encoder;
use App\Encoding\Data\MediaSettings;
use App\Encoding\EncoderDetector;
use App\Encoding\Enums\EncoderType;
use App\Encoding\MediaPreferences;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Probe\ProbeResult;
use App\QualityControl\Actions\RequestQualityCheck;
use App\QualityControl\Actions\RunQualityCheck;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\Actions\RequestTranscodeRun;
use App\Transcoding\Actions\RunTranscode;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

it('encodes and verifies real subtitles and chapters in the selected container', function (string $extension, bool $mislabeled): void {
    Queue::fake();
    Storage::fake('transcodes');
    app(AccelerationPreference::class)->save('software');
    app(EncoderDetector::class)->store(new Detection(now()->toImmutable(), encoders: [
        new Encoder('libx265', 'hevc', EncoderType::VIDEO, 'HEVC'),
    ]));
    app(MediaPreferences::class)->saveGlobal(new MediaSettings(requireSmallerOutput: false, verifyVideoQuality: false));
    $directory = mediaDirectory([
        'subtitles.srt' => "1\n00:00:00,000 --> 00:00:01,500\nHello\n",
        'chapters.txt' => ";FFMETADATA1\n[CHAPTER]\nTIMEBASE=1/1000\nSTART=0\nEND=2000\ntitle=Opening\n",
    ]);
    $source = $directory.'/source.'.$extension;
    (new Process(['ffmpeg', '-v', 'error', '-nostdin', '-f', 'lavfi', '-i', 'testsrc2=size=64x64:rate=8:duration=2',
        '-f', 'lavfi', '-i', 'sine=duration=2:sample_rate=48000', '-i', $directory.'/subtitles.srt', '-i', $directory.'/subtitles.srt',
        '-f', 'ffmetadata', '-i', $directory.'/chapters.txt', '-map', '0:v', '-map', '1:a', '-map', '2:s', '-map', '3:s',
        '-map_chapters', '4', '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', $extension === 'mp4' ? 'aac' : 'pcm_s16le',
        '-c:s', $extension === 'mp4' ? 'mov_text' : 'subrip', '-metadata:s:s:0', 'language=eng',
        '-metadata:s:s:1', 'language=ita', '-disposition:s:0', 'default', '-disposition:s:1', 'forced', $source], timeout: 30))->mustRun();
    $probe = new Process(['ffprobe', '-v', 'error', '-show_streams', '-show_format', '-show_chapters', '-of', 'json', $source]);
    $probe->mustRun();
    $result = ProbeResult::fromJson($probe->getOutput());
    $root = LibraryPath::factory()->atPath($directory)->create();
    $file = MediaFile::factory()->for($root)->atPath('source.'.$extension)->create([
        'size_bytes' => filesize($source), 'filesystem_modified_at' => date('Y-m-d H:i:s', (int) filemtime($source)),
    ]);
    $inspection = MediaProbe::factory()->for($file)->create(['status' => TaskStatus::COMPLETED,
        'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
        'format' => $result->format, 'metadata' => $result->metadata, 'duration_seconds' => $result->duration]);
    $inspection->streams()->createMany($result->streams);
    $before = hash_file('sha256', $source);

    $run = app(RequestTranscodeRun::class)->forFile($file);
    $item = $run->transcodes()->sole();
    app(RunTranscode::class)->handle($item->id, $item->request_token);
    $item->refresh();
    expect($item->error)->toBeNull()->and($item->status)->toBe(TaskStatus::COMPLETED);
    if ($item->output_path === null) {
        throw new RuntimeException('Missing accepted output.');
    }
    expect($item->output_path)->toEndWith('/output.'.$extension);
    if ($mislabeled) {
        $replacement = $directory.'/replacement.mkv';
        $accepted = Storage::disk('transcodes')->path($item->output_path);
        (new Process(['ffmpeg', '-v', 'error', '-nostdin', '-i', $accepted, '-map', '0:v:0', '-c', 'copy', '-f', 'matroska', $replacement], timeout: 30))->mustRun();
        copy($replacement, $accepted);
        clearstatcache(true, $accepted);
        $item->update(['output_size_bytes' => filesize($accepted)]);
    }
    $check = app(RequestQualityCheck::class)->handle($item);
    if ($mislabeled) {
        $check->update(['output_size_bytes' => $item->output_size_bytes]);
    }
    app(RunQualityCheck::class)->handle($check->id, $check->request_token);
    $check->refresh();
    if ($mislabeled) {
        expect($check->reason)->toBe('output_container')->and($check->verdict)->toBe(QualityVerdict::REJECTED);
    } else {
        expect($check->error)->toBeNull()->and($check->verdict)->toBe(QualityVerdict::PASSED);
    }
    expect(hash_file('sha256', $source))->toBe($before);
})->with([['mp4', false], ['mkv', false], ['mp4', true]])
    ->skip(fn (): bool => getenv('QUALITY_CONTROL_NATIVE_TESTS') !== '1', 'Run with the native FFmpeg tests.');
