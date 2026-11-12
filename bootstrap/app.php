<?php

use App\Http\Middleware\AccountsSecurityHeaders;
use App\Http\Middleware\KeepAccountsHostToItsPages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The sign-in site. First, so its / wins on that host.
            Route::domain(parse_url(config('accounts.url'), PHP_URL_HOST))
                ->middleware(['web', AccountsSecurityHeaders::class])
                ->group(base_path('routes/accounts.php'));

            // Outside any middleware group, so a bot hitting the root never opens a session.
            Route::get('/', fn () => ['name' => config('app.name'), 'ok' => true]);

            Route::middleware(['web', 'auth:web'])->group(base_path('routes/media.php'));
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

        // Leading indentation and trailing spaces are meaningful in Markdown entries.
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/daily/journals/*/entries/*')]);

        // The apps send signed-out visitors to the sign-in site themselves; the API just answers 401.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->prependToGroup('web', KeepAccountsHostToItsPages::class);
        $middleware->prependToGroup('api', KeepAccountsHostToItsPages::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => ! $request->routeIs('accounts.*'));

        // An expired form or too many attempts on the sign-in site: back to the form with a note,
        // rather than a bare error page (which its CSP would leave unstyled anyway).
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $message = match ($e->getStatusCode()) {
                419 => 'That page had expired. Try again.',
                429 => 'Too many attempts. Wait a minute and try again.',
                default => null,
            };

            if ($message && $request->routeIs('accounts.*') && $request->hasSession()) {
                return back()->withInput()->withErrors(['form' => $message]);
            }
        });
    })->create();
