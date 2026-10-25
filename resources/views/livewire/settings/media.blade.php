@use('App\Encoding\Enums\TargetVideoCodec')
@use('App\Encoding\Enums\VideoQuality')
@use('App\Encoding\Enums\EncodingSpeed')
@use('App\Encoding\Enums\HdrMode')
@use('App\Encoding\Enums\AudioCodec')
@use('App\Encoding\Enums\AudioChannels')
@use('App\Encoding\Enums\MediaPreset')

<div class="space-y-6">
    <x-page-header title="Media settings" :description="$library ? 'Media preferences for '.$library->name.'. Inherit global settings or override individual fields.' : 'Default media preferences for every library.'">
        @if ($library)
            <x-button text="Back to libraries" flat :href="route('settings.libraries')" wire:navigate />
        @endif
    </x-page-header>
    <p class="text-sm text-gray-500 dark:text-dark-400">These preferences are saved for future transcoding. No files are processed yet. Resolution and frame rate remain unchanged.</p>

    <form wire:submit="save" class="space-y-6">
        @if ($errors->isNotEmpty())
            <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                <p class="font-semibold">Check the media settings below.</p>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <x-card header="Video preset">
            <div class="space-y-4">
                <p class="text-sm text-gray-500 dark:text-dark-400">Apply a starting point for video codec, quality, and speed. Other settings stay unchanged. You can edit the values before saving.</p>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="min-w-0 flex-1">
                        <x-select.native label="Preset" wire:model="form.selectedPreset" :options="[
                            ['label' => 'Choose a video preset', 'value' => ''],
                            ...array_map(fn (MediaPreset $preset) => ['label' => $preset->label(), 'value' => $preset->value], MediaPreset::cases()),
                        ]" />
                    </div>
                    <x-button text="Apply preset" flat wire:click="applyPreset" loading="applyPreset" />
                </div>
                @if ($library)
                    <x-button text="Use global settings for all" flat wire:click="inheritAll" loading="inheritAll" />
                @endif
            </div>
        </x-card>
        <x-card header="Video and audio">
            <div class="grid gap-6 sm:grid-cols-2">
                <x-media.setting field="video_codec" :library-mode="$library !== null"
                    :inherited="$form->isInherited('video_codec')" :global-value="$globalSettings?->videoCodec->label() ?? ''">
                    <x-select.native label="Target video codec" wire:model="form.values.video_codec"
                        :disabled="$form->isInherited('video_codec')"
                        :options="array_map(fn (TargetVideoCodec $choice) => ['label' => $choice->label(), 'value' => $choice->value], TargetVideoCodec::cases())" />
                </x-media.setting>
                <x-media.setting field="video_quality" :library-mode="$library !== null"
                    :inherited="$form->isInherited('video_quality')" :global-value="$globalSettings?->videoQuality->label() ?? ''">
                    <x-select.native label="Fixed quality (direct encoding)" wire:model="form.values.video_quality"
                        :disabled="$form->isInherited('video_quality')"
                        :options="array_map(fn (VideoQuality $choice) => ['label' => $choice->label(), 'value' => $choice->value], VideoQuality::cases())" hint="Used when savings or quality verification is disabled. Automatic optimization chooses quality using measured perceptual similarity and predicted output size." />
                </x-media.setting>
                <x-media.setting field="encoding_speed" :library-mode="$library !== null"
                    :inherited="$form->isInherited('encoding_speed')" :global-value="$globalSettings?->encodingSpeed->label() ?? ''">
                    <x-select.native label="Encoding speed" wire:model="form.values.encoding_speed"
                        :disabled="$form->isInherited('encoding_speed')"
                        :options="array_map(fn (EncodingSpeed $choice) => ['label' => $choice->label(), 'value' => $choice->value], EncodingSpeed::cases())" hint="Slower encoding prioritizes compression efficiency." />
                </x-media.setting>
                <x-media.setting field="hdr_mode" :library-mode="$library !== null"
                    :inherited="$form->isInherited('hdr_mode')" :global-value="$globalSettings?->hdrMode->label() ?? ''">
                    <x-select.native label="HDR handling" wire:model="form.values.hdr_mode"
                        :disabled="$form->isInherited('hdr_mode')"
                        :options="array_map(fn (HdrMode $choice) => ['label' => $choice->label(), 'value' => $choice->value], HdrMode::cases())" hint="High dynamic range (HDR) is preserved by default. SDR means standard dynamic range. Unsupported preservation must skip the file rather than strip HDR." />
                </x-media.setting>
                <x-media.setting field="audio_codec" :library-mode="$library !== null"
                    :inherited="$form->isInherited('audio_codec')" :global-value="$globalSettings?->audioCodec->label() ?? ''">
                    <x-select.native label="Audio codec" wire:model="form.values.audio_codec"
                        :disabled="$form->isInherited('audio_codec')"
                        :options="array_map(fn (AudioCodec $choice) => ['label' => $choice->label(), 'value' => $choice->value], AudioCodec::cases())" />
                </x-media.setting>
                <x-media.setting field="audio_channels" :library-mode="$library !== null"
                    :inherited="$form->isInherited('audio_channels')" :global-value="$globalSettings?->audioChannels->label() ?? ''">
                    <x-select.native label="Audio channels" wire:model="form.values.audio_channels"
                        :disabled="$form->isInherited('audio_channels')"
                        :options="array_map(fn (AudioChannels $choice) => ['label' => $choice->label(), 'value' => $choice->value], AudioChannels::cases())" hint="Channel conversion applies only to audio that is re-encoded." />
                </x-media.setting>
            </div>
        </x-card>

        <x-accordion multiple>
            <x-accordion.items title="Advanced audio" id="media-advanced-audio">
                <div class="space-y-6">
                <x-media.setting field="audio_bitrate_per_channel" :library-mode="$library !== null"
                    :inherited="$form->isInherited('audio_bitrate_per_channel')" :global-value="$globalSettings?->audioBitratePerChannel ?? ''">
                    <x-input label="Audio bitrate per channel (kbps)" type="number" min="32" max="256" step="1"
                        wire:model="form.values.audio_bitrate_per_channel" :disabled="$form->isInherited('audio_bitrate_per_channel')" hint="Kilobits per second per output channel. Applies only to converted audio; copied tracks keep their original bitrate." />
                </x-media.setting>
                <x-media.setting field="preserve_lossless_audio" :library-mode="$library !== null"
                    :inherited="$form->isInherited('preserve_lossless_audio')" :global-value="$globalSettings?->preserveLosslessAudio ? 'Enabled' : 'Disabled'">
                    <x-checkbox label="Preserve lossless audio" wire:model="form.values.preserve_lossless_audio" :disabled="$form->isInherited('preserve_lossless_audio')" />
                    <p class="text-sm text-gray-500 dark:text-dark-400">Copy lossless tracks without changing their codec or channels, even when audio conversion is selected.</p>
                </x-media.setting>
                <x-media.setting field="audio_languages" :library-mode="$library !== null"
                    :inherited="$form->isInherited('audio_languages')" :global-value="implode(', ', $globalSettings?->audioLanguages ?? []) ?: 'All languages'">
                    <x-tag label="Audio languages" wire:model="form.values.audio_languages" :limit="100"
                        :disabled="$form->isInherited('audio_languages')" hint="Leave empty to keep all. Enter three-letter tags such as eng, ita, or und (unknown), then press Enter. A filter that removes every audio track must skip the file." />
                </x-media.setting>
                </div>
            </x-accordion.items>
            <x-accordion.items title="Subtitles" id="media-subtitles">
                <div class="space-y-6">
                <x-media.setting field="retain_subtitles" :library-mode="$library !== null"
                    :inherited="$form->isInherited('retain_subtitles')" :global-value="$globalSettings?->retainSubtitles ? 'Enabled' : 'Disabled'">
                    <x-checkbox label="Retain subtitles" wire:model="form.values.retain_subtitles" :disabled="$form->isInherited('retain_subtitles')" />
                </x-media.setting>
                <x-media.setting field="subtitle_languages" :library-mode="$library !== null"
                    :inherited="$form->isInherited('subtitle_languages')" :global-value="implode(', ', $globalSettings?->subtitleLanguages ?? []) ?: 'All languages'">
                    <x-tag label="Subtitle languages" wire:model="form.values.subtitle_languages" :limit="100"
                        :disabled="$form->isInherited('subtitle_languages')" hint="Leave empty to keep all. Enter three-letter tags such as eng, ita, or und, then press Enter. Applies only when subtitles are retained." />
                </x-media.setting>
                <x-media.setting field="preserve_forced_subtitles" :library-mode="$library !== null"
                    :inherited="$form->isInherited('preserve_forced_subtitles')" :global-value="$globalSettings?->preserveForcedSubtitles ? 'Enabled' : 'Disabled'">
                    <x-checkbox label="Preserve forced subtitles" wire:model="form.values.preserve_forced_subtitles" :disabled="$form->isInherited('preserve_forced_subtitles')" />
                    <p class="text-sm text-gray-500 dark:text-dark-400">Keep forced subtitles even outside the selected languages. Applies only when subtitles are retained.</p>
                </x-media.setting>
                </div>
            </x-accordion.items>
            <x-accordion.items title="Processing safeguards" id="media-safeguards">
                <div class="space-y-6">
                <x-media.setting field="skip_video_codecs" :library-mode="$library !== null"
                    :inherited="$form->isInherited('skip_video_codecs')" :global-value="implode(', ', $globalSettings?->skipVideoCodecs ?? []) ?: 'None'">
                    <fieldset class="space-y-2">
                        <legend class="text-sm font-medium">Skip files with these video codecs</legend>
                        <div class="flex flex-wrap gap-4">
                            @foreach (['h264' => 'H.264', 'hevc' => 'H.265', 'av1' => 'AV1'] as $codec => $label)
                                <div wire:key="skip-video-{{ $codec }}">
                                    <x-checkbox :label="$label" :value="$codec" wire:model="form.values.skip_video_codecs"
                                        :disabled="$form->isInherited('skip_video_codecs')" />
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                    <p class="text-sm text-gray-500 dark:text-dark-400">Matching files are skipped entirely. Presets do not change this list: remove H.265 here if you want to convert it to AV1.</p>
                </x-media.setting>
                <x-media.setting field="require_smaller_output" :library-mode="$library !== null"
                    :inherited="$form->isInherited('require_smaller_output')" :global-value="$globalSettings?->requireSmallerOutput ? 'Enabled' : 'Disabled'">
                    <x-checkbox label="Require smaller output" wire:model="form.values.require_smaller_output" :disabled="$form->isInherited('require_smaller_output')" />
                </x-media.setting>
                <x-media.setting field="minimum_savings_percent" :library-mode="$library !== null"
                    :inherited="$form->isInherited('minimum_savings_percent')" :global-value="$globalSettings?->minimumSavingsPercent ?? ''">
                    <x-input label="Minimum savings (%)" type="number" min="0" max="99" step="1"
                        wire:model="form.values.minimum_savings_percent" :disabled="$form->isInherited('minimum_savings_percent')" hint="Applies when smaller output is required. Zero still requires an output strictly smaller than the original." />
                </x-media.setting>
                <x-media.setting field="verify_video_quality" :library-mode="$library !== null"
                    :inherited="$form->isInherited('verify_video_quality')" :global-value="$globalSettings?->verifyVideoQuality ? 'Enabled' : 'Disabled'">
                    <x-checkbox label="Verify video quality" wire:model="form.values.verify_video_quality" :disabled="$form->isInherited('verify_video_quality')" />
                </x-media.setting>
                <x-media.setting field="minimum_vmaf" :library-mode="$library !== null"
                    :inherited="$form->isInherited('minimum_vmaf')" :global-value="$globalSettings?->minimumVmaf ?? ''">
                    <x-input label="Minimum VMAF score" type="number" min="0" max="100" step="0.1"
                        wire:model="form.values.minimum_vmaf" :disabled="$form->isInherited('minimum_vmaf')" hint="Video Multi-Method Assessment Fusion (VMAF) measures perceptual similarity. It is not a lossless guarantee. Applies when quality verification is enabled." />
                </x-media.setting>
                </div>
            </x-accordion.items>
        </x-accordion>
        <div class="flex justify-end">
            <x-button text="Save media settings" submit loading="save" />
        </div>
    </form>
</div>
