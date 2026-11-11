<?php

use App\Http\Controllers\MusicController;
use Illuminate\Support\Facades\Route;

// Audio and images, fetched by <audio> and <img> rather than by the app's own requests. Those
// don't reliably send the Origin or Referer that Sanctum looks for before it starts a session
// (iOS plays audio from a separate media process), so these use the session cookie directly.
// Same session, same login as the API.
Route::prefix('api/music')->controller(MusicController::class)->group(function () {
    // Every seek is a new range request, and each one checks the session here first.
    Route::get('/tracks/{track}/stream', 'stream')->middleware('throttle:music-stream');
    Route::get('/tracks/{track}/cover', 'trackCover');
    Route::get('/albums/{album}/cover', 'albumCover');
});
