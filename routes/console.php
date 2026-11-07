<?php

use App\Models\User;
use App\Support\Presence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('guests:prune', function () {
    $count = 0;

    // One by one, so each delete runs the model's clean-up hooks.
    User::abandonedGuests()->lazyById()->each(function (User $user) use (&$count) {
        $user->delete();
        $count++;
    });

    $this->info("Pruned {$count} abandoned guest(s).");
})->purpose('Delete guests whose session has expired');

Artisan::command('presence:sync', function (Presence $presence) {
    $connected = $presence->connectedIds();

    // Anyone Reverb and the database disagree about (a Reverb restart sends no "left" events),
    // plus anyone online who may have gone idle.
    User::where(fn ($q) => $q->whereNotNull('connected_at')->orWhereIn('public_id', $connected)->orWhere('presence', '!=', 'offline'))
        ->lazyById()
        ->each(fn (User $user) => $user->markConnected(in_array($user->public_id, $connected, true)));
})->purpose('Match online statuses to Reverb and mark inactive people idle');

Schedule::command('guests:prune')->hourly();
Schedule::command('presence:sync')->everyMinute();
