<?php

namespace App\Transcoding\Actions;

use App\QualityControl\QualityPolicy;
use App\QualityControl\QualityWorkspace;
use App\QualityControl\SampleWindows;
use App\Transcoding\EncoderQuality;
use App\Transcoding\OptimizationLimit;
use App\Transcoding\OptimizationMeasurements;
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

    /** @param non-empty-list<TranscodePlan> $plans
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $qualityPolicy
     * @param  Closure(): bool  $stop
     * @param  Closure(array<string, mixed>): void  $progress
     */
    public function handle(array $plans, array $policy, array $qualityPolicy, string $directory, float $deadline, Closure $stop, Closure $progress): TranscodePlan|SkippedTranscode
    {
        $plan = $plans[0];
        if (! $policy['enabled']) {
            $progress(['optimization' => ['enabled' => false, 'reason' => 'A required savings or video quality check is disabled.']]);

            return $plan;
        }
        if (count($plans) > 2 || file_exists($directory) || is_link($directory) || ! mkdir($directory, 0700)) {
            throw new RuntimeException('Optimization requires a fresh attempt workspace and at most two encoders.');
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
        $audit = ['enabled' => true, 'policy' => $policy, 'quality_policy' => $qualityPolicy, 'candidates' => [], 'limits' => [], 'winner' => null, 'non_monotonic' => false, 'termination' => [], 'confirmations' => [], 'closest_by_encoder' => [], 'search_decisions' => [], 'closest' => null];
        $winner = null;
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
            $audit['encoder_plans'] = array_map(fn (TranscodePlan $candidate): array => ['encoder' => $candidate->encoder->value,
                'arguments' => $candidate->arguments, 'stream_decisions' => $candidate->streamDecisions], $plans);
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
            $confirmationWindows = $this->windows->forDuration($duration, 'sampled', $policy['confirmation_positions'], $policy['confirmation_seconds']);
            $audit['search_windows'] = $searchWindows;
            $audit['confirmation_windows'] = $confirmationWindows;
            $references = $this->samples->prepare($this->media, $plan, $video, $searchWindows, $directory, 'search');
            $confirmation = $confirmationWindows === $searchWindows ? $references
                : $this->samples->prepare($this->media, $plan, $video, $confirmationWindows, $directory, 'confirmation');
            $firstDeadline = microtime(true) + max(0, $deadline - microtime(true)) / count($plans);
            $conflict = false;
            foreach ($plans as $index => $encoderPlan) {
                $encoderDeadline = $index === 0 ? $firstDeadline : $deadline;
                $searchDeadline = microtime(true) + max(0, $encoderDeadline - microtime(true)) * (1 - $policy['confirmation_fraction']);
                $measurements = new OptimizationMeasurements;
                $searchPolicy = new OptimizationSearchPolicy(
                    new EncoderQuality($encoderPlan->encoder, $policy['starting_quality'][$encoderPlan->encoder->value]),
                    $policy['maximum_candidates'], $policy['quality_floor'], $maximum, $fixedBytes);
                try {
                    $this->search->run($searchPolicy,
                        function (int $value) use ($encoderPlan, $references, $qualityPolicy, $directory, $duration, $policy, $nonVideo, &$audit, &$measurements, $encoderDeadline, $searchDeadline, $progress): array {
                            $this->media->deadline($measurements->confirming ? $encoderDeadline : $searchDeadline);
                            $progress(['phase' => 'sampling']);
                            $candidate = $encoderPlan->withQuality(new EncoderQuality($encoderPlan->encoder, $value));
                            $measurement = $this->samples->measure($this->media, $candidate, $references, $qualityPolicy, $directory, $duration);
                            $measurements->record($value, $measurement);
                            $bytes = $this->estimate($measurement['video_bytes'], $nonVideo, $candidate->sourceSizeBytes, $policy);
                            $record = ['encoder' => $candidate->encoder->value, 'quality' => $value, 'bytes' => $bytes,
                                'passes' => $measurement['minimum_vmaf'] >= $policy['quality_floor'], 'measurements' => $measurement];
                            foreach ($audit['candidates'] as $previous) {
                                if ($previous['encoder'] === $record['encoder']
                                    && (($value - $previous['quality']) * ($measurement['minimum_vmaf'] - $previous['measurements']['minimum_vmaf']) > 0.5)) {
                                    $audit['non_monotonic'] = true;
                                }
                            }
                            $audit['candidates'][] = $record;
                            $progress(['optimization' => $audit]);

                            return ['quality' => $value, 'bytes' => $bytes, 'vmaf' => (float) $measurement['minimum_vmaf']];
                        },
                        function (array $point) use ($encoderPlan, $confirmation, $qualityPolicy, $directory, $duration, $policy, $nonVideo, $maximum, &$audit, &$winner, &$measurements, $encoderDeadline, $progress): array {
                            $measurements->confirming = true;
                            $this->media->deadline($encoderDeadline);
                            $progress(['phase' => 'confirming']);
                            $value = $point['quality'];
                            $candidate = $encoderPlan->withQuality(new EncoderQuality($encoderPlan->encoder, $value));
                            $measured = $this->samples->measure($this->media, $candidate, $confirmation, $qualityPolicy, $directory, $duration);
                            $bytes = max($point['bytes'], $this->estimate($measured['video_bytes'], $nonVideo, $candidate->sourceSizeBytes, $policy));
                            $score = min($point['vmaf'], $measured['minimum_vmaf']);
                            $confirmed = $score >= $policy['quality_floor'] && $bytes <= $maximum;
                            $audit['confirmations'][] = ['encoder' => $candidate->encoder->value, 'quality' => $value, 'bytes' => $bytes, 'passes' => $confirmed, 'measurements' => $measured];
                            $margin = $score - $policy['quality_floor'];
                            $training = $measurements->forQuality($value);
                            $seconds = $measured['encode_seconds'] + $training['encode_seconds'];
                            if ($confirmed && ($winner === null || [$bytes, -$margin, $seconds]
                                < [$audit['winner']['bytes'] ?? PHP_INT_MAX, -($audit['winner']['quality_margin'] ?? 0), $audit['winner']['encode_seconds'] ?? INF])) {
                                $winner = $candidate;
                                $audit['winner'] = ['encoder' => $candidate->encoder->value, 'quality' => $value, 'bytes' => $bytes,
                                    'confirmed' => true, 'quality_margin' => $margin, 'encode_seconds' => $seconds,
                                    'estimated_savings_percent' => 100 * (1 - $bytes / $candidate->sourceSizeBytes)];
                            }
                            $progress(['optimization' => $audit]);

                            return ['quality' => $value, 'bytes' => $bytes, 'vmaf' => (float) $score];
                        }, function (string $reason) use (&$audit, $encoderPlan, $measurements): void {
                            $measurements->termination = $reason;
                            $audit['termination'][$encoderPlan->encoder->value] = $reason;
                            if ($reason === 'candidate_limit') {
                                $audit['limits'][] = $encoderPlan->encoder->value.': candidate limit reached';
                            }
                        }, function (array $event) use (&$audit, $encoderPlan, $progress): void {
                            if ($event['phase'] === 'search') {
                                $audit['search_decisions'][] = ['encoder' => $encoderPlan->encoder->value, ...$event];
                            } elseif ($event['phase'] === 'limit') {
                                $audit['limits'][] = $encoderPlan->encoder->value.': '.$event['message'];
                            } elseif (is_array($event['closest'] ?? null)) {
                                $audit['closest_by_encoder'][$encoderPlan->encoder->value] = ['encoder' => $encoderPlan->encoder->value, ...$event['closest']];
                                $closest = array_values($audit['closest_by_encoder']);
                                usort($closest, fn (array $a, array $b): int => [(int) ! $a['within_size_budget'], $a['within_size_budget'] ? -$a['vmaf'] : $a['bytes'], $a['within_size_budget'] ? $a['bytes'] : -$a['vmaf']]
                                    <=> [(int) ! $b['within_size_budget'], $b['within_size_budget'] ? -$b['vmaf'] : $b['bytes'], $b['within_size_budget'] ? $b['bytes'] : -$b['vmaf']]);
                                $audit['closest'] = $closest[0];
                            }
                            $progress(['optimization' => $audit]);
                        });
                } catch (OptimizationLimit $limit) {
                    $audit['limits'][] = $encoderPlan->encoder->value.': '.$limit->getMessage();
                }
                $conflict = $conflict || $measurements->termination === 'quality_size_conflict';
            }

            if ($winner === null && $audit['limits'] === [] && $conflict) {
                return new SkippedTranscode('quality_size_conflict', 'Adjacent measured settings straddle the size limit without meeting the quality requirement.');
            }

            return $winner ?? ($audit['limits'] !== []
                ? new SkippedTranscode('optimization_inconclusive', 'Preparation limits were reached without a confirmed candidate.')
                : new SkippedTranscode('no_acceptable_candidate', 'No measured candidate met quality and savings requirements within the measured adaptive search.'));
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
