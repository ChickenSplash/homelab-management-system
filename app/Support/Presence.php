<?php

namespace App\Support;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Pusher\Pusher;

// Whether someone is online is Reverb's to say: a user is connected while any of their tabs
// holds their private channel. Asked fresh each time rather than remembered, so a missed
// event can't leave anyone stuck online.
class Presence
{
    public const STATUSES = ['online', 'idle', 'dnd', 'invisible'];

    public const IDLE_AFTER_MINUTES = 10;

    public const CHANNEL_PREFIX = 'private-user.';

    public function isConnected(string $publicId): bool
    {
        return (bool) ($this->pusher()->getChannelInfo(self::CHANNEL_PREFIX.$publicId)->occupied ?? false);
    }

    /** @return array<int, string> public IDs of everyone connected right now */
    public function connectedIds(): array
    {
        // Not getChannels(): Reverb sends "no channels" as [] rather than {}, which it can't read.
        $channels = (array) $this->pusher()->get('/channels', ['filter_by_prefix' => self::CHANNEL_PREFIX])->channels;

        return array_map(fn (string $name) => Str::after($name, self::CHANNEL_PREFIX), array_keys($channels));
    }

    private function pusher(): Pusher
    {
        return Broadcast::connection('reverb')->getPusher();
    }
}
