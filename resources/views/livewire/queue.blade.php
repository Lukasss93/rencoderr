<div class="space-y-8">
    <x-page-header title="Queue" description="Your media transcoding jobs and their progress.">
        <x-badge text="Queue empty" color="gray" />
    </x-page-header>

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
