@use('App\Library\Enums\MediaFileStatus')
@use('Illuminate\Support\Str')

<div class="space-y-6" wire:poll.5s>
    <x-page-header :title="basename($file->relative_path)" :description="$file->libraryPath->library->name">
        <div class="flex flex-wrap gap-2">
            <x-button text="Back to dashboard" flat :href="route('home', ['library' => $file->libraryPath->library_id])" wire:navigate />
            <x-button text="View queue" flat :href="route('queue.index')" wire:navigate />
            <x-button text="Transcode" icon="film" wire:click="transcode" loading="transcode"
                :disabled="$activeRun !== null || $file->transcodeUnavailableReason() !== null" />
        </div>
    </x-page-header>

    @error('transcode')
        <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
    @if ($reason = $file->transcodeUnavailableReason())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $reason }}</p>
    @elseif ($activeRun)
        <p class="text-sm text-gray-500 dark:text-gray-400">This library already has an active transcode run. Follow its progress on the Queue page.</p>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">Transcoding uses this library's saved media settings.</p>
    @endif

    <x-card>
        <div class="space-y-5">
            <div class="flex flex-wrap gap-2">
                <x-badge :text="ucfirst($file->status->value)" :color="match ($file->status) {
                    MediaFileStatus::PRESENT => 'green',
                    MediaFileStatus::MISSING => 'red',
                    MediaFileStatus::EXCLUDED => 'gray',
                }" />
                <x-media.probe-status :file="$file" />
                <x-media.transcode-status :transcode="$file->latestTranscode" />
            </div>
            <h2 class="text-lg font-semibold">File information</h2>
            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div class="sm:col-span-2 lg:col-span-3"><dt class="text-gray-500 dark:text-gray-400">Location</dt><dd class="break-all font-mono">{{ rtrim($file->libraryPath->path, '/').'/'.$file->relative_path }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Size</dt><dd>{{ $file->formattedSize() }} ({{ number_format($file->size_bytes) }} bytes)</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Container</dt><dd>{{ $file->probe?->format ?? 'Unknown' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Duration</dt><dd>{{ $file->probe?->duration_seconds !== null ? number_format($file->probe->duration_seconds, 1).' seconds' : 'Unknown' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Bitrate</dt><dd>{{ $file->probe?->bit_rate !== null ? number_format($file->probe->bit_rate).' bit/s' : 'Unknown' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Modified (UTC)</dt><dd>{{ $file->filesystem_modified_at->format('Y-m-d H:i:s') }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">First seen (UTC)</dt><dd>{{ $file->first_seen_at->format('Y-m-d H:i:s') }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Last seen (UTC)</dt><dd>{{ $file->last_seen_at->format('Y-m-d H:i:s') }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Last successful probe (UTC)</dt><dd>{{ $file->probe?->probed_at?->format('Y-m-d H:i:s') ?? 'Never' }}</dd></div>
            </dl>
            @if ($file->probe && ! $file->hasCurrentProbe())
                <p class="text-sm text-yellow-700 dark:text-yellow-400">Metadata and streams show the last stored probe and may not describe the current file.</p>
            @endif
            @if ($file->probe?->error)
                <p class="break-all text-sm text-red-600 dark:text-red-400">{{ $file->probe->error }}</p>
            @endif
            @if ($file->probe?->metadata)
                <details class="text-sm" wire:key="container-metadata-{{ $file->id }}" wire:ignore.self>
                    <summary class="cursor-pointer font-medium">Container metadata</summary>
                    <pre class="mt-2 whitespace-pre-wrap break-all">{{ json_encode($file->probe->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
            @endif
        </div>
    </x-card>

    <section aria-label="Media streams" class="space-y-4">
        <h2 class="text-xl font-semibold">Streams</h2>
        @forelse ($file->probe?->streams ?? [] as $stream)
            <div wire:key="stream-{{ $stream->id }}">
                <x-card>
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">#{{ $stream->stream_index }} · {{ ucfirst($stream->codec_type) }} · {{ $stream->codec_name ?? 'Unknown codec' }}</h3>
                            @if ($stream->is_default)<x-badge text="Default" color="blue" />@endif
                            @if ($stream->is_forced)<x-badge text="Forced" color="yellow" />@endif
                            @if ($stream->is_attached_picture)<x-badge text="Attached picture" color="gray" />@endif
                        </div>
                        @if ($stream->title)<p class="break-words text-sm">{{ $stream->title }}</p>@endif
                        <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ([
                                'Language' => $stream->language,
                                'Profile' => $stream->profile,
                                'Resolution' => $stream->width && $stream->height ? $stream->width.' × '.$stream->height : null,
                                'Pixel format' => $stream->pixel_format,
                                'Average frame rate' => $stream->average_frame_rate,
                                'Real frame rate' => $stream->real_frame_rate,
                                'Channels' => $stream->channels,
                                'Channel layout' => $stream->channel_layout,
                                'Sample rate' => $stream->sample_rate !== null ? number_format($stream->sample_rate).' Hz' : null,
                                'Bitrate' => $stream->bit_rate !== null ? number_format($stream->bit_rate).' bit/s' : null,
                                'Duration' => $stream->duration_seconds !== null ? number_format($stream->duration_seconds, 1).' seconds' : null,
                                'Color range' => $stream->color_range,
                                'Color space' => $stream->color_space,
                                'Color transfer' => $stream->color_transfer,
                                'Color primaries' => $stream->color_primaries,
                            ] as $label => $value)
                                @if ($value !== null && $value !== '')
                                    <div wire:key="stream-{{ $stream->id }}-{{ Str::slug($label) }}"><dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="break-words">{{ $value }}</dd></div>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                </x-card>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">No streams available.</p>
        @endforelse
    </section>

    <section aria-label="Transcode history" class="space-y-4">
        <h2 class="text-xl font-semibold">Transcode history</h2>
        @forelse ($transcodes as $transcode)
            <div wire:key="history-{{ $transcode->id }}">
                <x-media.transcode :transcode="$transcode" />
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">No transcodes yet.</p>
        @endforelse
        {{ $transcodes->links() }}
    </section>
</div>
