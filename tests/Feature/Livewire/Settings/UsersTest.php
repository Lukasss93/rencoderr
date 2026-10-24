<?php

use App\Livewire\Settings\Users;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\assertModelExists;
use function Pest\Laravel\assertModelMissing;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

it('requires authentication to manage users', function (): void {
    get('/settings/users')->assertRedirectToRoute('login');
});

it('lists accounts for any signed-in user and escapes their names', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create(['name' => '<script>alert("name")</script>']);

    actingAs($user)->get('/settings/users')->assertOk()
        ->assertSee($other->name)->assertDontSee($other->name, false)
        ->assertSee($other->email)->assertSee('Add user');
    get(route('settings.libraries'))->assertSee(route('settings.users'));
});

it('hides user management and redirects its page when authentication is disabled', function (): void {
    Config::set('auth.enabled', false);

    get('/settings/users')->assertRedirectToRoute('home');
    get(route('settings.libraries'))->assertOk()->assertDontSee(route('settings.users'));
});

it('rejects stale user actions when authentication is disabled', function (string $method): void {
    $user = User::factory()->create();
    $target = User::factory()->create();
    Auth::login($user);
    $page = get('/settings/users')->assertOk();
    Config::set('auth.enabled', false);

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($page, [
        'form.name' => 'New user', 'form.email' => 'new@example.com',
        'form.password' => 'new-password', 'form.passwordConfirmation' => 'new-password',
    ], [['method' => $method, 'params' => $method === 'save' ? [] : [$target->id]]]), ['X-Livewire' => 'true'])
        ->assertRedirectToRoute('home');

    assertDatabaseCount('users', 2);
    assertModelExists($target);
})->with(['save', 'edit', 'delete']);

it('rejects a user mutation after the session expires', function (): void {
    $target = User::factory()->create();
    Auth::login(User::factory()->create());
    $page = get('/settings/users')->assertOk();
    Auth::logout();
    session()->invalidate();
    Auth::forgetGuards();

    postJson(Livewire::getUpdateUri(), livewireUpdatePayload($page, [], [
        ['method' => 'delete', 'params' => [$target->id]],
    ]), ['X-Livewire' => 'true'])->assertUnauthorized();

    assertModelExists($target);
});

it('creates an account with normalized details and a hashed password', function (): void {
    actingAs(User::factory()->create());

    Livewire::test(Users::class)->call('create')
        ->set('form.name', '  New user  ')->set('form.email', '  NEW@EXAMPLE.COM  ')
        ->set('form.password', 'new-password')->set('form.passwordConfirmation', 'new-password')
        ->call('save')->assertHasNoErrors()->assertSet('showEditor', false)->assertDispatched('ts-ui:toast', title: 'User created.')
        ->assertSet('form.password', '')->assertSet('form.passwordConfirmation', '');

    assertDatabaseHas('users', ['name' => 'New user', 'email' => 'new@example.com']);
    expect(Hash::check('new-password', User::query()->where('email', 'new@example.com')->firstOrFail()->password))->toBeTrue();
});

it('rejects invalid account details without saving', function (string $field, string $value, string $error): void {
    actingAs(User::factory()->create(['email' => 'existing@example.com']));

    Livewire::test(Users::class)->call('create')
        ->set('form.name', 'New user')->set('form.email', 'new@example.com')
        ->set('form.password', 'new-password')->set('form.passwordConfirmation', $field === 'password' ? $value : 'new-password')
        ->set('form.'.$field, $value)->call('save')->assertHasErrors(['form.'.($field === 'passwordConfirmation' ? 'password' : $field)])
        ->assertSee($error);

    assertDatabaseCount('users', 1);
})->with([
    'blank name' => ['name', '   ', 'The name field is required.'],
    'long name' => ['name', str_repeat('a', 256), 'The name field must not be greater than 255 characters.'],
    'blank email' => ['email', '', 'The email field is required.'],
    'invalid email' => ['email', 'invalid', 'The email field must be a valid email address.'],
    'duplicate email' => ['email', ' EXISTING@EXAMPLE.COM ', 'The email has already been taken.'],
    'missing password' => ['password', '', 'The password field is required.'],
    'short password' => ['password', 'short', 'The password field must be at least 8 characters.'],
    'mismatched passwords' => ['passwordConfirmation', 'different-password', 'The password field confirmation does not match.'],
]);

