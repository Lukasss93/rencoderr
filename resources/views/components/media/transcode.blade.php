@props(['transcode', 'qualityActions' => false])
@use('Illuminate\Support\Number')

<x-card>
    <div class="space-y-3">
        @if ($transcode->media_file_id)
            <a class="break-all font-mono text-sm text-primary-600 hover:underline dark:text-primary-400" href="{{ route('media.show', $transcode->media_file_id) }}" wire:navigate>{{ $transcode->source_path }}</a>
        @else
            <p class="break-all font-mono text-sm">{{ $transcode->source_path }}</p>
        @endif
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $transcode->run->library_name }} · Run #{{ $transcode->transcode_run_id }} · Transcode #{{ $transcode->id }}</p>
        <x-media.transcode-status :transcode="$transcode" />
        @if ($transcode->optimization)
            @if ($transcode->optimization['enabled'] ?? false)
                <p class="text-sm">Automatic storage optimization · {{ count($transcode->optimization['candidates'] ?? []) }} candidates measured</p>
                @if ($transcode->optimization['winner'] ?? null)
                    <p class="text-sm">Estimated savings: {{ number_format($transcode->optimization['winner']['estimated_savings_percent'] ?? 0, 1) }}% · Selected quality: {{ $transcode->optimization['winner']['quality'] }} · {{ $transcode->optimization['winner']['encoder'] ?? '' }}</p>
                @elseif ($transcode->optimization['closest'] ?? null)
                    <p class="text-sm">Closest measured result: {{ $transcode->optimization['closest']['encoder'] }} · Quality {{ $transcode->optimization['closest']['quality'] }} · Lowest window VMAF: {{ number_format($transcode->optimization['closest']['vmaf'], 2) }} (required {{ number_format($transcode->optimization['policy']['quality_floor'], 2) }}) · Estimated output: {{ Number::fileSize($transcode->optimization['closest']['bytes'], maxPrecision: 2) }} · {{ $transcode->optimization['closest']['within_size_budget'] ? 'Within size budget' : 'Above size budget' }}</p>
                @endif
            @else
                <p class="text-sm">Direct encoding: automatic storage optimization is disabled because a required savings or video quality check is disabled.</p>
            @endif
            <details class="text-sm" wire:key="optimization-details-{{ $transcode->id }}" wire:ignore.self>
                <summary class="cursor-pointer font-medium">Optimization measurements</summary>
                @foreach ($transcode->optimization['candidates'] ?? [] as $candidate)
                    <p class="mt-2">{{ $candidate['encoder'] ?? 'Encoder' }} · Quality {{ $candidate['quality'] }} · Lowest window score: {{ number_format($candidate['measurements']['minimum_vmaf'] ?? 0, 2) }} · Estimated output: {{ Number::fileSize($candidate['bytes'] ?? 0, maxPrecision: 2) }} · {{ ($candidate['passes'] ?? false) ? 'Sample quality passed' : 'Sample quality rejected' }}</p>
                @endforeach
                @foreach ($transcode->optimization['limits'] ?? [] as $limit)
                    <p class="mt-2">{{ $limit }}</p>
                @endforeach
                <pre class="mt-2 whitespace-pre-wrap break-all">{{ json_encode($transcode->optimization, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @endif
        <p class="text-sm">Reclaimed disk space: 0 bytes. Originals and staged outputs are retained.</p>
        @if ($transcode->qualityCheck)
            <x-media.quality-check :check="$transcode->qualityCheck" :actions="$qualityActions" />
        @endif
        <dl class="grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-gray-500 dark:text-gray-400">Encoder</dt><dd>{{ $transcode->encoder ?? 'Not selected yet' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Progress</dt><dd>{{ $transcode->progress_percent !== null ? number_format($transcode->progress_percent, 1).'%' : 'Unknown' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Elapsed</dt><dd>{{ $transcode->started_at ? (int) $transcode->started_at->diffInSeconds($transcode->finished_at ?? now()) . ' seconds' : 'Not started' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Requested (UTC)</dt><dd>{{ $transcode->created_at->format('Y-m-d H:i:s') }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Started (UTC)</dt><dd>{{ $transcode->started_at?->format('Y-m-d H:i:s') ?? 'Not started' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Finished (UTC)</dt><dd>{{ $transcode->finished_at?->format('Y-m-d H:i:s') ?? 'Not finished' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Input size</dt><dd>{{ Number::fileSize($transcode->source_size_bytes, maxPrecision: 2) }} ({{ number_format($transcode->source_size_bytes) }} bytes)</dd></div>
            @if ($transcode->output_size_bytes !== null)
                <div><dt class="text-gray-500 dark:text-gray-400">Output size</dt><dd>{{ Number::fileSize($transcode->output_size_bytes, maxPrecision: 2) }} ({{ number_format($transcode->output_size_bytes) }} bytes)</dd></div>
                @if ($transcode->source_size_bytes > 0)
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ $transcode->qualityCheck?->verdict === \App\QualityControl\Enums\QualityVerdict::PASSED ? 'Verified potential savings' : 'Output size reduction (not approved)' }}</dt><dd>{{ number_format(100 * (1 - $transcode->output_size_bytes / $transcode->source_size_bytes), 1) }}%</dd></div>
                @endif
            @endif
        </dl>
        @if ($transcode->encoded_seconds !== null)
            <p class="text-sm text-gray-500 dark:text-gray-400">Encoded media: {{ number_format($transcode->encoded_seconds, 1) }} seconds</p>
        @endif
        @if ($transcode->output_path)
            <p class="break-all text-sm">Staged output: <code>{{ $transcode->output_path }}</code></p>
        @endif
        @if ($transcode->error)
            <p class="break-all text-sm text-red-600 dark:text-red-400">{{ $transcode->error }}</p>
        @endif
        @if ($transcode->skip_reason)
            <p class="break-all text-sm text-gray-500 dark:text-gray-400">{{ $transcode->skip_reason }}</p>
        @endif
        @if ($transcode->diagnostic_tail)
            <details class="text-sm" wire:key="encoder-diagnostics-{{ $transcode->id }}" wire:ignore.self>
                <summary class="cursor-pointer font-medium">Recent encoder diagnostics</summary>
                <pre class="mt-2 whitespace-pre-wrap break-all">{{ substr($transcode->diagnostic_tail, -4096) }}</pre>
            </details>
        @endif
        @if ($transcode->arguments || $transcode->stream_decisions || $transcode->settings->isNotEmpty())
            <details class="space-y-2 text-sm" wire:key="encoding-details-{{ $transcode->id }}" wire:ignore.self>
                <summary class="cursor-pointer font-medium">Encoding details</summary>
                @if ($transcode->arguments)
                    <h3 class="font-medium">Encoder arguments</h3>
                    <pre class="whitespace-pre-wrap break-all">{{ json_encode($transcode->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                @endif
                @if ($transcode->stream_decisions)
                    <h3 class="font-medium">Stream decisions</h3>
                    <pre class="whitespace-pre-wrap break-all">{{ json_encode($transcode->stream_decisions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                @endif
                @if ($transcode->settings->isNotEmpty())
                    <h3 class="font-medium">Settings used</h3>
                    <pre class="whitespace-pre-wrap break-all">{{ json_encode($transcode->settings->pluck('value', 'key'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                @endif
            </details>
        @endif
    </div>
</x-card>
