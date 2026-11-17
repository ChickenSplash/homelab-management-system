<?php

use App\Events\Ping;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Middleware\DailyPrivate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Signing in happens on the sign-in site (routes/accounts.php), which shares its session with the apps.
Route::prefix('auth')->controller(AuthController::class)->middleware('auth:sanctum')->group(function () {
    Route::post('/logout', 'logout');
    Route::get('/me', 'me');
    Route::patch('/me', 'update');
    Route::put('/password', 'updatePassword')->middleware('throttle:auth');
});

Route::middleware('auth:sanctum')->group(base_path('routes/chat.php'));
Route::middleware('auth:sanctum')->prefix('music')->group(base_path('routes/music.php'));
Route::middleware([DailyPrivate::class, 'auth:sanctum', 'throttle:120,1'])->prefix('daily')->group(base_path('routes/daily.php'));

// What a browser needs to open the Reverb WebSocket. The key is public by design;
// the secret never leaves the server.
Route::get('/realtime', fn () => [
    'key' => config('broadcasting.connections.reverb.key'),
]);

Route::middleware(['auth:sanctum', 'throttle:ping'])->post('/ping', function (Request $request) {
    Ping::dispatch($request->user());

    return response()->json(['ok' => true], 202);
});

// The portfolio's contact form. Its nginx (and the glass box) forward /api/contact here over
// the edge network, so the form posts to its own origin and there is no CORS to set up.
Route::middleware('throttle:contact')->post('/contact', [ContactController::class, 'store']);
