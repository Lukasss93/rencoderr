<div class="space-y-6">
    <x-page-header title="Users" description="Manage the accounts that can sign in. Every user has full access to the application.">
        <x-button text="Add user" icon="plus" wire:click="create" />
    </x-page-header>

    @error('delete')
        <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror

    <x-card>
        <div class="divide-y divide-gray-200 dark:divide-dark-700">
            @foreach ($users as $user)
                <div class="flex flex-wrap items-center justify-between gap-4 py-4 first:pt-0 last:pb-0" wire:key="user-{{ $user->id }}">
                    <div class="min-w-0 space-y-1">
                        <h2 class="break-words text-lg font-semibold">
                            {{ $user->name }}
                            @if ($user->id === auth()->id())
                                <span class="text-sm font-normal text-gray-500 dark:text-gray-400">(you)</span>
                            @endif
                        </h2>
                        <p class="break-all text-sm text-gray-500 dark:text-gray-400">{{ $user->email }}</p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <x-button text="Edit" sm flat wire:click="edit({{ $user->id }})" />
                        @if ($user->id !== auth()->id())
                            <x-button text="Delete" sm flat color="red" wire:click="delete({{ $user->id }})" loading="delete({{ $user->id }})"
                                wire:confirm="Delete this user? They will no longer be able to sign in. This cannot be undone." />
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-card>

    <x-modal wire="showEditor" :title="$editingId ? 'Edit user' : 'Add user'" center>
        <form wire:submit="save" class="space-y-6">
            <x-input label="Name" wire:model="form.name" autocomplete="name" required />
            <x-input label="Email" type="email" wire:model="form.email" autocomplete="off" required />
            <x-input :label="$editingId ? 'New password' : 'Password'" type="password" wire:model="form.password" autocomplete="new-password"
                :hint="$editingId ? 'Leave blank to keep the current password.' : 'Use at least 8 characters.'" />
            <x-input label="Confirm password" type="password" wire:model="form.passwordConfirmation" autocomplete="new-password" />
            @if ($editingId)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $editingId === auth()->id() ? 'Changing your password signs you out. Sign in again with your new password.' : 'Changing the password signs this user out of their existing sessions.' }}
                </p>
            @endif
            <div class="flex justify-end gap-3">
                <x-button text="Cancel" flat wire:click="cancel" />
                <x-button text="Save user" submit loading="save" />
            </div>
        </form>
    </x-modal>
</div>
