@props(['field', 'libraryMode' => false, 'inherited' => false, 'globalValue' => ''])

{{-- TallStackUI captures disabled state when its Alpine controls initialize. --}}
<div class="space-y-2" wire:key="media-setting-{{ $field }}-{{ $inherited ? 'inherited' : 'overridden' }}">
    {{ $slot }}
    @if ($libraryMode)
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
            <x-checkbox :id="'override-'.$field" label="Override global setting"
                :checked="! $inherited" :wire:change="'setOverride(\''. $field .'\', $event.target.checked)'" />
            <span class="text-gray-500 dark:text-dark-400">Global: {{ $globalValue }}</span>
        </div>
    @endif
</div>
