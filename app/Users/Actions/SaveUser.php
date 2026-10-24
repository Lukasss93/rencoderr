<?php

namespace App\Users\Actions;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;

class SaveUser
{
    public function handle(User $user, string $name, string $email, ?string $password = null): User
    {
        $isExisting = $user->exists;
        $user->name = $name;
        $user->email = $email;

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($password !== null) {
            $user->password = $password;
            $user->remember_token = Str::random(60);
        }

        $user->save();

        if ($isExisting && $password !== null) {
            event(new PasswordReset($user));
        }

        return $user;
    }

    public function resetPassword(User $user, string $password): User
    {
        return $this->handle($user, $user->name, $user->email, $password);
    }
}
