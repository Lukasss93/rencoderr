<div class="space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-2">
            <h1 class="text-3xl font-semibold tracking-tight">Queue</h1>
            <p class="text-gray-500 dark:text-dark-400">Your media transcoding jobs and their progress.</p>
        </div>
        <x-badge text="Queue empty" color="gray" />
    </div>

    <x-card>
        <section class="flex flex-col items-center gap-4 px-4 py-12 text-center" aria-labelledby="transcoding-queue-heading">
            <span class="rounded-xl bg-gray-50 p-4 text-gray-400 dark:bg-dark-900 dark:text-dark-400">
                <x-icon icon="queue-list" class="size-8" aria-hidden="true" />
            </span>
            <div class="max-w-md space-y-2">
                <h2 id="transcoding-queue-heading" class="text-lg font-semibold text-gray-900 dark:text-white">No transcoding jobs yet</h2>
                <p class="text-sm text-gray-500 dark:text-dark-400">When transcoding is available, queued jobs and their progress will appear here.</p>
            </div>
        </section>
    </x-card>
</div>
