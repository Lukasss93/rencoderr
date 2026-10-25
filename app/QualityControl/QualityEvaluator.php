<?php

namespace App\QualityControl;

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\HdrMode;
use App\Ffmpeg\MediaProcess;
use App\Models\MediaQualityCheck;
use App\QualityControl\Enums\QualityVerdict;
use App\Transcoding\HdrInspection;
use Closure;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\Driver\FFProbeDriver;
use RuntimeException;

class QualityEvaluator
{
    public function __construct(
        private MediaProcess $process,
        private FFMpegDriver $ffmpeg,
        private FFProbeDriver $ffprobe,
        private QualityWorkspace $workspace,
        private QualityPolicy $policies,
        private StreamContract $contract,
        private SampleWindows $windows,
        private VmafResult $scores,
        private SampleScorer $scorer,
    ) {}

    /**
     * @param  Closure(): bool  $stop
     * @param  Closure(array<string, mixed>): void  $progress
     * @return array<string, mixed>
     */
    public function evaluate(MediaQualityCheck $check, Closure $stop, Closure $progress): array
    {
        $files = $this->workspace->open($check);
        try {
            return $this->inspect($check, $files, $stop, $progress);
        } finally {
            if ($this->workspace->identity($files['source']) !== $files['source_identity']
                || $this->workspace->identity($files['output']) !== $files['output_identity']) {
                throw new RuntimeException('The source or staged output changed during quality control.');
            }
        }
    }

