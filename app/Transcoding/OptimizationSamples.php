<?php

namespace App\Transcoding;

use App\QualityControl\FrameTimeline;
use App\QualityControl\SampleScorer;
use App\QualityControl\StreamContract;
use App\QualityControl\VmafResult;
use RuntimeException;

class OptimizationSamples
{
    public function __construct(private SampleScorer $scorer, private VmafResult $scores) {}

    /** @param list<array{start: float, end: float}> $windows
     * @param  array<string, mixed>  $video
     * @return list<array{path: string, probe: array<string, mixed>, timeline: FrameTimeline, start: float, end: float}>
     */
    public function prepare(OptimizationMedia $media, TranscodePlan $plan, array $video, array $windows, string $directory, string $group): array
    {
        $samples = [];
        foreach ($windows as $index => $window) {
            $seek = max(0.0, $window['start'] - 2);
            $length = $window['end'] - $seek;
            $path = $directory.'/'.$group.'-'.$index.'.mkv';
            $media->execute([$media->binary(), '-nostdin', '-v', 'error', '-xerror', '-noautorotate', '-ss', sprintf('%.9F', $seek),
                '-i', $plan->sourcePath, '-t', sprintf('%.9F', $length), '-map', '0:'.$video['index'], '-c:v', 'ffv1', '-level', '3',
                '-pix_fmt', '+'.$video['pix_fmt'], '-fps_mode', 'passthrough', '-enc_time_base', 'demux', '-f', 'matroska', $path]);
            $probe = $media->probe($path);
            $timeline = $this->timeline($media, $path, $probe['streams'][0], $path.'.frames');
            $samples[] = ['path' => $path, 'probe' => $probe, 'timeline' => $timeline,
                'start' => $window['start'] - $seek, 'end' => min($length, $timeline->duration())];
        }

        return $samples;
    }

    /** @param list<array{path: string, probe: array<string, mixed>, timeline: FrameTimeline, start: float, end: float}> $samples
     * @param  array<string, mixed>  $policy
     * @return array{video_bytes: int, minimum_vmaf: float, windows: list<array<string, mixed>>, dispersion: float, encode_seconds: float}
     */
    public function measure(OptimizationMedia $media, TranscodePlan $plan, array $samples, array $policy, string $directory, float $duration): array
    {
        $windows = [];
        $rates = [];
        $minimum = INF;
        $totalBytes = 0;
        $totalSeconds = 0.0;
        $encodeSeconds = 0.0;
        foreach ($samples as $index => $sample) {
            $media->guard();
            $output = $directory.'/candidate.mkv';
            $manifest = $output.'.frames';
            $log = 'candidate.xml';
            try {
                $started = microtime(true);
                $media->execute([$media->binary(), ...$plan->sampleArguments($sample['path']), '-v', 'error', '-xerror', $output], strict: false);
                $encodeSeconds += microtime(true) - $started;
                $probe = $media->probe($output);
                $sourceVideo = $sample['probe']['streams'][0];
                $outputVideo = $probe['streams'][0];
                $timeline = $this->timeline($media, $output, $outputVideo, $manifest);
                $rounding = (StreamContract::fraction($sourceVideo['time_base'] ?? null) ?? 0)
                    + (StreamContract::fraction($outputVideo['time_base'] ?? null) ?? 0);
                $ranges = $sample['timeline']->align($timeline, [['start' => $sample['start'], 'end' => $sample['end']]], max(0.000002, $rounding), $media->guard(...));
                $range = $ranges[0];
                $contract = ['source_video' => $sourceVideo, 'output_video' => $outputVideo,
                    'hdr_view' => in_array($sourceVideo['color_transfer'] ?? null, ['smpte2084', 'arib-std-b67'], true) ? 'tone_mapped_sdr' : 'sdr'];
                $command = $this->scorer->comparison($media->binary(), ['source' => $sample['path'], 'output' => $output],
                    $sample['probe'], $probe, $contract, $plan->settings, $policy, $range, $policy['threads'], $log);
                $media->execute($command);
                $score = $this->scores->read($directory.'/'.$log, $range['frames']);
                $minimum = min($minimum, $score['mean']);
                $bytes = $media->inventory($output)[0] ?? null;
                if ($bytes === null) {
                    throw new RuntimeException('The candidate video packet size is unknown.');
                }
                $seconds = $timeline->duration();
                $totalSeconds += $seconds;
                $totalBytes += $bytes;
                $rates[] = $bytes / $seconds;
                $windows[] = ['sample' => $index, 'vmaf' => $score['mean'], 'frames' => $score['frames'], 'video_bytes' => $bytes, 'seconds' => $seconds];
            } finally {
                unset($timeline);
                foreach ([$output, $manifest, $directory.'/'.$log] as $temporary) {
                    if (is_file($temporary) && ! unlink($temporary)) {
                        throw new RuntimeException('Could not clean an optimization candidate.');
                    }
                }
            }
        }
        if ($totalSeconds <= 0 || ! is_finite($minimum) || $rates === []) {
            throw new RuntimeException('Optimization has no complete sample measurements.');
        }

        return ['video_bytes' => (int) ceil($totalBytes / $totalSeconds * $duration), 'minimum_vmaf' => $minimum,
            'windows' => $windows, 'dispersion' => max($rates) / max(1.0, min($rates)), 'encode_seconds' => $encodeSeconds];
    }

    /** @param array<string, mixed> $video */
    private function timeline(OptimizationMedia $media, string $path, array $video, string $manifest): FrameTimeline
    {
        $rate = StreamContract::fraction($video['avg_frame_rate'] ?? null);
        $timeline = new FrameTimeline($manifest, $rate !== null && $rate > 0 ? 1 / $rate : 0);
        $media->execute([$media->binary(true), '-v', 'error', '-select_streams', 'v:0', '-show_frames', '-show_entries',
            'frame=best_effort_timestamp_time,duration_time,pkt_duration_time', '-of', 'flat', $path], $timeline->push(...));
        $timeline->finish();

        return $timeline;
    }
}
