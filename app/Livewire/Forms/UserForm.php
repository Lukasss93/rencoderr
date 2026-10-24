<?php

namespace App\Livewire\Forms;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Form;

class UserForm extends Form
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function fillFrom(User $user): void
    {
        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function validateFor(User $user): void
    {
        $this->name = trim($this->name);
        $this->email = Str::lower(trim($this->email));
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => [$user->exists ? 'nullable' : 'required', 'string', 'confirmed:passwordConfirmation', Password::defaults()],
        ]);
    }

    public function newPassword(): ?string
    {
        return $this->password === '' ? null : $this->password;
    }
}
