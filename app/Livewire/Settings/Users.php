<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('User settings')]
class Users extends Component
{
    public bool $showEditor = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public string $notice = '';

    public function boot(): void
    {
        abort_unless(Config::boolean('auth.enabled') && Auth::check(), 403);
    }

    public function create(): void
    {
        $this->resetEditor();
        $this->showEditor = true;
    }

    public function edit(int $id): void
    {
        $user = User::query()->findOrFail($id);
        $this->create();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function cancel(): void
    {
        $this->resetEditor();
    }

    public function save(): void
    {
        $user = $this->editingId === null ? new User : User::query()->findOrFail($this->editingId);
        $this->name = trim($this->name);
        $this->email = Str::lower(trim($this->email));
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => [$user->exists ? 'nullable' : 'required', 'string', 'confirmed:passwordConfirmation', Password::defaults()],
        ]);

        $isNewUser = ! $user->exists;
        $passwordChanged = $this->password !== '';
        $user->name = $this->name;
        $user->email = $this->email;

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($passwordChanged) {
            $user->password = $this->password;
            $user->remember_token = Str::random(60);
        }

        $user->save();

        if (! $isNewUser && $passwordChanged) {
            event(new PasswordReset($user));
        }

        $this->resetEditor();
        $this->notice = $isNewUser ? 'User created.' : 'User saved.';

        if ($passwordChanged && $user->id === Auth::id()) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
            $this->redirectRoute('login');
        }
    }

    public function delete(int $id): void
    {
        if ($id === Auth::id()) {
            throw ValidationException::withMessages(['delete' => 'You cannot delete your own account.']);
        }

        User::query()->findOrFail($id)->delete();

        if ($this->editingId === $id) {
            $this->resetEditor();
        }

        $this->resetValidation('delete');
        $this->notice = 'User deleted.';
    }

    public function render(): View
    {
        return view('livewire.settings.users', [
            'users' => User::query()->orderBy('name')->orderBy('id')->get(),
        ])->layoutData(['settingsPage' => true]);
    }

    private function resetEditor(): void
    {
        $this->reset('showEditor', 'editingId', 'name', 'email', 'password', 'passwordConfirmation');
        $this->resetValidation();
    }
}
