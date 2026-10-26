<div class="space-y-8" wire:poll.5s>
    <x-page-header title="Dashboard" description="An overview of your media library and its storage.">
        <x-button text="View queue" icon="queue-list" flat :href="route('queue.index')" wire:navigate />
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach (['present' => 'Present files', 'missing' => 'Missing files', 'excluded' => 'Excluded files'] as $statusKey => $label)
            <x-card>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-2 text-3xl font-semibold tracking-tight">{{ number_format($counts[$statusKey]) }}</p>
            </x-card>
        @endforeach
        <x-card>
            <p class="text-sm text-gray-500 dark:text-gray-400">Present file size</p>
            <p class="mt-2 text-2xl font-semibold">{{ $presentSize }}</p>
        </x-card>
    </div>

    <div class="space-y-1">
        <h2 class="text-lg font-semibold">Media inventory</h2>
        <p class="text-sm text-gray-500 dark:text-dark-400">Browse the video files discovered across your libraries.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-input label="Search files" type="search" wire:model.live.debounce.300ms="search" placeholder="Filename or relative path" />
        <x-select.native label="Library" wire:model.live="library" :options="$libraryOptions" />
        <x-select.native label="Availability" wire:model.live="status" :options="$statusOptions" />
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-gray-500 dark:text-gray-400">
            @if ($selectedLibrary)
                <p>Transcode all eligible files in <strong>{{ $selectedLibrary->name }}</strong> using its saved media settings. Search and availability filters do not limit the run.</p>
                @if ($activeRuns->has($selectedLibrary->id))
                    <p>A transcode run is already active for this library. Follow its progress on the Queue page.</p>
                @endif
            @elseif ($library === '' && count($libraryOptions) > 1)
                <p>Transcode all eligible files across all libraries using each library's saved media settings. Existing active runs are reused. Search and availability filters do not limit the runs.</p>
            @else
                <p>Add or select a library to start a library transcode, or transcode an individual file below.</p>
            @endif
        </div>
        <x-button text="Transcode library" icon="film" wire:click="transcodeLibrary" loading="transcodeLibrary"
            :disabled="$selectedLibrary ? $activeRuns->has($selectedLibrary->id) : ($library !== '' || count($libraryOptions) === 1)" />
    </div>
    @error('library')
        <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
    @error('transcode')
        <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror

    <x-table :headers="[
        ['index' => 'relative_path', 'label' => 'File'],
        ['index' => 'library', 'label' => 'Library / root'],
        ['index' => 'size_bytes', 'label' => 'Size'],
        ['index' => 'filesystem_modified_at', 'label' => 'Modified (UTC)'],
        ['index' => 'status', 'label' => 'Availability'],
        ['index' => 'processing', 'label' => 'Processing'],
        ['index' => 'action', 'label' => 'Actions'],
    ]" :rows="$files" paginate>
        @interact('column_relative_path', $file)
            <div class="max-w-sm whitespace-normal">
                <a href="{{ route('media.show', $file) }}" wire:navigate class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ basename($file->relative_path) }}</a>
                <p class="break-all text-xs">{{ $file->relative_path }}</p>
            </div>
        @endinteract
        @interact('column_library', $file)
            <div class="max-w-xs whitespace-normal">
                <p>{{ $file->libraryPath->library->name }}</p>
                <p class="break-all font-mono text-xs">{{ $file->libraryPath->path }}</p>
            </div>
        @endinteract
        @interact('column_size_bytes', $file)
            {{ $file->formattedSize() }}
        @endinteract
        @interact('column_filesystem_modified_at', $file)
            {{ $file->filesystem_modified_at->format('Y-m-d H:i:s') }}
        @endinteract
        @interact('column_status', $file)
            <x-badge :text="ucfirst($file->status->value)" :color="match ($file->status) {
                \App\Library\Enums\MediaFileStatus::PRESENT => 'green',
                \App\Library\Enums\MediaFileStatus::MISSING => 'red',
                \App\Library\Enums\MediaFileStatus::EXCLUDED => 'gray',
            }" />
        @endinteract
        @interact('column_processing', $file)
            <div class="flex flex-col items-start gap-2">
                <x-media.probe-status :file="$file" />
                <x-media.transcode-status :transcode="$file->latestTranscode" />
                @if ($file->latestTranscode?->progress_percent !== null)
                    <span class="text-xs">{{ number_format($file->latestTranscode->progress_percent, 1) }}%</span>
                @endif
            </div>
        @endinteract
        @interact('column_action', $file, $activeRuns)
            @php($reason = $file->transcodeUnavailableReason() ?? ($activeRuns->has($file->libraryPath->library_id) ? 'This library already has an active transcode run.' : null))
            <div class="max-w-xs space-y-2 whitespace-normal">
                <x-button text="Transcode" icon="film" sm wire:click="transcodeFile({{ $file->id }})" loading="transcodeFile" :disabled="$reason !== null" />
                @if ($reason)
                    <p class="text-xs">{{ $reason }}</p>
                @endif
            </div>
        @endinteract
        <x-slot:empty>
            No video files match these filters. Add a library or run a scan to discover your media.
        </x-slot:empty>
    </x-table>
    <p class="text-xs text-gray-500 dark:text-gray-400">Counts and size reflect the current filters. Missing and excluded files retain their last known metadata.</p>
</div>
