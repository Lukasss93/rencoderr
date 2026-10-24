<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Config::boolean('auth.enabled')) {
            return redirect()->route('home');
        }

        return $next($request);
    }
}
