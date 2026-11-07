<?php

namespace App\Support;

use App\Events\LiveUpdate;
use App\Models\User;

class Live
{
    /**
     * Push an event to each user's private channel. The change is already saved, so a failed
     * broadcast is reported rather than thrown: clients catch up when they next refetch.
     *
     * @param  User|iterable<User>  $users
     * @param  array<string, mixed>  $payload
     */
    public static function to(User|iterable $users, string $name, array $payload = []): void
    {
        foreach ($users instanceof User ? [$users] : $users as $user) {
            rescue(fn () => event(new LiveUpdate($user->public_id, $name, $payload)));
        }
    }
}
