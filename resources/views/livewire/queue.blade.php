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
                <p class="text-sm text-gray-500 dark:text-gray-400">Choose Transcode library in Settings to start a run.</p>
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
                                    <p class="text-sm">{{ $run->status === TaskStatus::COMPLETED && $run->completed_count > 0 ? 'Awaiting quality control' : ucfirst($run->status->value) }}</p>
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
                    <x-card>
                        <div class="space-y-3">
                            <p class="break-all font-mono text-sm">{{ $transcode->source_path }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $transcode->run->library_name }} · Run #{{ $transcode->transcode_run_id }}</p>
                            <p class="font-semibold">{{ $transcode->status === TaskStatus::COMPLETED ? 'Awaiting quality control' : ucfirst($transcode->status->value) }}</p>
                            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                                <div><dt class="text-gray-500 dark:text-gray-400">Encoder</dt><dd>{{ $transcode->encoder ?? 'Not selected yet' }}</dd></div>
                                <div><dt class="text-gray-500 dark:text-gray-400">Progress</dt><dd>{{ $transcode->progress_percent !== null ? number_format($transcode->progress_percent, 1).'%' : 'Unknown' }}</dd></div>
                                <div><dt class="text-gray-500 dark:text-gray-400">Elapsed</dt><dd>{{ $transcode->started_at ? (int) $transcode->started_at->diffInSeconds($transcode->finished_at ?? now()) . ' seconds' : 'Not started' }}</dd></div>
                            </dl>
                            @if ($transcode->encoded_seconds !== null)
                                <p class="text-sm text-gray-500 dark:text-gray-400">Encoded media: {{ number_format($transcode->encoded_seconds, 1) }} seconds</p>
                            @endif
                            @if ($transcode->output_path)
                                <p class="break-all text-sm">Staged output: <code>{{ $transcode->output_path }}</code></p>
                            @endif
                            @if ($transcode->diagnostic_tail)
                                <details class="text-sm">
                                    <summary>Recent encoder diagnostics</summary>
                                    <pre class="whitespace-pre-wrap break-all">{{ substr($transcode->diagnostic_tail, -4096) }}</pre>
                                </details>
                            @endif
                            @if ($transcode->output_size_bytes !== null)
                                <p class="text-sm text-gray-500 dark:text-gray-400">Output size: {{ number_format($transcode->output_size_bytes) }} bytes</p>
                            @endif
                            @if ($transcode->error)
                                <p class="break-all text-sm text-red-600 dark:text-red-400">{{ $transcode->error }}</p>
                            @endif
                            @if ($transcode->skip_reason)
                                <p class="break-all text-sm text-gray-500 dark:text-gray-400">{{ $transcode->skip_reason }}</p>
                            @endif
                        </div>
                    </x-card>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No files have been queued yet.</p>
            @endforelse
            {{ $transcodes->links() }}
        </section>
    @endif
</div>
