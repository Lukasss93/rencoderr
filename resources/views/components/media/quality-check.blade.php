@props(['check', 'actions' => false])
@use('App\Tasks\Enums\TaskStatus')

<section aria-label="Quality control" class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-dark-700">
    <h3 class="font-medium">Quality control</h3>
    <dl class="grid gap-3 text-sm sm:grid-cols-3">
        <div><dt class="text-gray-500 dark:text-gray-400">Phase</dt><dd>{{ $check->phase ? ucfirst(str_replace('_', ' ', $check->phase)) : ucfirst($check->status->value) }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Check progress</dt><dd>{{ $check->progress_percent !== null ? number_format($check->progress_percent, 1).'%' : 'Not measured yet' }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">VMAF (perceptual video quality)</dt><dd>{{ ! ($check->policy['media']['verify_video_quality'] ?? true) ? 'Disabled' : ($check->vmaf_mean !== null ? number_format($check->vmaf_mean, 2).' / 100' : 'Not measured yet') }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Required score</dt><dd>{{ $check->policy['media']['minimum_vmaf'] ?? 'Unknown' }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Measured coverage</dt><dd>{{ $check->coverage_seconds !== null ? number_format($check->coverage_seconds, 1).' seconds' : 'Not measured yet' }} · {{ ucfirst($check->policy['mode'] ?? 'sampled') }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Savings check</dt><dd>{{ ! ($check->policy['media']['require_smaller_output'] ?? true) ? 'Disabled' : 'Minimum '.($check->policy['media']['minimum_savings_percent'] ?? 5).'%' }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Elapsed</dt><dd>{{ $check->started_at ? (int) $check->started_at->diffInSeconds($check->finished_at ?? now()).' seconds' : 'Not started' }}</dd></div>
    </dl>
    @if (($check->results['comparison_view'] ?? null) === 'tone_mapped_sdr')
        <p class="text-sm text-gray-500 dark:text-gray-400">HDR quality is measured using tone-mapped SDR views. HDR metadata is checked separately.</p>
    @endif
    @if ($check->error)
        <p class="break-all text-sm text-red-600 dark:text-red-400">{{ $check->error }}</p>
    @endif
    @if ($check->cancel_requested_at && $check->status === TaskStatus::RUNNING)
        <p class="text-sm">Cancellation requested. Waiting for the check to stop.</p>
    @endif
    @if ($actions)
        <div class="flex flex-wrap gap-2">
            @if (in_array($check->status, [TaskStatus::QUEUED, TaskStatus::RUNNING], true) && ! $check->cancel_requested_at)
                <x-button text="Cancel quality control" color="red" outline wire:click="cancelQuality({{ $check->id }})" loading="cancelQuality({{ $check->id }})" />
            @elseif (in_array($check->status, [TaskStatus::FAILED, TaskStatus::CANCELLED], true))
                <x-button text="Retry quality control" wire:click="retryQuality({{ $check->id }})" loading="retryQuality({{ $check->id }})" />
            @endif
        </div>
    @endif
    <details class="space-y-2 text-sm" wire:key="quality-details-{{ $check->id }}" wire:ignore.self>
        <summary class="cursor-pointer font-medium">Quality-check details</summary>
        <p class="break-all">Model SHA-256: {{ $check->model_sha256 ?? 'Not loaded' }}</p>
        <pre class="whitespace-pre-wrap break-all">{{ json_encode(['policy' => $check->policy, 'results' => $check->results, 'expected_streams' => $check->expected_contract, 'binaries' => $check->binary_versions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        @if ($check->diagnostic_tail)
            <pre class="whitespace-pre-wrap break-all">{{ substr($check->diagnostic_tail, -4096) }}</pre>
        @endif
    </details>
</section>
