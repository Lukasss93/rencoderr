<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

function navigationDocument(string $html): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

    return new DOMXPath($document);
}

it('provides dashboard queue and settings navigation with icons', function (): void {
    $response = actingAs(User::factory()->create())->get(route('home'))->assertOk();
    $navigation = navigationDocument((string) $response->getContent());

    expect($navigation->evaluate('count(//nav[@aria-label="Main navigation"]/a)'))->toBe(3.0)
        ->and($navigation->evaluate('count(//nav[@aria-label="Main navigation"]/a/svg)'))->toBe(3.0)
        ->and($navigation->evaluate('normalize-space(//nav[@aria-label="Main navigation"]/a[1])'))->toBe('Dashboard')
        ->and($navigation->evaluate('string(//nav[@aria-label="Main navigation"]/a[1]/@href)'))->toBe(route('home'))
        ->and($navigation->evaluate('normalize-space(//nav[@aria-label="Main navigation"]/a[2])'))->toBe('Queue')
        ->and($navigation->evaluate('string(//nav[@aria-label="Main navigation"]/a[2]/@href)'))->toBe(route('queue.index'))
        ->and($navigation->evaluate('normalize-space(//nav[@aria-label="Main navigation"]/a[3])'))->toBe('Settings')
        ->and($navigation->evaluate('string(//nav[@aria-label="Main navigation"]/a[3]/@href)'))->toBe(route('settings.index'));
});

it('marks the current navigation section', function (string $page, string $link): void {
    $response = actingAs(User::factory()->create())->get(route($page))->assertOk();
    $navigation = navigationDocument((string) $response->getContent());

    expect($navigation->evaluate('count(//nav[@aria-label="Main navigation"]/a[@aria-current="page"])'))->toBe(1.0)
        ->and($navigation->evaluate('string(//nav[@aria-label="Main navigation"]/a[@aria-current="page"]/@href)'))->toBe(route($link));
})->with([
    'dashboard' => ['home', 'home'],
    'queue' => ['queue.index', 'queue.index'],
    'library settings' => ['settings.libraries', 'settings.index'],
]);

it('places library settings inside the settings sidebar', function (): void {
    $response = actingAs(User::factory()->create())->get(route('settings.libraries'))->assertOk()->assertSee('Add your first library');
    $navigation = navigationDocument((string) $response->getContent());

    expect($navigation->evaluate('count(//aside//nav[@aria-label="Settings navigation"]/a)'))->toBe(2.0)
        ->and($navigation->evaluate('normalize-space(//nav[@aria-label="Settings navigation"]/a[2])'))->toBe('Libraries')
        ->and($navigation->evaluate('string(//nav[@aria-label="Settings navigation"]/a[2]/@href)'))->toBe(route('settings.libraries'))
        ->and($navigation->evaluate('string(//nav[@aria-label="Settings navigation"]/a[2]/@aria-current)'))->toBe('page');
});

it('keeps the settings sidebar within the settings section', function (string $page): void {
    $response = actingAs(User::factory()->create())->get(route($page))->assertOk();
    $navigation = navigationDocument((string) $response->getContent());

    expect($navigation->evaluate('count(//nav[@aria-label="Settings navigation"])'))->toBe(0.0);
})->with(['home', 'queue.index']);

it('includes account identity theme controls and a csrf protected logout in the user menu', function (): void {
    $user = User::factory()->create();
    $response = actingAs($user)->get(route('home'))->assertOk()->assertSee($user->name)->assertSee($user->email);
    $navigation = navigationDocument((string) $response->getContent());
    $panel = $navigation->evaluate('string(//button[@aria-label="User menu"]/@aria-controls)');

    expect($panel)->toBe('user-menu-panel')
        ->and($navigation->evaluate('count(//*[@id="user-menu-panel"]//*[@role="group" and @aria-label="Color theme"]//button)'))->toBe(3.0)
        ->and($navigation->evaluate('string(//*[@id="user-menu-panel"]//form/@method)'))->toBe('POST')
        ->and($navigation->evaluate('string(//*[@id="user-menu-panel"]//form/@action)'))->toBe(route('logout'))
        ->and($navigation->evaluate('count(//*[@id="user-menu-panel"]//form/input[@name="_token"])'))->toBe(1.0)
        ->and($navigation->evaluate('normalize-space(//*[@id="user-menu-panel"]//button[@type="submit"])'))->toBe('Log out');
});

it('escapes account identity in the user menu', function (): void {
    $user = User::factory()->create(['name' => '<script>name()</script>', 'email' => 'person+<script>@example.com']);

    actingAs($user)->get(route('home'))->assertOk()
        ->assertSee($user->name)->assertDontSee($user->name, false)
        ->assertSee($user->email)->assertDontSee($user->email, false);
});

it('keeps appearance available without exposing account controls when authentication is disabled', function (): void {
    $user = User::factory()->create();
    actingAs($user);
    Config::set('auth.enabled', false);

    $response = get(route('home'))->assertOk()->assertSee('Local access')->assertDontSee($user->name)->assertDontSee($user->email)->assertDontSee('Log out');
    $navigation = navigationDocument((string) $response->getContent());

    expect($navigation->evaluate('count(//*[@id="user-menu-panel"]//*[@aria-label="Color theme"]//button)'))->toBe(3.0)
        ->and($navigation->evaluate('count(//*[@id="user-menu-panel"]//form)'))->toBe(0.0);
});

it('keeps application navigation and account menus off the login page', function (): void {
    $response = get(route('login'))->assertOk();
    $navigation = navigationDocument((string) $response->getContent());

    expect($navigation->evaluate('count(//header)'))->toBe(0.0)
        ->and($navigation->evaluate('count(//button[@aria-label="User menu"])'))->toBe(0.0);
});

it('opens settings on status and redirects former URLs', function (string $page, string $destination): void {
    actingAs(User::factory()->create())->get(route($page))->assertRedirectToRoute($destination);
})->with([
    ['settings.index', 'settings.status'],
    ['settings.system-health', 'settings.status'],
    ['libraries.index', 'settings.libraries'],
]);

it('protects queue and settings pages behind the existing authentication', function (string $page): void {
    get(route($page))->assertRedirectToRoute('login');
})->with(['queue.index', 'settings.index', 'settings.libraries', 'settings.status']);
