<?php

namespace App\QualityControl;

use App\Encoding\Data\MediaSettings;
use App\Encoding\Enums\HdrMode;
use App\Ffmpeg\HardwareDecoding;
use App\Ffmpeg\ToneMap;

class SampleScorer
{
    /** @param array<string, mixed> $files
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $policy
     * @param  array<string, int|float>  $range
     * @return list<string>
     */
    public function comparison(string $binary, array $files, array $source, array $output, array $contract, MediaSettings $settings, array $policy, array $range, int $threads, string $log): array
    {
        $command = [$binary, '-nostdin', '-xerror', '-v', 'error', '-nostats', '-progress', 'pipe:1', '-stats_period', '1', '-copyts', '-filter_complex_threads', (string) $threads];
        $decoding = HardwareDecoding::fromPolicy($policy);
        if ($decoding->inputArguments($contract['source_video']) !== [] || $decoding->inputArguments($contract['output_video']) !== []) {
            $command = [...$command, ...$decoding->deviceArguments()];
        }
        foreach (['source' => $source, 'output' => $output] as $name => $probe) {
            $seek = max(0.0, $range[$name.'_start'] - (float) ($probe['format']['start_time'] ?? 0) - 2);
            $command = [...$command, '-threads', (string) $threads];
            if ($seek > 0) {
                $command = [...$command, '-ss', sprintf('%.9F', $seek)];
            }
            $command = [...$command, ...$decoding->inputArguments($contract[$name.'_video']), '-noautorotate', '-i', $files[$name]];
        }
        $chains = [];
        foreach (['source' => 'reference', 'output' => 'distorted'] as $name => $label) {
            $video = $contract[$name.'_video'];
            $input = $name === 'source' ? '0' : '1';
            $filters = ['trim=start='.sprintf('%.9F', $range[$name.'_start']).':end='.sprintf('%.9F', $range[$name.'_end'])];
            if ($contract['hdr_view'] === 'tone_mapped_sdr' && ($name === 'source' || $settings->hdrMode === HdrMode::PRESERVE)) {
                $filters[] = ToneMap::filter($video['pix_fmt']);
            }
            $filters[] = 'scale='.$policy['width'].':'.$policy['height'].':force_original_aspect_ratio=decrease:force_divisible_by=2:reset_sar=1:flags=bicubic';
            $filters[] = 'pad='.$policy['width'].':'.$policy['height'].':(ow-iw)/2:(oh-ih)/2:color=black';
            $filters[] = 'format=yuv420p10le,settb=AVTB,setpts=N';
            $chains[] = '['.$input.':'.$video['index'].']'.implode(',', $filters).'['.$label.']';
        }
        $chains[] = '[distorted][reference]libvmaf=model=path=model.json:n_threads='.$threads.':n_subsample=1:log_fmt=xml:log_path='.$log.':shortest=1:repeatlast=0[score]';

        return [...$command, '-filter_complex', implode(';', $chains), '-map', '[score]', '-an', '-fps_mode', 'passthrough', '-f', 'null', '-'];
    }
}
