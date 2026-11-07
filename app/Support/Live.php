<?php

namespace App\Support;

use App\Events\LiveUpdate;
use App\Models\User;

class Live
{
    /**
     * Push an event to each user's private channel, in one broadcast (the Pusher API takes
     * up to 100 channels a call). The change is already saved, so a failed broadcast is
     * reported rather than thrown: clients catch up when they next refetch.
     *
     * @param  User|iterable<User>  $users
     * @param  array<string, mixed>  $payload
     */
    public static function to(User|iterable $users, string $name, array $payload = []): void
    {
        $ids = collect($users instanceof User ? [$users] : $users)
            ->filter()
            ->map(fn (User $user) => $user->public_id)
            ->unique()
            ->values()
            ->all();

        if ($ids !== []) {
            rescue(fn () => event(new LiveUpdate($ids, $name, $payload)));
        }
    }
}
