@props(['href', 'icon', 'active' => false, 'navigate' => true])

<a href="{{ $href }}" @if ($navigate) wire:navigate @endif
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'inline-flex items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 sm:gap-2 sm:px-3 sm:text-sm',
        'bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300' => $active,
        'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-dark-300 dark:hover:bg-dark-800 dark:hover:text-white' => ! $active,
    ]) }}>
    <x-icon :icon="$icon" class="size-4 shrink-0 sm:size-5" aria-hidden="true" />
    <span>{{ $slot }}</span>
</a>
