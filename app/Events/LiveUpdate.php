<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

// Anything one person's open tabs should hear about: friend changes, new messages, reads.
// Sent with App\Support\Live, which never lets a Reverb outage fail the request.
class LiveUpdate implements ShouldBroadcastNow
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public string $recipient,
        public string $name,
        public array $payload = [],
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->recipient);
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
