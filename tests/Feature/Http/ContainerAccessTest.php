<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withServerVariables;

it('protects the Horizon dashboard with application authentication', function (): void {
    get('/horizon')->assertRedirect(route('login'));
});

it('allows signed in users to open Horizon', function (): void {
    actingAs(User::factory()->create());

    get('/horizon')->assertOk();
});

it('honors explicitly disabled authentication on Horizon', function (): void {
    Config::set('auth.enabled', false);

    get('/horizon')->assertOk();
});

it('trusts forwarded HTTPS only from the configured proxy', function (string $address, string $expected): void {
    Config::set('trustedproxy.proxies', ['10.20.0.1']);

    withServerVariables(['REMOTE_ADDR' => $address])
        ->get('http://example.test/', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
        ->assertRedirect($expected);
})->with([
    ['10.20.0.1', 'https://example.test/login'],
    ['10.20.0.2', 'http://example.test/login'],
]);
