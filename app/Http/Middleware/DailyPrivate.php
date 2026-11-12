<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DailyPrivate
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
