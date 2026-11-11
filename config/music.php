<?php

return [
    // The music folder, mounted read-only. Each creator's MP3s live under a folder named after
    // their public ID: <root>/<public_id>/anything/below.mp3.
    'root' => env('MUSIC_ROOT', storage_path('app/music')),

    // Cover art uploaded in the studio or pulled out of the MP3s. Must survive a rebuild, so in
    // the containers it sits on the database volume.
    'covers' => env('MUSIC_COVERS', storage_path('app/music-covers')),

    // When set, streams are handed to the front end's nginx with X-Accel-Redirect to this
    // internal location (which serves the music root), so a slow listener never ties up a PHP
    // worker. Unset (development, tests), PHP sends the file itself.
    'accel_prefix' => env('MUSIC_ACCEL_PREFIX'),
];
