<?php

/*
| Only the keys that differ from Sanctum's own defaults are here; the package
| merges the rest in.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Browser apps on these hosts sign in with a session cookie and must send
    | the XSRF-TOKEN back. Requests from anywhere else (the portfolio's contact
    | form, scripts) get no session, so a signed-in browser cannot be used
    | against the API from another site.
    |
    */

    'stateful' => array_filter(explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        'localhost,localhost:5173,127.0.0.1,127.0.0.1:5173',
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | A session from a stateful domain counts; everything else needs a bearer token.
    |
    */

    'guard' => ['web'],

];