it('edits an account without changing a blank password or its remembered login', function (): void {
    actingAs(User::factory()->create());
    $target = User::factory()->create();
    $password = $target->password;
    $token = $target->remember_token;
    Event::fake([PasswordReset::class]);

    Livewire::test(Users::class)->call('edit', $target->id)
        ->assertSet('form.name', $target->name)->assertSet('form.email', $target->email)->assertSet('form.password', '')
        ->set('form.name', 'Updated user')->call('save')->assertHasNoErrors();

    expect($target->refresh()->name)->toBe('Updated user');
    expect($target->password)->toBe($password);
    expect($target->remember_token)->toBe($token);
    Event::assertNotDispatched(PasswordReset::class);
});

it('normalizes changed email addresses and clears their verification', function (): void {
    actingAs(User::factory()->create());
    $target = User::factory()->create();

    Livewire::test(Users::class)->call('edit', $target->id)->set('form.email', ' CHANGED@EXAMPLE.COM ')
        ->call('save')->assertHasNoErrors();

    expect($target->refresh()->email)->toBe('changed@example.com');
    expect($target->email_verified_at)->toBeNull();
});

it('rejects another accounts email when editing', function (): void {
    $manager = User::factory()->create();
    actingAs($manager);
    $target = User::factory()->create();
    $email = $target->email;

    Livewire::test(Users::class)->call('edit', $target->id)->set('form.email', $manager->email)
        ->call('save')->assertHasErrors(['form.email']);

    expect($target->refresh()->email)->toBe($email);
});

it('changes a password and revokes the accounts existing session', function (): void {
    $target = User::factory()->create();
    $token = $target->remember_token;
    Auth::login($target);
    get(route('home'))->assertOk();
    actingAs(User::factory()->create());
    Event::fake([PasswordReset::class]);

    Livewire::test(Users::class)->call('edit', $target->id)
        ->set('form.password', 'replacement-password')->set('form.passwordConfirmation', 'replacement-password')
        ->call('save')->assertHasNoErrors()->assertSet('form.password', '');

    expect(Hash::check('replacement-password', $target->refresh()->password))->toBeTrue();
    expect($target->remember_token)->not->toBe($token);
    Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event): bool => $event->user->is($target));
    Auth::forgetGuards();
    get(route('home'))->assertRedirectToRoute('login');
    assertGuest();
});

it('requires signing in again after changing your own password', function (): void {
    $user = User::factory()->create();
    Auth::login($user);

    Livewire::test(Users::class)->call('edit', $user->id)
        ->set('form.password', 'replacement-password')->set('form.passwordConfirmation', 'replacement-password')
        ->call('save')->assertHasNoErrors()->assertRedirectToRoute('login');

    expect(Hash::check('replacement-password', $user->refresh()->password))->toBeTrue();
    assertGuest();
});

it('deletes another account and prevents its existing session from accessing the app', function (): void {
    $target = User::factory()->create();
    Auth::login($target);
    get(route('home'))->assertOk();
    actingAs(User::factory()->create());

    Livewire::test(Users::class)->call('delete', $target->id)->assertHasNoErrors();

    assertModelMissing($target);
    Auth::forgetGuards();
    get(route('home'))->assertRedirectToRoute('login');
});

it('prevents deleting your own account even through a direct action', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    Livewire::test(Users::class)->call('delete', $user->id)
        ->assertHasErrors(['delete'])->assertSee('You cannot delete your own account.');

    assertModelExists($user);
});

it('clears unsaved credentials when starting another account', function (): void {
    actingAs(User::factory()->create());
    $target = User::factory()->create();

    Livewire::test(Users::class)->call('edit', $target->id)
        ->set('form.password', 'unsaved-password')->set('form.passwordConfirmation', 'unsaved-password')
        ->call('create')->assertSet('editingId', null)->assertSet('form.name', '')->assertSet('form.email', '')
        ->assertSet('form.password', '')->assertSet('form.passwordConfirmation', '');
});
