<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

// Anything one person's open tabs should hear about: friend changes, new messages, reads.
// Sent with App\Support\Live, which never lets a Reverb outage fail the request.
class LiveUpdate implements ShouldBroadcastNow
{
    /**
     * @param  array<int, string>  $recipients  public IDs; one call reaches them all
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $recipients,
        public string $name,
        public array $payload = [],
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (string $id) => new PrivateChannel('user.'.$id), $this->recipients);
    }

    public function reaches(User $user): bool
    {
        return in_array($user->public_id, $this->recipients, true);
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
