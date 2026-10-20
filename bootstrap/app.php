<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureAuthenticationEnabled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'auth' => Authenticate::class,
            'auth.enabled' => EnsureAuthenticationEnabled::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request): string => route('login'));
        $middleware->redirectUsersTo(fn (Request $request): string => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
