<?php

use App\Events\Ping;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\PresenceController;
use App\Mail\ContactMessageReceived;
use App\Models\Message;
use App\Support\ClientIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', 'register');
        Route::post('/login', 'login');
    });

    Route::post('/guest', 'guest')->middleware('throttle:guest');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', 'logout');
        Route::get('/me', 'me');
        Route::patch('/me', 'update');
        Route::put('/password', 'updatePassword')->middleware('throttle:auth');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::controller(FriendController::class)->group(function () {
        Route::get('/friends', 'index');
        Route::post('/friends', 'store')->middleware('throttle:find-users');
        Route::post('/friends/{publicId}/accept', 'accept');
        Route::delete('/friends/{publicId}', 'destroy');

        Route::get('/blocks', 'blocks');
        Route::put('/blocks/{publicId}', 'block');
        Route::delete('/blocks/{publicId}', 'unblock');
    });

    Route::controller(ConversationController::class)->group(function () {
        Route::post('/dms', 'openDm')->middleware('throttle:find-users');
        Route::get('/conversations', 'index');
        Route::get('/conversations/{conversation}', 'show')->whereNumber('conversation');
        Route::get('/conversations/{conversation}/messages', 'messages');
        Route::post('/conversations/{conversation}/messages', 'send')->middleware('throttle:messages');
        Route::delete('/conversations/{conversation}/messages/{message}', 'deleteMessage')->scopeBindings();
        Route::patch('/conversations/{conversation}/messages/{message}', 'editMessage')->scopeBindings()->middleware('throttle:messages');
        Route::post('/conversations/{conversation}/read', 'read');
        Route::post('/conversations/{conversation}/typing', 'typing')->middleware('throttle:typing');
    });

    Route::controller(PresenceController::class)->group(function () {
        Route::get('/presence', 'index');
        Route::post('/presence/active', 'active')->middleware('throttle:presence');
        Route::put('/presence/status', 'status')->middleware('throttle:presence');
    });

    Route::controller(GroupController::class)->group(function () {
        Route::get('/groups', 'browse');
        Route::post('/groups', 'store')->middleware('throttle:groups');
        Route::patch('/groups/{conversation}', 'update');
        Route::delete('/groups/{conversation}', 'destroy');
        Route::post('/groups/{conversation}/join', 'join');
        Route::post('/groups/{conversation}/leave', 'leave');
        Route::post('/groups/{conversation}/invite', 'resetInvite');
        Route::post('/groups/{conversation}/transfer', 'transfer');
        Route::get('/groups/{conversation}/members', 'members');
        Route::put('/groups/{conversation}/members/{publicId}', 'setRole');
        Route::delete('/groups/{conversation}/members/{publicId}', 'kick');
        Route::get('/groups/{conversation}/bans', 'bans');
        Route::put('/groups/{conversation}/bans/{publicId}', 'ban');
        Route::delete('/groups/{conversation}/bans/{publicId}', 'unban');

        // Invite codes are short enough to guess at, so looking them up shares the find-users limit.
        Route::get('/invites/{code}', 'invite')->middleware('throttle:find-users');
        Route::post('/invites/{code}', 'acceptInvite')->middleware('throttle:find-users');
    });
});

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
Route::middleware('throttle:contact')->post('/contact', function (Request $request) {
    // Bots fill every field they find; people never see this one. Pretend it worked.
    if (filled($request->input('website'))) {
        return response()->json(['ok' => true], 201);
    }

    $validated = $request->validate([
        'email' => ['required', 'email', 'max:255'],
        'subject' => ['required', 'string', 'max:150'],
        'message' => ['required', 'string', 'max:5000'],
    ]);

    $message = Message::create([
        'email' => $validated['email'],
        'subject' => $validated['subject'],
        'body' => $validated['message'],
        'ip' => ClientIp::of($request),
        'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
    ]);

    // The message is already saved, so a mail hiccup is logged rather than shown to the sender.
    if ($admin = config('app.admin_email')) {
        try {
            Mail::to($admin)->send(new ContactMessageReceived($message));
        } catch (Throwable $e) {
            report($e);
        }
    }

    return response()->json(['ok' => true], 201);
});
