<?php

namespace App\Policies;

use App\Models\ChatMessage;
use App\Models\User;

class ChatMessagePolicy
{
    public function update(User $user, ChatMessage $message): bool
    {
        $conversation = $message->conversation;

        if ($message->trashed() || $message->type === 'system' || $message->user_id !== $user->id
            || ! $conversation->members()->where('user_id', $user->id)->exists()) {
            return false;
        }

        if ($conversation->type === 'dm') {
            $partner = $conversation->partnerOf($user);

            return $partner && ! $user->blockedEitherWay($partner);
        }

        return true;
    }
}
