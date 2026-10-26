<?php

namespace App\Transcoding\Actions;

use App\QualityControl\QualityPolicy;
use App\QualityControl\QualityWorkspace;
use App\QualityControl\SampleWindows;
use App\Transcoding\EncoderQuality;
use App\Transcoding\OptimizationLimit;
use App\Transcoding\OptimizationMedia;
use App\Transcoding\OptimizationSamples;
use App\Transcoding\OptimizationSearch;
use App\Transcoding\OptimizationSearchPolicy;
use App\Transcoding\SkippedTranscode;
use App\Transcoding\TranscodePlan;
use Closure;
use RuntimeException;

class OptimizeTranscode
{
    public function __construct(private OptimizationMedia $media, private OptimizationSamples $samples,
        private OptimizationSearch $search, private SampleWindows $windows, private QualityWorkspace $identities, private QualityPolicy $policies) {}

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $qualityPolicy
     * @param  Closure(): bool  $stop
     * @param  Closure(array<string, mixed>): void  $progress
     */
    public function handle(TranscodePlan $plan, array $policy, array $qualityPolicy, string $directory, float $deadline, Closure $stop, Closure $progress): TranscodePlan|SkippedTranscode
    {
        if (! $policy['enabled']) {
            $progress(['optimization' => ['enabled' => false, 'reason' => 'A required savings or video quality check is disabled.']]);

            return $plan;
        }
        if (file_exists($directory) || is_link($directory) || ! mkdir($directory, 0700)) {
            throw new RuntimeException('Optimization requires a fresh attempt workspace.');
        }
        $this->policies->settings($qualityPolicy);
        $deadline = min($deadline, microtime(true) + $policy['seconds']);
        $identity = $this->identities->identity($plan->sourcePath);
        $guard = function () use ($stop, $plan, $identity): bool {
            if ($this->identities->identity($plan->sourcePath) !== $identity) {
                throw new RuntimeException('The source changed during optimization.');
            }

            return $stop();
        };
        $this->media->configure($directory, $deadline, $policy['temporary_bytes'], $guard, $progress);
        $audit = ['enabled' => true, 'policy' => $policy, 'quality_policy' => $qualityPolicy, 'candidates' => [], 'limits' => [], 'winner' => null, 'non_monotonic' => false, 'termination' => null, 'search_decisions' => [], 'closest' => null];
        try {
            $progress(['phase' => 'measuring_source']);
            $model = $qualityPolicy['model_path'];
            if (! is_string($model) || ! is_readable($model) || ! copy($model, $directory.'/model.json')) {
                throw new RuntimeException('The optimization VMAF model is unavailable.');
            }
            $hash = hash_file('sha256', $directory.'/model.json');
            if ($hash === false || hash_file('sha256', $model) !== $hash || (isset($qualityPolicy['model_sha256']) && $qualityPolicy['model_sha256'] !== $hash)) {
                throw new RuntimeException('The VMAF model changed during preparation.');
            }
            $audit['quality_policy']['model_sha256'] = $hash;
            $audit['binary_versions'] = ['ffmpeg' => $this->media->read([$this->media->binary(), '-version']),
                'ffprobe' => $this->media->read([$this->media->binary(true), '-version'])];
            $audit['encoder_plan'] = ['encoder' => $plan->encoder->value,
                'arguments' => $plan->arguments, 'stream_decisions' => $plan->streamDecisions];
            $progress(['optimization' => $audit]);
            $probe = $this->media->probe($plan->sourcePath);
            $videoIndex = $plan->streamDecisions[0]['index'];
            $streams = array_column($probe['streams'], null, 'index');
            $video = $streams[$videoIndex] ?? null;
            $duration = (float) ($video['duration'] ?? $probe['format']['duration'] ?? 0);
            if (! is_array($video) || ! is_finite($duration) || $duration <= 0) {
                throw new RuntimeException('The source video duration is unknown.');
            }
            $inventory = $this->media->inventory($plan->sourcePath);
            $nonVideo = $this->retainedBytes($plan, $streams, $inventory);
            $maximum = min($plan->sourceSizeBytes - 1, (int) floor($plan->sourceSizeBytes * (1 - $plan->settings->minimumSavingsPercent / 100)));
            $audit['source_video_bytes'] = $inventory[$videoIndex] ?? null;
            $audit['retained_non_video_bytes'] = $nonVideo;
            $audit['maximum_bytes'] = $maximum;
            $fixedBytes = (int) ceil($nonVideo + $plan->sourceSizeBytes * $policy['mux_reserve']);
            if ($fixedBytes >= $maximum) {
                return new SkippedTranscode('retained_streams_exceed_budget', 'Retained audio, subtitles, attachments and the container reserve exceed the maximum output size.');
            }
            $progress(['phase' => 'sampling', 'optimization' => $audit]);
            $searchWindows = $this->windows->forDuration($duration, 'sampled', $policy['positions'], $policy['sample_seconds']);
            $audit['search_windows'] = $searchWindows;
            $references = $this->samples->prepare($this->media, $plan, $video, $searchWindows, $directory);
            $searchPolicy = new OptimizationSearchPolicy(
                new EncoderQuality($plan->encoder, $policy['starting_quality'][$plan->encoder->value]),
                $policy['maximum_candidates'], $policy['quality_floor'], $maximum, $fixedBytes);
            $result = $this->search->run($searchPolicy,
                function (int $value) use ($plan, $references, $qualityPolicy, $directory, $duration, $policy, $nonVideo, &$audit): array {
                    $candidate = $plan->withQuality(new EncoderQuality($plan->encoder, $value));
                    $measurement = $this->samples->measure($this->media, $candidate, $references, $qualityPolicy, $directory, $duration);
                    $bytes = $this->estimate($measurement['video_bytes'], $nonVideo, $candidate->sourceSizeBytes, $policy);
                    foreach ($audit['candidates'] as $previous) {
                        if (($value - $previous['quality']) * ($measurement['minimum_vmaf'] - $previous['measurements']['minimum_vmaf']) > 0.5) {
                            $audit['non_monotonic'] = true;
                        }
                    }
                    $audit['candidates'][] = ['encoder' => $candidate->encoder->value, 'quality' => $value, 'bytes' => $bytes,
                        'passes' => $measurement['minimum_vmaf'] >= $policy['quality_floor'], 'measurements' => $measurement];

                    return ['quality' => $value, 'bytes' => $bytes, 'vmaf' => (float) $measurement['minimum_vmaf'], 'encode_seconds' => $measurement['encode_seconds']];
                }, function (array $event) use (&$audit, $plan, $policy, $progress): void {
                    if ($event['phase'] === 'search') {
                        $audit['search_decisions'][] = $event;
                    } elseif ($event['phase'] === 'limit') {
                        $audit['limits'][] = $event['message'];
                    } else {
                        $audit['closest'] = ['encoder' => $plan->encoder->value, ...$event['closest']];
                        if ($event['winner'] !== null) {
                            $audit['winner'] = ['encoder' => $plan->encoder->value, ...$event['winner'],
                                'quality_margin' => $event['winner']['vmaf'] - $policy['quality_floor'],
                                'estimated_savings_percent' => 100 * (1 - $event['winner']['bytes'] / $plan->sourceSizeBytes)];
                        }
                    }
                    $progress(['optimization' => $audit]);
                });
            $audit['termination'] = $result['termination'];
            if ($result['termination'] === 'candidate_limit') {
                $audit['limits'][] = 'Candidate limit reached';
            }
            if ($result['winner'] !== null) {
                return $plan->withQuality(new EncoderQuality($plan->encoder, $result['winner']['quality']));
            }
            if ($audit['limits'] === [] && $audit['termination'] === 'quality_size_conflict') {
                return new SkippedTranscode('quality_size_conflict', 'Adjacent measured settings straddle the size limit without meeting the quality requirement.');
            }

            return $audit['limits'] !== []
                ? new SkippedTranscode('optimization_inconclusive', 'Preparation limits were reached without a suitable sampled candidate.')
                : new SkippedTranscode('no_acceptable_candidate', 'No measured candidate met quality and savings requirements within the measured adaptive search.');
        } catch (OptimizationLimit $limit) {
            $audit['limits'][] = $limit->getMessage();

            return new SkippedTranscode('optimization_inconclusive', $limit->getMessage());
        } finally {
            $progress(['optimization' => $audit]);
            if ($this->identities->identity($plan->sourcePath) !== $identity) {
                throw new RuntimeException('The source changed during optimization.');
            }
        }
    }

