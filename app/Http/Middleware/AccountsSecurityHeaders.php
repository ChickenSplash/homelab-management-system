<?php

namespace App\Http\Middleware;

use App\Support\Accounts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sign-in pages are plain HTML and one stylesheet: no script at all, never framed. Forms
 * may only post here, and (since browsers check the redirect after a post too) on to the apps.
 */
class AccountsSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $formAction = implode(' ', ["'self'", ...Accounts::appOrigins()]);

        $response->headers->add([
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; img-src 'self'; form-action {$formAction}; frame-ancestors 'none'; base-uri 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Cache-Control' => 'no-store, private',
        ]);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
