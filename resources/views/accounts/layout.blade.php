<!doctype html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Homelab accounts</title>
    <link rel="stylesheet" href="/accounts.css?v={{ @filemtime(public_path('accounts.css')) }}">
</head>
<body>
    <main class="@yield('width', 'narrow')">
        @yield('content')
    </main>
</body>
</html>
