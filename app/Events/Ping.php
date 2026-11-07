<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// A round trip through Reverb back to the sender, to prove live updates work. Sent during the
// request rather than queued: the queue worker polls once a second, too slow for chat.
class Ping implements ShouldBroadcastNow
{
    use Dispatchable;

    public string $sentAt;

    public function __construct(public User $user)
    {
        $this->sentAt = now()->toIso8601String();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->user->public_id);
    }

    public function broadcastAs(): string
    {
        return 'ping';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['sent_at' => $this->sentAt];
    }
}
