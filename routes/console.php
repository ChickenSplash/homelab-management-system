<?php

use App\Models\User;
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

Schedule::command('guests:prune')->hourly();
