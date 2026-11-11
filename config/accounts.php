<?php

return [
    // The sign-in site, served by HMS itself. On its host only the sign-in pages answer; the
    // API stays reachable through the apps' own proxies alone.
    'url' => rtrim((string) env('ACCOUNTS_URL', 'http://localhost:8000'), '/'),

    // The apps it launches. Their origins are the only places it sends anyone back to after
    // signing in, so it can't be used to bounce people on to another site.
    'apps' => array_values(array_filter([
        ['name' => 'Chat', 'description' => 'Friends, groups and direct messages.', 'url' => env('CHAT_URL', 'https://chat.chickensplash.dpdns.org')],
        ['name' => 'Music', 'description' => 'AI-made music, free to stream.', 'url' => env('MUSIC_URL', 'https://music.chickensplash.dpdns.org')],
    ], fn (array $app) => filled($app['url']))),
];
