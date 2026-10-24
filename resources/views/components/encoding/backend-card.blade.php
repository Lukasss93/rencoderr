@props(['backend'])

<section {{ $attributes }} class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-dark-700" aria-label="{{ $backend->label }}">
    <h3 class="font-semibold">{{ $backend->label }}</h3>
    <x-badge :text="$backend->available ? 'Available' : 'Unavailable'" :color="$backend->available ? 'green' : 'gray'" light />
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $backend->encoders }} installed {{ $backend->encoders === 1 ? 'encoder' : 'encoders' }}</p>
    <details class="text-sm text-gray-600 dark:text-gray-300">
        <summary class="cursor-pointer">Detection details</summary>
        <p class="mt-2 break-words whitespace-pre-line">{{ $backend->message }}</p>
    </details>
</section>
