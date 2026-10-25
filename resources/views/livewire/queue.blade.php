@use('App\Tasks\Enums\TaskStatus')

<div class="space-y-6" wire:poll.5s>
    <x-page-header title="Queue" description="Your media transcoding jobs and their progress.">
        @if ($runs->total() === 0)
            <x-badge text="Queue empty" color="gray" />
        @endif
    </x-page-header>

    @error('run')
        <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror

    @if ($runs->total() === 0)
        <x-card>
            <div class="space-y-3 py-8 text-center">
                <h2 class="text-lg font-semibold">No transcoding jobs yet</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Choose a library or an individual file on the Dashboard to start a transcode.</p>
            </div>
        </x-card>
    @else
        <section aria-label="Transcode runs" class="space-y-4">
            @foreach ($runs as $run)
                <div wire:key="run-{{ $run->id }}">
                    <x-card>
                        <div class="space-y-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="space-y-1">
                                    <h2 class="font-semibold">{{ $run->library_name }} · Run #{{ $run->id }}</h2>
                                    <p class="text-sm">{{ ucfirst($run->status->value) }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @if (in_array($run->status, [TaskStatus::PENDING, TaskStatus::QUEUED, TaskStatus::RUNNING], true) && ! $run->cancel_requested_at)
                                        <x-button text="Cancel run" color="red" sm flat wire:click="cancel({{ $run->id }})" loading="cancel"
                                            wire:confirm="Cancel this run? Retained outputs remain available for quality control." />
                                    @endif
                                    @if ($run->failed_count > 0 && ! in_array($run->status, [TaskStatus::PENDING, TaskStatus::QUEUED, TaskStatus::RUNNING], true))
                                        <x-button text="Retry failed" sm wire:click="retryFailed({{ $run->id }})" loading="retryFailed" />
                                    @endif
                                </div>
                            </div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $run->transcodes_count }} files · {{ $run->completed_count }} encoded · {{ $run->skipped_count }} skipped · {{ $run->failed_count }} failed · {{ $run->excluded_metadata_count }} excluded for missing metadata · {{ $run->retained_output_count }} retained output{{ $run->retained_output_count === 1 ? '' : 's' }}
                            </p>
                            @if (! $run->enumeration_finished_at && in_array($run->status, [TaskStatus::PENDING, TaskStatus::QUEUED, TaskStatus::RUNNING], true))
                                <p class="text-sm text-gray-500 dark:text-gray-400">Enumerating the library inventory.</p>
                            @endif
                            @if ($run->cancel_requested_at && in_array($run->status, [TaskStatus::PENDING, TaskStatus::QUEUED, TaskStatus::RUNNING], true))
                                <p class="text-sm">Cancellation requested.</p>
                            @endif
                            @if ($run->error)
                                <p class="break-all text-sm text-red-600 dark:text-red-400">{{ $run->error }}</p>
                            @endif
                        </div>
                    </x-card>
                </div>
            @endforeach
            {{ $runs->links() }}
        </section>

        <section aria-label="Individual transcodes" class="space-y-4">
            <h2 class="text-xl font-semibold">Files</h2>
            @forelse ($transcodes as $transcode)
                <div wire:key="transcode-{{ $transcode->id }}">
                    <x-media.transcode :transcode="$transcode" :quality-actions="true" />
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No files have been queued yet.</p>
            @endforelse
            {{ $transcodes->links() }}
        </section>
    @endif
</div>
