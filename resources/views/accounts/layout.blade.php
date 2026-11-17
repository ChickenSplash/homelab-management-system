<!doctype html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Homelab</title>
    <link rel="stylesheet" href="/accounts.css?v={{ @filemtime(public_path('accounts.css')) }}">
    <script src="/accounts.js?v={{ @filemtime(public_path('accounts.js')) }}" defer></script>
    <script src="/contact.js?v={{ @filemtime(public_path('contact.js')) }}" defer></script>
</head>
<body>
    <header class="site-header">
        <nav class="site-nav" aria-label="Main navigation">
            <a href="/" data-navigate data-section="about" aria-label="About" title="About" @if (request()->routeIs('accounts.home', 'accounts.about')) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/></svg>
                <span>About</span>
            </a>
            <a href="/portfolio" data-navigate data-section="portfolio" aria-label="Portfolio" title="Portfolio" @if (request()->routeIs('accounts.portfolio')) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="15" rx="2"/><path d="M8 6V3h8v3M3 12h18m-11 0v3h4v-3"/></svg>
                <span>Portfolio</span>
            </a>
            <a href="/profile" data-navigate data-section="profile" aria-label="Profile and login" title="Profile and login" @if (request()->routeIs('accounts.profile', 'accounts.login', 'accounts.register', 'accounts.guest')) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>
                <span>{{ auth('web')->check() ? 'Profile' : 'Login' }}</span>
            </a>
        </nav>
    </header>
    <main id="page-content" class="@yield('width', 'narrow')" tabindex="-1" aria-label="@yield('title')">
        @yield('content')
    </main>
</body>
</html>
