<aside class="self-start rounded-xl border border-gray-200 bg-white p-3 dark:border-dark-700 dark:bg-dark-900 lg:sticky lg:top-24">
    <p class="px-3 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-dark-400">Settings</p>
    <nav aria-label="Settings navigation" class="space-y-1">
        <x-navigation-link :href="route('settings.status')" icon="server"
            :active="request()->routeIs('settings.status')" class="w-full justify-start">
            Status
        </x-navigation-link>
        <x-navigation-link :href="route('settings.libraries')" icon="folder"
            :active="request()->routeIs('settings.libraries', 'settings.libraries.*')" class="w-full justify-start">
            Libraries
        </x-navigation-link>
        <x-navigation-link :href="route('settings.media')" icon="film"
            :active="request()->routeIs('settings.media')" class="w-full justify-start">
            Media
        </x-navigation-link>
        @if (config('auth.enabled'))
            <x-navigation-link :href="route('settings.users')" icon="users"
                :active="request()->routeIs('settings.users')" class="w-full justify-start">
                Users
            </x-navigation-link>
        @endif
        <x-navigation-link :href="route('settings.workers')" icon="cpu-chip"
            :active="request()->routeIs('settings.workers')" class="w-full justify-start">
            Workers
        </x-navigation-link>
    </nav>
</aside>
