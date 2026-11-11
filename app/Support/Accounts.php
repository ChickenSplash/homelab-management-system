<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** The sign-in site (config/accounts.php) and where it may send people afterwards. */
class Accounts
{
    /** The sign-in site's host, with its port when it isn't the scheme's default. */
    public static function host(): string
    {
        return self::authority(config('accounts.url'));
    }

    public static function isAccountsHost(Request $request): bool
    {
        return $request->getHttpHost() === self::host();
    }

    /** @return list<array{name: string, description: string, url: string}> */
    public static function apps(): array
    {
        return config('accounts.apps');
    }

    /** @return list<string> e.g. https://chat.example.org */
    public static function appOrigins(): array
    {
        return array_values(array_unique(array_map(
            fn (array $app) => self::origin($app['url']),
            self::apps(),
        )));
    }

    /**
     * The URL to go back to, or null unless it's an absolute URL on one of the apps' exact
     * origins. Checked as a string prefix (origin then "/"), so userinfo, lookalike hosts,
     * other ports and schemes all fail without relying on how a parser reads them.
     */
    public static function safeReturn(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }

        foreach (self::appOrigins() as $origin) {
            if ($url === $origin || Str::startsWith($url, $origin.'/')) {
                return $url;
            }
        }

        return null;
    }

    /** Ends the shared session, so every app is signed out on its next request. */
    public static function signOut(Request $request): void
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Nothing could ever sign back in as this guest.
        if ($user?->is_guest) {
            $user->delete();
        }
    }

    private static function origin(string $url): string
    {
        return parse_url($url, PHP_URL_SCHEME).'://'.self::authority($url);
    }

    private static function authority(string $url): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? null;
        $default = ($parts['scheme'] ?? 'http') === 'https' ? 443 : 80;

        return strtolower($parts['host'] ?? '').($port && $port !== $default ? ':'.$port : '');
    }
}
