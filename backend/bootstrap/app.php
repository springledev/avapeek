<?php

use App\Http\Middleware\EnsureChildAdmin;
use App\Http\Middleware\EnsureChildMember;
use App\Http\Middleware\EnsureChildOwner;
use App\Http\Middleware\TrustCaddyProxy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustCaddyProxy::class);

        $middleware->alias([
            'child.member' => EnsureChildMember::class,
            'child.admin' => EnsureChildAdmin::class,
            'child.owner' => EnsureChildOwner::class,
        ]);

        // The API has no login page to redirect guests to (the SPA has its own),
        // so unauthenticated requests always get a JSON 401 instead of trying
        // route('login') and failing with a 500.
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'auth_hash',
            'current_auth_hash',
            'new_auth_hash',
            'password_hash',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
