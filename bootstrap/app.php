<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Outside any middleware group, so a bot hitting the root never opens a session.
        then: function () {
            Route::get('/', fn () => ['name' => config('app.name'), 'ok' => true]);
        },
    )
    // Under /api so the front ends only have to proxy /api, /sanctum and the WebSocket.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloudflare terminates TLS and cloudflared forwards plain HTTP to the container,
        // so the real scheme, host and client IP only exist in the forwarded headers.
        $middleware->trustProxies(at: '*');

        // Browser apps on SANCTUM_STATEFUL_DOMAINS get session cookies and CSRF on /api.
        // Everything else (the contact form, scripts with bearer tokens) stays stateless.
        $middleware->statefulApi();

        // API only: there is no login page, so a signed-out request just gets a 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
    })->create();
