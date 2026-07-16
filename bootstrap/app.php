<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ties sessions to the password hash so "log out other browser
        // sessions" (and password changes) invalidate stale sessions
        // (FR-SEC-05).
        $middleware->web(append: [
            AuthenticateSession::class,
        ]);

        // Two front doors: staff sign in at /login, tenants at /portal/login.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('portal*') ? route('portal.login') : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
