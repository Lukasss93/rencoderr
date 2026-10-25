@use('App\Tasks\Enums\TaskStatus')

<div class="space-y-6" wire:poll.5s>
    <x-page-header title="Libraries" description="Choose the folders to scan for video files.">
        <x-button text="Add library" icon="plus" wire:click="create" />
    </x-page-header>

    @forelse ($libraries as $library)
        <x-card wire:key="library-{{ $library->id }}">
            <div class="space-y-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-1">
                        <h2 class="text-xl font-semibold">{{ $library->name }}</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $library->automatic_scanning ? 'Automatic scans every '.$library->scan_interval_minutes.' minutes' : 'Automatic scanning disabled' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-button text="Scan now" icon="arrow-path" sm wire:click="scan({{ $library->id }})" loading="scan" />
                        <x-button text="Edit" sm flat wire:click="edit({{ $library->id }})" />
                        <x-button text="Media settings" sm flat :href="route('settings.libraries.media', $library)" wire:navigate />
                        <x-button text="Remove" sm flat color="red" wire:click="delete({{ $library->id }})"
                            wire:confirm="Remove this library and its inventory? Source files will remain unchanged." />
                    </div>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-dark-700">
                    @foreach ($library->paths as $path)
                        <div class="space-y-2 py-3" wire:key="path-{{ $path->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="break-all font-mono text-sm">{{ $path->path }}</p>
                                <x-badge :text="match ($path->scan_status) {
                                        TaskStatus::PENDING => 'Not scanned',
                                        TaskStatus::RUNNING => 'Scanning',
                                        default => ucfirst($path->scan_status->value),
                                    }"
                                    :color="match (true) {
                                        $path->scan_status === TaskStatus::FAILED => 'red',
                                        $path->scan_status->isActive() => 'blue',
                                        default => 'green',
                                    }" />
                            </div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Last successful scan: {{ $path->last_successful_scan_at ? $path->last_successful_scan_at->format('Y-m-d H:i:s').' UTC' : 'Never' }}
                            </p>
                            @if ($path->scan_error)
                                <p class="break-all text-sm text-red-600 dark:text-red-400">{{ $path->scan_error }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </x-card>
    @empty
        <x-card>
            <div class="space-y-3 py-8 text-center">
                <h2 class="text-lg font-semibold">Add your first library</h2>
                <p class="text-gray-500 dark:text-gray-400">Group your movie or TV folders into a library. A scan starts when you add them.</p>
            </div>
        </x-card>
    @endforelse

    <x-modal wire="showEditor" :title="$editingId ? 'Edit library' : 'Add library'" size="3xl" center>
        <form wire:submit="save" class="space-y-6">
            <x-input label="Library name" wire:model="form.name" placeholder="Movies" />
            <div class="space-y-3">
                <h3 class="font-semibold">Folders</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Use paths visible to the application server or container, including mounted network drives.</p>
                @foreach ($form->paths as $index => $path)
                    <div class="space-y-1" wire:key="selected-path-{{ $index }}-{{ md5($path) }}">
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-dark-700">
                            <p class="break-all font-mono text-sm">{{ $path }}</p>
                            <x-button text="Remove" sm flat color="red" wire:click="removePath({{ $index }})"
                                wire:confirm="Remove this folder from the library? Its inventory will be removed when you save. Source files will remain unchanged." />
                        </div>
                        @error('form.paths.'.$index)
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
                @error('form.paths')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
                <x-input label="Absolute folder path" wire:model="form.newPath" placeholder="/media/movies" />
                <div class="flex flex-wrap gap-2">
                    <x-button text="Add folder" sm flat wire:click="addPath" />
                    <x-button text="Browse server folders" sm flat icon="folder" wire:click="browse" />
                </div>
            </div>

            @if ($showBrowser)
                <div class="space-y-4 rounded-lg border border-gray-200 p-4 dark:border-dark-700">
                    <h3 class="font-semibold">Server folders</h3>
                    <x-input label="Navigate to a folder" wire:model="browserPath" />
                    <div class="flex flex-wrap gap-2">
                        <x-button text="Go" sm wire:click="navigate" />
                        <x-button text="Close browser" sm flat wire:click="$set('showBrowser', false)" />
                    </div>
                    @if ($listing)
                        <nav aria-label="Folder breadcrumbs" class="flex flex-wrap gap-2">
                            @foreach ($listing['breadcrumbs'] as $crumb)
                                <button type="button" class="cursor-pointer text-sm text-primary-600 hover:underline dark:text-primary-400"
                                    wire:click="navigate({{ Js::encode($crumb['path']) }})">{{ $crumb['name'] }}</button>
                            @endforeach
                        </nav>
                        @if ($listing['parent'])
                            <x-button text="Parent folder" sm flat icon="arrow-up" :wire:click="'navigate('.Js::encode($listing['parent']).')'" />
                        @endif
                        <div class="max-h-64 space-y-1 overflow-y-auto">
                            @forelse ($listing['directories'] as $directory)
                                <div>
                                    <x-button :text="$directory['name']" icon="folder" sm flat :wire:click="'navigate('.Js::encode($directory['path']).')'" />
                                </div>
                            @empty
                                <p class="text-sm text-gray-500 dark:text-gray-400">No readable subfolders.</p>
                            @endforelse
                        </div>
                        @if ($listing['last_page'] > 1)
                            <div class="flex items-center justify-between gap-2">
                                <x-button text="Previous" sm flat :disabled="$listing['page'] <= 1"
                                    :wire:click="'navigate('.Js::encode($listing['path']).', '.($listing['page'] - 1).')'" />
                                <span class="text-sm">{{ $listing['page'] }} / {{ $listing['last_page'] }}</span>
                                <x-button text="Next" sm flat :disabled="$listing['page'] >= $listing['last_page']"
                                    :wire:click="'navigate('.Js::encode($listing['path']).', '.($listing['page'] + 1).')'" />
                            </div>
                        @endif
                        <x-button text="Select this folder" sm icon="check" wire:click="selectDirectory" />
                    @endif
                </div>
            @endif

            <x-checkbox label="Scan automatically" wire:model="form.automaticScanning" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input label="Scan interval" type="number" min="1" step="1" wire:model="form.interval" />
                <x-select.native label="Interval unit" wire:model="form.intervalUnit" :options="[
                    ['label' => 'Minutes', 'value' => 'minutes'],
                    ['label' => 'Hours', 'value' => 'hours'],
                    ['label' => 'Days', 'value' => 'days'],
                ]" />
            </div>
            <x-textarea label="Exclusion patterns" wire:model="form.exclusions" rows="4"
                hint="One pattern per line. * matches any characters. Patterns are case-sensitive and match filenames or relative paths; matching folders skip their contents."
                placeholder="Extras&#10;*sample*" />
            <div class="flex justify-end gap-3">
                <x-button text="Cancel" flat wire:click="$set('showEditor', false)" />
                <x-button text="Save library" type="submit" loading="save" />
            </div>
        </form>
    </x-modal>
</div>
