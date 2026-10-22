<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

#[Signature('user:reset-password {email : The email address of the account}')]
#[Description('Reset an account password and revoke its sessions and remembered logins')]
class ResetUserPassword extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command requires interactive input.');

            return self::FAILURE;
        }

        $email = $this->argument('email');

        if (! is_string($email)) {
            $this->error('An email address is required.');

            return self::FAILURE;
        }

        $email = Str::lower(trim($email));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error('No user exists with that email address.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password', false);
        $passwordConfirmation = (string) $this->secret('Confirm password', false);

        $validator = Validator::make([
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        event(new PasswordReset($user));

        $this->info('Password reset successfully.');

        return self::SUCCESS;
    }
}
