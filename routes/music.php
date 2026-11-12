<?php

use App\Http\Controllers\MusicController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;

// Loaded from routes/api.php under /api/music, signed in.
Route::controller(MusicController::class)->group(function () {
    Route::get('/home', 'home');
    Route::get('/search', 'search');
    Route::get('/tracks/{track}', 'track');
    Route::post('/tracks/{track}/play', 'play')->middleware('throttle:music-writes');
    Route::get('/albums/{album}', 'album');
    Route::get('/creators/{publicId}', 'creator');
    Route::get('/likes', 'likes');
    Route::put('/likes/{track}', 'like')->middleware('throttle:music-writes');
    Route::delete('/likes/{track}', 'unlike')->middleware('throttle:music-writes');
});

Route::controller(PlaylistController::class)->middleware('throttle:music-writes')->group(function () {
    Route::get('/playlists', 'index')->withoutMiddleware('throttle:music-writes');
    Route::post('/playlists', 'store');
    Route::get('/playlists/{playlist}', 'show')->withoutMiddleware('throttle:music-writes');
    Route::patch('/playlists/{playlist}', 'update');
    Route::delete('/playlists/{playlist}', 'destroy');
    Route::post('/playlists/{playlist}/tracks', 'addTracks');
    Route::put('/playlists/{playlist}/tracks/{entry}', 'moveEntry')->scopeBindings();
    Route::delete('/playlists/{playlist}/tracks/{entry}', 'removeEntry')->scopeBindings();
});

Route::prefix('studio')->controller(StudioController::class)->middleware('throttle:music-writes')->group(function () {
    Route::get('/', 'index')->withoutMiddleware('throttle:music-writes');
    Route::patch('/tracks', 'updateTracks');
    Route::patch('/tracks/{track}', 'updateTrack');
    Route::post('/tracks/{track}/cover', 'trackCover');
    Route::delete('/tracks/{track}/cover', 'removeTrackCover');
    Route::post('/albums', 'storeAlbum');
    Route::patch('/albums/{album}', 'updateAlbum');
    Route::delete('/albums/{album}', 'destroyAlbum');
    Route::post('/albums/{album}/cover', 'albumCover');
    Route::post('/scan', 'scan')->middleware('throttle:music-scan');
});
