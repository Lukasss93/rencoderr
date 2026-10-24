@use('App\Workers\Enums\WorkerState')

<div class="space-y-6" wire:poll.5s>
    <x-page-header title="Workers" description="Control how many background jobs can run at once.">
        <x-navigation-link :href="route('horizon.index')" icon="chart-bar" :navigate="false">
            Horizon
        </x-navigation-link>
    </x-page-header>

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
                    <dd class="font-semibold">{{ $status->state->label() }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Saved maximum</dt>
                    <dd class="font-semibold">{{ $savedLimit }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Current worker limit</dt>
                    <dd class="font-semibold">{{ $status->limit ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Current workers</dt>
                    <dd class="font-semibold">{{ $status->workers ?? 'Unknown' }}</dd>
                </div>
            </dl>

            @if ($status->state === WorkerState::OFFLINE)
                <p class="text-sm text-gray-500 dark:text-gray-400">The saved limit will apply when Horizon starts.</p>
            @elseif ($status->state === WorkerState::UNAVAILABLE)
                <p class="text-sm text-gray-500 dark:text-gray-400">Worker status could not be read. You can still save a limit; its application cannot currently be confirmed.</p>
            @else
                @if ($status->limit !== $savedLimit)
                    <p class="text-sm text-gray-500 dark:text-gray-400">Waiting for Horizon to pick up the saved limit.</p>
                @endif
                @if ($status->state === WorkerState::PAUSED)
                    <p class="text-sm text-gray-500 dark:text-gray-400">Horizon is paused. Saving a limit does not resume processing.</p>
                @endif
                <p class="text-sm text-gray-500 dark:text-gray-400">Worker counts adjust gradually with demand. Workers finishing their last job are not included in the current count.</p>
            @endif
        </div>
    </x-card>
</div>