    /** @param array<string, mixed> $policy */
    private function estimate(int $video, int $nonVideo, int $source, array $policy): int
    {
        return (int) ceil($video * (1 + $policy['video_reserve']) + $nonVideo + $source * $policy['mux_reserve']);
    }

    /** @param array<int, array<string, mixed>> $streams
     * @param  array<int, int>  $inventory
     */
    private function retainedBytes(TranscodePlan $plan, array $streams, array $inventory): int
    {
        $total = 0;
        $output = 0;
        foreach ($plan->streamDecisions as $position => $decision) {
            if ($decision['action'] === 'drop') {
                continue;
            }
            if ($position > 0) {
                if ($decision['action'] === 'encode') {
                    $key = array_search('-b:'.$output, $plan->arguments, true);
                    $rate = $key === false ? '' : ($plan->arguments[$key + 1] ?? '');
                    if (! preg_match('/^(\\d+)k$/D', $rate, $match)) {
                        throw new RuntimeException('The retained encoded audio bitrate is unknown.');
                    }
                    $duration = $this->media->audioDuration($plan->sourcePath, $decision['index']);
                    $total += (int) ceil((int) $match[1] * 1000 / 8 * $duration * 1.1);
                } elseif ($decision['type'] === 'attachment') {
                    $size = $streams[$decision['index']]['extradata_size'] ?? null;
                    if (! is_int($size) || $size < 0) {
                        throw new RuntimeException('A retained attachment size is unknown.');
                    }
                    $total += $size;
                } else {
                    $total += $inventory[$decision['index']] ?? 0;
                }
            }
            $output++;
        }

        return $total;
    }
}
