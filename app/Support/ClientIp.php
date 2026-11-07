<?php

namespace App\Support;

use Illuminate\Http\Request;

class ClientIp
{
    /**
     * Every proxy is trusted, so X-Forwarded-For can be extended by the client and
     * $request->ip() is only as honest as the leftmost entry. Cloudflare overwrites
     * CF-Connecting-IP on every request, and HMS publishes no port, so only the
     * tunnel and the edge containers can reach it to set that header.
     */
    public static function of(Request $request): string
    {
        return (string) ($request->header('CF-Connecting-IP') ?: $request->ip());
    }
}
