<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

// A round trip through the queue and Reverb back to the sender, to prove live updates work.
class Ping implements ShouldBroadcast
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
