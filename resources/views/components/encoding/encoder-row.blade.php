@props(['encoder', 'check' => null])

<div {{ $attributes }} class="space-y-2 py-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="break-all font-mono text-sm">{{ $encoder->name }}</p>
        <div class="flex flex-wrap items-center gap-2">
            @if ($encoder->isHardware())
                <x-badge :text="strtoupper($encoder->backend->value)" color="gray" light />
                @if ($check)
                    <x-badge :text="$check->successful ? 'Verified' : 'Test failed'" :color="$check->successful ? 'green' : 'red'" light />
                @endif
                @if ($encoder->isTestable())
                    <x-button text="Test encoder" sm flat
                        :aria-label="'Test encoder '.$encoder->name"
                        :wire:click="'testEncoder('.Js::encode($encoder->name).')'"
                        :loading="'testEncoder('.Js::encode($encoder->name).')'"
                        wire:target="testEncoder,refreshDetection,saveHardwareAcceleration" />
                @endif
            @endif
        </div>
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $encoder->description }}</p>
    @if ($check)
        <p role="status" class="break-words whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $check->message }}</p>
    @endif
</div>
