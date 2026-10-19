<?php

use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie/session auth on /api, limited by the "api" rate limiter.
        $middleware->statefulApi();
        $middleware->throttleApi();

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'tenant' => EnsureTenantAccess::class,
        ]);

        // The SPA owns the login page; there is no named "login" route on the server.
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every request under /api is answered with JSON, even without an Accept header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
