<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Everything meant for one person: pings now; friend requests, new DMs and kicks later.
Broadcast::channel('user.{publicId}', fn (User $user, string $publicId) => $user->public_id === $publicId);
