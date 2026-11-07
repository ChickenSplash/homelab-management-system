<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\Presence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

// Queued by Reverb when someone's first tab connects or their last one goes. The worker
// asks Reverb whether they are connected now, so a quick reload never shows them offline.
class SyncPresence implements ShouldQueue
{
    use Queueable;

    public int $backoff = 2;

    public function __construct(public string $publicId) {}

    public function handle(Presence $presence): void
    {
        User::where('public_id', $this->publicId)->first()
            ?->markConnected($presence->isConnected($this->publicId));
    }
}
