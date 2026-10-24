@props(['title', 'description'])

<div class="flex flex-wrap items-center justify-between gap-4">
    <div class="space-y-2">
        <h1 class="text-3xl font-semibold tracking-tight">{{ $title }}</h1>
        <p class="text-gray-600 dark:text-gray-300">{{ $description }}</p>
    </div>
    {{ $slot }}
</div>
