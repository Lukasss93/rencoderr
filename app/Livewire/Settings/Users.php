<?php

namespace App\Livewire\Settings;

use App\Livewire\Forms\UserForm;
use App\Models\User;
use App\Users\Actions\SaveUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

#[Title('User settings')]
class Users extends Component
{
    use Interactions;

    public bool $showEditor = false;

    #[Locked]
    public ?int $editingId = null;

    public UserForm $form;

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
        $this->form->fillFrom($user);
    }

    public function cancel(): void
    {
        $this->resetEditor();
    }

    public function save(SaveUser $saveUser): void
    {
        $user = $this->editingId === null ? new User : User::query()->findOrFail($this->editingId);
        $isNew = ! $user->exists;
        $this->form->validateFor($user);
        $password = $this->form->newPassword();
        $saveUser->handle($user, $this->form->name, $this->form->email, $password);
        $this->resetEditor();
        $this->toast()->success($isNew ? 'User created.' : 'User saved.')->send();

        if ($password !== null && $user->id === Auth::id()) {
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
        $this->toast()->success('User deleted.')->send();
    }

    public function render(): View
    {
        return view('livewire.settings.users', [
            'users' => User::query()->orderBy('name')->orderBy('id')->get(),
        ])->layoutData(['settingsPage' => true]);
    }

    private function resetEditor(): void
    {
        $this->reset('showEditor', 'editingId');
        $this->form->reset();
        $this->resetValidation();
    }
}
