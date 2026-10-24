<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Users\Actions\SaveUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

#[Signature('user:create')]
#[Description('Create an account with interactive name, email, and password prompts')]
class CreateUser extends Command
{
    public function handle(SaveUser $saveUser): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command requires interactive input.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        $email = Str::lower(trim((string) $this->ask('Email')));
        $password = (string) $this->secret('Password', false);
        $passwordConfirmation = (string) $this->secret('Confirm password', false);

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $saveUser->handle(new User, $name, $email, $password);

        $this->info('User created successfully.');

        return self::SUCCESS;
    }
}