    /**
     * @param  array{source: string, output: string, directory: string, source_identity: array<string, int>, output_identity: array<string, int>}  $files
     * @param  Closure(): bool  $stop
     * @param  Closure(array<string, mixed>): void  $progress
     * @return array<string, mixed>
     */
    private function inspect(MediaQualityCheck $check, array $files, Closure $stop, Closure $progress): array
    {
        $policy = $check->policy;
        $settings = $this->policies->settings($policy);
        $ffmpeg = $this->ffmpeg->getProcessBuilderFactory()->getBinary();
        $ffprobe = $this->ffprobe->getProcessBuilderFactory()->getBinary();
        $versions = ['ffmpeg' => $this->read([$ffmpeg, '-version'], $check, $stop, $progress),
            'ffprobe' => $this->read([$ffprobe, '-version'], $check, $stop, $progress)];
        $progress(['binary_versions' => $versions, 'phase' => 'probing']);
        $source = $this->probe($files['source'], $check, $stop, $progress);
        $output = $this->probe($files['output'], $check, $stop, $progress);
        $transcode = $check->transcode()->firstOrFail();
        $decisions = $transcode->stream_decisions;
        $savedVersions = $transcode->optimization['binary_versions'] ?? null;
        if (is_array($savedVersions) && $savedVersions !== $versions) {
            throw new RuntimeException('The media binary versions changed after optimization.');
        }
        if (! is_array($decisions)) {
            throw new RuntimeException('The accepted encode has no saved stream decisions.');
        }
        $contract = $this->contract->verify($source, $output, $decisions, $settings);
        $results = ['streams' => 'passed', 'video_quality' => $settings->verifyVideoQuality ? 'pending' : 'disabled',
            'comparison_view' => $contract['hdr_view'], 'segments' => []];
        $estimated = $transcode->optimization['winner']['bytes'] ?? null;
        if (is_int($estimated) && $estimated > 0) {
            $results['estimated_output_bytes'] = $estimated;
            $results['actual_output_bytes'] = $check->output_size_bytes;
            $results['prediction_deviation_percent'] = 100 * ($check->output_size_bytes / $estimated - 1);
        }
        $savings = 100.0 * (1 - $check->output_size_bytes / $check->source_size_bytes);
        $progress(['expected_contract' => $contract['expected'], 'results' => $results, 'savings_percent' => $savings]);
        if ($settings->requireSmallerOutput && ($check->output_size_bytes >= $check->source_size_bytes || $savings < $settings->minimumSavingsPercent)) {
            throw new QualityRejected('insufficient_savings', 'The output does not meet the saved minimum savings requirement.');
        }
        $results['savings'] = $settings->requireSmallerOutput ? 'passed' : 'disabled';
        if ($settings->verifyVideoQuality) {
            $filters = $this->read([$ffmpeg, '-hide_banner', '-filters'], $check, $stop, $progress);
            if (! preg_match('/^\s*[.A-Z]+\s+libvmaf\s/m', $filters)) {
                throw new RuntimeException('The configured FFmpeg does not provide libvmaf.');
            }
            $this->model($check, $files['directory'], $progress);
        }
        $progress(['phase' => 'checking_timing', 'results' => $results]);
        [$sourceFrames, $sourceHdr] = $this->frames($files['source'], $files['directory'].'/source.frames', $contract['source_video'], $check, $stop, $progress);
        [$outputFrames, $outputHdr] = $this->frames($files['output'], $files['directory'].'/output.frames', $contract['output_video'], $check, $stop, $progress);
        $duration = $sourceFrames->duration();
        $frameRate = StreamContract::fraction($contract['source_video']['avg_frame_rate'] ?? null);
        $tolerance = max((float) $policy['duration_tolerance_seconds'], $frameRate !== null && $frameRate > 0 ? 2 / $frameRate : 0);
        if (abs($duration - $outputFrames->duration()) > $tolerance) {
            throw new QualityRejected('video_duration', 'The decoded video duration does not match the source.');
        }
        $positions = ($policy['version'] ?? 1) >= 2 ? array_values(array_unique([...$policy['positions'], 0.2, 0.8])) : $policy['positions'];
        $windows = $this->windows->forDuration($duration, $policy['mode'], $positions, $policy['sample_seconds']);
        if (($policy['version'] ?? 1) >= 2 && $policy['mode'] === 'full') {
            $windows = [];
            for ($start = 0.0; $start < $duration; $start += 30) {
                $windows[] = ['start' => $start, 'end' => min($duration, $start + 30)];
            }
        }
        $rounding = max(0.000002, (StreamContract::fraction($contract['source_video']['time_base'] ?? null) ?? 0)
            + (StreamContract::fraction($contract['output_video']['time_base'] ?? null) ?? 0));
        $ranges = $sourceFrames->align($outputFrames, $windows, $rounding, $stop);
        foreach ($contract['expected'] as $stream) {
            if ($stream['codec_type'] !== 'audio') {
                continue;
            }
            $before = $this->audio($files['source'], $stream['source_index'], $check, $stop, $progress);
            $after = $this->audio($files['output'], $stream['output_index'], $check, $stop, $progress);
            foreach (['start', 'end'] as $boundary) {
                if (abs(($before[$boundary] - $sourceFrames->origin()) - ($after[$boundary] - $outputFrames->origin())) > 0.25) {
                    throw new QualityRejected('audio_timing', 'A retained audio stream changed its duration or synchronization.');
                }
            }
        }
        $this->hdr($contract, $settings, $sourceHdr, $outputHdr);
        $results = [...$results, 'timing' => 'passed', 'hdr_metadata' => 'passed', 'duration_seconds' => $duration,
            'output_duration_seconds' => $outputFrames->duration(), 'mode' => $policy['mode']];
        $progress(['results' => $results]);
        if (! $settings->verifyVideoQuality) {
            return ['verdict' => QualityVerdict::PASSED, 'results' => $results, 'coverage_seconds' => 0.0];
        }
        $totalFrames = array_sum(array_column($ranges, 'frames'));
        $completeFrames = 0;
        $weightedScore = 0.0;
        foreach ($ranges as $index => $range) {
            $progress(['phase' => 'scoring_'.($index + 1).'_of_'.count($ranges), 'progress_percent' => 100 * $completeFrames / $totalFrames]);
            $log = 'segment-'.$index.'.xml';
            $command = $this->scorer->comparison($ffmpeg, $files, $source, $output, $contract, $settings, $policy, $range, $check->threads, $log);
            $pending = '';
            $measuredFrames = 0;
            $consume = function (string $data) use (&$pending, &$measuredFrames): void {
                $pending .= $data;
                while (($end = strpos($pending, "\n")) !== false) {
                    $line = substr($pending, 0, $end);
                    $pending = substr($pending, $end + 1);
                    if (preg_match('/^frame=(\d+)$/D', trim($line), $match)) {
                        $measuredFrames = (int) $match[1];
                    }
                }
                if (strlen($pending) > 8192) {
                    throw new RuntimeException('The comparison returned an oversized progress field.');
                }
            };
            $result = $this->process->execute($command, $this->remaining($check), $stop, $consume,
                function (string $tail) use ($progress, &$measuredFrames, $completeFrames, $totalFrames, $range): void {
                    $progress(['diagnostic_tail' => $tail, 'progress_percent' => min(99.9, 100 * ($completeFrames + min($range['frames'], $measuredFrames)) / $totalFrames)]);
                }, $files['directory']);
            if ($result->exitCode !== 0 || trim($result->diagnosticTail) !== '') {
                throw new RuntimeException('VMAF comparison failed with exit code '.$result->exitCode.'. '.$result->diagnosticTail);
            }
            $score = $this->scores->read($files['directory'].'/'.$log, $range['frames']);
            $completeFrames += $score['frames'];
            $weightedScore += $score['mean'] * $score['frames'];
            $results['segments'][] = [...$range, ...$score];
            $progress(['results' => $results]);
        }
        $mean = $weightedScore / $completeFrames;
        $coverage = array_sum(array_map(fn (array $window): float => $window['end'] - $window['start'], $windows));
        $passes = $this->policies->passes($policy, $mean, array_column($results['segments'], 'mean'), $settings->minimumVmaf);
        $results['video_quality'] = $passes ? 'passed' : 'rejected';
        $progress(['results' => $results, 'vmaf_mean' => $mean, 'coverage_seconds' => $coverage]);
        if (! $passes) {
            throw new QualityRejected('vmaf_below_threshold', 'The overall or a required segment VMAF score is below the saved minimum.');
        }

        return ['verdict' => QualityVerdict::PASSED, 'results' => $results, 'vmaf_mean' => $mean, 'coverage_seconds' => $coverage];
    }

