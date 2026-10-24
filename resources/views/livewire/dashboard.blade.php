<div class="space-y-8" wire:poll.5s>
    <x-page-header title="Dashboard" description="An overview of your media library and its storage." />

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

    <x-table :headers="[
        ['index' => 'relative_path', 'label' => 'File'],
        ['index' => 'library', 'label' => 'Library / root'],
        ['index' => 'size_bytes', 'label' => 'Size'],
        ['index' => 'filesystem_modified_at', 'label' => 'Modified (UTC)'],
        ['index' => 'status', 'label' => 'Availability'],
    ]" :rows="$files" paginate>
        @interact('column_relative_path', $file)
            <div class="max-w-sm whitespace-normal">
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ basename($file->relative_path) }}</p>
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
        <x-slot:empty>
            No video files match these filters. Add a library or run a scan to discover your media.
        </x-slot:empty>
    </x-table>
    <p class="text-xs text-gray-500 dark:text-gray-400">Counts and size reflect the current filters. Missing and excluded files retain their last known metadata.</p>
</div>
