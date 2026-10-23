<div class="space-y-6" wire:poll.5s>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-2">
            <h1 class="text-3xl font-semibold tracking-tight">Workers</h1>
            <p class="text-gray-600 dark:text-gray-300">Control how many background jobs can run at once.</p>
        </div>
        <x-navigation-link :href="route('horizon.index')" icon="chart-bar" :navigate="false">
            Horizon
        </x-navigation-link>
    </div>

    @if ($notice !== '')
        <p role="status" class="rounded-lg bg-primary-50 p-4 text-sm text-primary-800 dark:bg-primary-950 dark:text-primary-200">{{ $notice }}</p>
    @endif

    <x-card header="Queue workers">
        <form wire:submit="save" class="space-y-4">
            <x-input label="Maximum workers" type="number" min="1" step="1" wire:model="maxWorkers"
                hint="Horizon keeps at least one worker and adds more as queued work increases. Scans and media probes share this limit." />
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Changes apply automatically without restarting the container. When you lower the limit, workers finish their current jobs before stopping, within the existing job timeouts.
            </p>
            <div class="flex justify-end">
                <x-button text="Save" type="submit" loading="save" />
            </div>
        </form>
    </x-card>

    <x-card header="Worker status">
        <div class="space-y-4" role="status">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="font-semibold">{{ $runtime['state'] === 'unavailable' ? 'Status unavailable' : ucfirst($runtime['state']) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Saved maximum</dt>
                    <dd class="font-semibold">{{ $savedLimit }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Current worker limit</dt>
                    <dd class="font-semibold">{{ $runtime['limit'] ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Current workers</dt>
                    <dd class="font-semibold">{{ $runtime['workers'] ?? 'Unknown' }}</dd>
                </div>
            </dl>

            @if ($runtime['state'] === 'offline')
                <p class="text-sm text-gray-500 dark:text-gray-400">The saved limit will apply when Horizon starts.</p>
            @elseif ($runtime['state'] === 'unavailable')
                <p class="text-sm text-gray-500 dark:text-gray-400">Worker status could not be read. You can still save a limit; its application cannot currently be confirmed.</p>
            @else
                @if ($runtime['limit'] !== $savedLimit)
                    <p class="text-sm text-gray-500 dark:text-gray-400">Waiting for Horizon to pick up the saved limit.</p>
                @endif
                @if ($runtime['state'] === 'paused')
                    <p class="text-sm text-gray-500 dark:text-gray-400">Horizon is paused. Saving a limit does not resume processing.</p>
                @endif
                <p class="text-sm text-gray-500 dark:text-gray-400">Worker counts adjust gradually with demand. Workers finishing their last job are not included in the current count.</p>
            @endif
        </div>
    </x-card>
</div>