    /** @param Closure(array<string, mixed>): void $progress */
    private function model(MediaQualityCheck $check, string $directory, Closure $progress): void
    {
        $path = $check->policy['model_path'] ?? null;
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The configured VMAF model is missing or unreadable.');
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false || ($check->model_sha256 !== null && $check->model_sha256 !== $hash)) {
            throw new RuntimeException('The VMAF model changed since this check first loaded it.');
        }
        if (! copy($path, $directory.'/model.json') || hash_file('sha256', $directory.'/model.json') !== $hash) {
            throw new RuntimeException('The VMAF model could not be copied consistently into the workspace.');
        }
        $progress(['model_sha256' => $hash]);
    }

    /** @param Closure(): bool $stop
     * @param  Closure(array<string, mixed>): void  $progress
     * @return array<string, mixed>
     */
    private function probe(string $path, MediaQualityCheck $check, Closure $stop, Closure $progress): array
    {
        $data = json_decode($this->read([$this->ffprobe->getProcessBuilderFactory()->getBinary(), '-v', 'error',
            '-show_streams', '-show_format', '-show_chapters', '-of', 'json', $path], $check, $stop, $progress), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('The media probe returned an invalid document.');
        }

        return $data;
    }

    /** @param array<string, mixed> $video
     * @param  Closure(): bool  $stop
     * @param  Closure(array<string, mixed>): void  $progress
     * @return array{FrameTimeline, HdrInspection}
     */
    private function frames(string $path, string $manifest, array $video, MediaQualityCheck $check, Closure $stop, Closure $progress): array
    {
        $rate = StreamContract::fraction($video['avg_frame_rate'] ?? null);
        $timeline = new FrameTimeline($manifest, $rate !== null && $rate > 0 ? 1 / $rate : 0);
        $hdr = new HdrInspection;
        $result = $this->process->execute([$this->ffprobe->getProcessBuilderFactory()->getBinary(), '-v', 'error', '-threads', (string) $check->threads,
            '-select_streams', (string) $video['index'], '-show_frames', '-show_streams', '-show_entries', 'frame=best_effort_timestamp_time,duration_time,pkt_duration_time:frame_side_data:stream_side_data', '-of', 'flat', $path],
            $this->remaining($check), $stop, function (string $chunk) use ($timeline, $hdr): void {
                $timeline->push($chunk);
                $hdr->push($chunk);
            }, fn (string $tail) => $progress(['diagnostic_tail' => $tail]));
        if ($result->exitCode !== 0 || trim($result->diagnosticTail) !== '') {
            throw new RuntimeException('Frame inspection failed. '.$result->diagnosticTail);
        }
        $timeline->finish();
        $hdr->finish();

        return [$timeline, $hdr];
    }

    /** @param Closure(): bool $stop
     * @param  Closure(array<string, mixed>): void  $progress
     * @return array{start: float, end: float}
     */
    private function audio(string $path, int $index, MediaQualityCheck $check, Closure $stop, Closure $progress): array
    {
        $span = new PacketSpan;
        $result = $this->process->execute([$this->ffprobe->getProcessBuilderFactory()->getBinary(), '-v', 'error',
            '-select_streams', (string) $index, '-show_packets', '-show_entries', 'packet=pts_time,duration_time', '-of', 'flat', $path],
            $this->remaining($check), $stop, fn (string $chunk) => $span->push($chunk),
            fn (string $tail) => $progress(['diagnostic_tail' => $tail]));
        if ($result->exitCode !== 0 || trim($result->diagnosticTail) !== '') {
            throw new RuntimeException('Audio packet inspection failed. '.$result->diagnosticTail);
        }

        return $span->finish();
    }

    /** @param array<string, mixed> $contract */
    private function hdr(array $contract, MediaSettings $settings, HdrInspection $source, HdrInspection $output): void
    {
        $hdr = $contract['hdr_view'] === 'tone_mapped_sdr';
        if ($hdr && $settings->hdrMode === HdrMode::PRESERVE) {
            if ($source->unsupported()) {
                throw new RuntimeException('The source has dynamic or changing HDR metadata that cannot be verified.');
            }
            if ($output->unsupported() || $source->normalizedSideData() !== $output->normalizedSideData()) {
                throw new QualityRejected('hdr_metadata', 'The output does not preserve the source HDR mastering metadata.');
            }
        } elseif ($output->unsupported() || $output->sideData() !== []) {
            throw new QualityRejected('hdr_metadata', 'The SDR output retains unexpected HDR metadata.');
        }
    }

    /** @param list<string> $command
     * @param  Closure(): bool  $stop
     * @param  Closure(array<string, mixed>): void  $progress
     */
    private function read(array $command, MediaQualityCheck $check, Closure $stop, Closure $progress): string
    {
        $text = '';
        $result = $this->process->execute($command, $this->remaining($check), $stop, function (string $chunk) use (&$text): void {
            if (strlen($text) + strlen($chunk) > 8 * 1024 * 1024) {
                throw new RuntimeException('Media inspection exceeded its metadata limit.');
            }
            $text .= $chunk;
        }, fn (string $tail) => $progress(['diagnostic_tail' => $tail]));
        if ($result->exitCode !== 0 || trim($result->diagnosticTail) !== '') {
            throw new RuntimeException('Media inspection failed with exit code '.$result->exitCode.'. '.$result->diagnosticTail);
        }

        return $text;
    }

    private function remaining(MediaQualityCheck $check): int
    {
        $seconds = (int) floor(($check->execution_deadline_at?->getTimestamp() ?? 0) - microtime(true));
        if ($seconds < 1) {
            throw new RuntimeException('The quality-control execution deadline expired.');
        }

        return $seconds;
    }
}
