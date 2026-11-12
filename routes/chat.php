<?php

use App\Http\Controllers\ConversationController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\PresenceController;
use Illuminate\Support\Facades\Route;

// Loaded from routes/api.php under /api, signed in.
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
