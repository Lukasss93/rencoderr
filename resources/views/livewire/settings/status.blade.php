<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-2">
            <h1 class="text-3xl font-semibold tracking-tight">Status</h1>
            <p class="text-gray-600 dark:text-gray-300">Hardware acceleration and encoding capabilities on this application server.</p>
        </div>
        <x-button text="Refresh detection" icon="arrow-path" wire:click="refreshDetection" loading="refreshDetection"
            wire:target="testEncoder,refreshDetection,saveHardwareAcceleration" />
    </div>

    <div class="grid gap-4 sm:grid-cols-3" aria-label="Encoding summary">
        <x-card>
            <div class="space-y-2">
                <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">FFmpeg</h2>
                <p class="break-all text-xl font-semibold">{{ $report['version'] ?? 'Unavailable' }}</p>
                <x-badge :text="$report['error'] ? 'Needs attention' : 'Detected'" :color="$report['error'] ? 'yellow' : 'green'" light />
            </div>
        </x-card>
        <x-card>
            <div class="space-y-2">
                <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Video</h2>
                <p class="text-3xl font-semibold">{{ $report['error'] ? '—' : $videoCount }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Available encoders</p>
            </div>
        </x-card>
        <x-card>
            <div class="space-y-2">
                <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400">Audio</h2>
                <p class="text-3xl font-semibold">{{ $report['error'] ? '—' : $audioCount }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Available encoders</p>
            </div>
        </x-card>
    </div>

    <x-card>
        <x-slot:header>
            <h2 class="font-semibold">Encoding preference</h2>
        </x-slot:header>
        <form wire:submit="saveHardwareAcceleration" class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-300">This preference applies to the whole application. Automatic prefers NVIDIA NVENC, then Intel Quick Sync, then VAAPI, followed by other available encoding backends.</p>
            <div class="flex flex-wrap items-end gap-4">
                <div class="min-w-0 grow">
                    <x-select.native id="preferred-acceleration" wire:model="preferredAcceleration" label="Preferred hardware acceleration">
                        <option value="auto">Automatic</option>
                        <option value="software">Software (CPU)</option>
                        @foreach ($availableBackends as $backend => $label)
                            <option value="{{ $backend }}">{{ $label }}</option>
                        @endforeach
                        @if ($savedAccelerationUnavailable)
                            <option value="{{ $savedAcceleration }}" disabled>{{ $report['acceleration']['backends'][$savedAcceleration]['label'] ?? strtoupper($savedAcceleration) }} (unavailable)</option>
                        @endif
                    </x-select.native>
                </div>
                <x-button submit text="Save preference" loading="saveHardwareAcceleration"
                    wire:target="testEncoder,refreshDetection,saveHardwareAcceleration" />
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-300">Current backend: {{ $effectiveAcceleration === null ? 'Software (CPU)' : $availableBackends[$effectiveAcceleration] }}.</p>
            @if ($savedAccelerationUnavailable)
                <p role="alert" class="text-sm text-amber-700 dark:text-amber-400">Your saved hardware backend is unavailable. Encoding will fall back to Software (CPU).</p>
            @endif
            @if ($notice)
                <p role="status" class="text-sm text-green-700 dark:text-green-400">{{ $notice }}</p>
            @endif
        </form>
    </x-card>

    <p role="status" class="text-sm text-gray-500 dark:text-gray-400">
        <span wire:loading.remove wire:target="refreshDetection">Last detection: {{ $checkedAt }}</span>
        <span wire:loading wire:target="refreshDetection">Checking hardware acceleration and installed encoders…</span>
    </p>

    @if ($report['error'])
        <x-card>
            <div role="alert" class="space-y-2">
                <h2 class="font-semibold text-red-600 dark:text-red-400">{{ $report['version'] ? 'Detection failed' : 'FFmpeg unavailable' }}</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $report['error'] }}</p>
            </div>
        </x-card>
    @else
        <x-card loading="refreshDetection">
            <x-slot:header>
                <h2 class="font-semibold">Hardware acceleration</h2>
            </x-slot:header>
            <div class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">Availability checks use the default device. Device availability and codec support are checked separately. A failed check may mean a missing driver, an inaccessible device, or a timeout.</p>
                @if ($report['acceleration']['error'])
                    <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $report['acceleration']['error'] }}</p>
                @endif
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @forelse ($report['acceleration']['backends'] as $name => $backend)
                        <section class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-dark-700" wire:key="backend-{{ $name }}" aria-label="{{ $backend['label'] }}">
                            <h3 class="font-semibold">{{ $backend['label'] }}</h3>
                            <x-badge :text="$backend['available'] ? 'Available' : 'Unavailable'"
                                :color="$backend['available'] ? 'green' : 'gray'" light />
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $backend['encoders'] }} installed {{ $backend['encoders'] === 1 ? 'encoder' : 'encoders' }}</p>
                            <details class="text-sm text-gray-600 dark:text-gray-300">
                                <summary class="cursor-pointer">Detection details</summary>
                                <p class="mt-2 break-words whitespace-pre-line">{{ $backend['message'] }}</p>
                            </details>
                        </section>
                    @empty
                        @unless ($report['acceleration']['error'])
                            <p class="text-sm text-gray-500 dark:text-gray-400">No hardware acceleration backends detected.</p>
                        @endunless
                    @endforelse
                </div>
            </div>
        </x-card>

        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p>Available encoders are grouped into hardware accelerated and software (CPU), then by codec. Hardware encoders require both FFmpeg support and an available acceleration backend.</p>
            <p>Test encoder verifies H.264, HEVC, or AV1 with NVIDIA, VAAPI, or Intel Quick Sync using one generated frame and the default device. Tests do not read or change library files.</p>
        </div>

        @foreach ($groups as $type => $categories)
            <x-card wire:key="encoders-{{ $type }}" loading="refreshDetection">
                <x-slot:header>
                    <h2 class="font-semibold">{{ $type }} encoders</h2>
                </x-slot:header>
                <div class="grid gap-6 xl:grid-cols-2">
                    @foreach ($categories as $category => $codecs)
                        @continue($category === 'Supported' && $codecs === [])
                        <section @class(['min-w-0 space-y-4', 'xl:col-span-2' => $category === 'Supported']) wire:key="category-{{ $type }}-{{ $category }}" aria-label="{{ $type }} {{ strtolower($category) }} encoders">
                            @if ($category === 'Supported')
                                <details class="rounded-lg border border-gray-200 p-4 dark:border-dark-700">
                                    <summary class="cursor-pointer font-semibold">Supported by FFmpeg, unavailable on this hardware</summary>
                                    <p class="my-4 text-sm text-gray-600 dark:text-gray-300">These encoders are included in the installed FFmpeg build, but are not available with the current hardware setup. The required hardware or drivers are unavailable, or the encoder check failed.</p>
                            @else
                                <h3 class="font-semibold">{{ $category === 'Software' ? 'Software (CPU)' : 'Hardware accelerated' }}</h3>
                            @endif
                            <div class="max-h-96 space-y-5 overflow-y-auto" tabindex="0" aria-label="{{ $type }} {{ strtolower($category) }} encoders by codec">
                                @forelse ($codecs as $codec => $encoders)
                                    <div class="space-y-2" wire:key="codec-{{ $type }}-{{ $category }}-{{ $codec }}">
                                        <h4 class="text-sm font-semibold uppercase tracking-wide">{{ $codec }}</h4>
                                        <div class="divide-y divide-gray-100 dark:divide-dark-700">
                                            @foreach ($encoders as $encoder)
                                                <div class="space-y-2 py-3" wire:key="encoder-{{ $encoder['name'] }}">
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <p class="break-all font-mono text-sm">{{ $encoder['name'] }}</p>
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            @if ($encoder['hardware'])
                                                                <x-badge :text="strtoupper($encoder['backend'])" color="gray" light />
                                                                @if (isset($checks[$encoder['name']]))
                                                                    <x-badge :text="$checks[$encoder['name']]['successful'] ? 'Verified' : 'Test failed'"
                                                                        :color="$checks[$encoder['name']]['successful'] ? 'green' : 'red'" light />

                                                                @endif
                                                                @if ($encoder['testable'])
                                                                    <x-button text="Test encoder" sm flat
                                                                        :aria-label="'Test encoder '.$encoder['name']"
                                                                        :wire:click="'testEncoder('.Js::encode($encoder['name']).')'"
                                                                        :loading="'testEncoder('.Js::encode($encoder['name']).')'"
                                                                        wire:target="testEncoder,refreshDetection,saveHardwareAcceleration" />
                                                                @endif
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $encoder['description'] }}</p>
                                                    @if (isset($checks[$encoder['name']]))
                                                        <p role="status" class="break-words whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $checks[$encoder['name']]['message'] }}</p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No {{ strtolower($category) }} {{ strtolower($type) }} encoders detected.</p>
                                @endforelse
                            </div>
                            @if ($category === 'Supported')
                                </details>
                            @endif
                        </section>
                    @endforeach
                </div>
            </x-card>
        @endforeach
    @endif
</div>
