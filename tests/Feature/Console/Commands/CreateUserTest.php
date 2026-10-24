<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertModelExists;
use function Pest\Laravel\seed;

it('creates an account with normalized email and a hashed password', function (string $password): void {
    artisanCommand('user:create')
        ->expectsQuestion('Name', '  Test User  ')
        ->expectsQuestion('Email', '  TEST@EXAMPLE.COM  ')
        ->expectsQuestion('Password', $password)
        ->expectsQuestion('Confirm password', $password)
        ->expectsOutput('User created successfully.')
        ->doesntExpectOutput($password)
        ->assertSuccessful();

    assertDatabaseHas('users', ['name' => 'Test User', 'email' => 'test@example.com']);
    expect(Hash::check($password, User::query()->sole()->password))->toBeTrue();
})->with([
    'password with surrounding whitespace' => '  correct-password  ',
    'plain password' => 'correct-password',
]);

it('rejects invalid account details without creating an account', function (string $name, string $email, string $password, string $confirmation, string $error): void {
    artisanCommand('user:create')
        ->expectsQuestion('Name', $name)
        ->expectsQuestion('Email', $email)
        ->expectsQuestion('Password', $password)
        ->expectsQuestion('Confirm password', $confirmation)
        ->expectsOutput($error)
        ->assertFailed();

    assertDatabaseCount('users', 0);
})->with([
    'missing name' => ['', 'test@example.com', 'correct-password', 'correct-password', 'The name field is required.'],
    'long name' => [str_repeat('a', 256), 'test@example.com', 'correct-password', 'correct-password', 'The name field must not be greater than 255 characters.'],
    'missing email' => ['Test User', '', 'password', 'password', 'The email field is required.'],
    'invalid email' => ['Test User', 'invalid-email', 'password', 'password', 'The email field must be a valid email address.'],
    'long email' => ['Test User', str_repeat('a', 245).'@example.com', 'password', 'password', 'The email field must not be greater than 255 characters.'],
    'missing password' => ['Test User', 'test@example.com', '', '', 'The password field is required.'],
    'short password' => ['Test User', 'test@example.com', 'short', 'short', 'The password field must be at least 8 characters.'],
    'mismatched passwords' => ['Test User', 'test@example.com', 'correct-password', 'different-password', 'The password field confirmation does not match.'],
]);

it('rejects a duplicate email after normalization', function (): void {
    $user = User::factory()->create(['email' => 'test@example.com']);

    artisanCommand('user:create')
        ->expectsQuestion('Name', 'Another User')
        ->expectsQuestion('Email', '  TEST@EXAMPLE.COM  ')
        ->expectsQuestion('Password', 'password')
        ->expectsQuestion('Confirm password', 'password')
        ->expectsOutput('The email has already been taken.')
        ->assertFailed();

    assertDatabaseCount('users', 1);
    assertModelExists($user);
});

it('fails without changing data when interactive input is unavailable', function (): void {
    artisanCommand('user:create', ['--no-interaction' => true])
        ->expectsOutput('This command requires interactive input.')
        ->assertFailed();

    assertDatabaseCount('users', 0);
});

it('does not seed a default login account', function (): void {
    seed();

    assertDatabaseCount('users', 0);
});
