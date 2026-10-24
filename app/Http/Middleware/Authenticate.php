<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as LaravelAuthenticate;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class Authenticate implements AuthenticatesRequests
{
    public function __construct(
        private readonly LaravelAuthenticate $authenticate,
        private readonly AuthenticateSession $authenticateSession,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Config::boolean('auth.enabled')) {
            return $next($request);
        }

        return $this->authenticate->handle(
            $request,
            fn (Request $request): Response => $this->authenticateSession->handle($request, $next),
        );
    }
}
