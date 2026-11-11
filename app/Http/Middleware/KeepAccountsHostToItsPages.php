<?php

namespace App\Http\Middleware;

use App\Support\Accounts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tunnel sends the sign-in site straight to HMS, so without this every API route would
 * answer there too. The API is only meant to be reached through the apps' own proxies.
 */
class KeepAccountsHostToItsPages
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Accounts::isAccountsHost($request) && ! $request->routeIs('accounts.*'), 404);

        return $next($request);
    }
}
