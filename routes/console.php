<?php

use App\Models\User;
use App\Support\Music\Scanner;
use App\Support\Presence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

Artisan::command('music:scan', function (Scanner $scanner) {
    // Shared with the studio's "Scan now", so the two never add the same new file twice.
    $lock = Cache::lock('music:scan', 300);

    if (! $lock->get()) {
        $this->warn('A scan is already running.');

        return;
    }

    try {
        $counts = $scanner->scan();
    } finally {
        $lock->release();
    }

    $this->info(collect($counts)->map(fn (int $n, string $what) => "{$what} {$n}")->join(', '));
})->purpose('Pick up new, changed, moved and missing files in the music folder');

Schedule::command('guests:prune')->hourly();
Schedule::command('music:scan')->everyFiveMinutes();
Schedule::command('presence:sync')->everyMinute();
