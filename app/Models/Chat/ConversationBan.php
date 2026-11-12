<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Keeps someone out of a group: they can't rejoin by browsing or by invite link.
#[Table('chat_conversation_bans')]
#[Fillable(['conversation_id', 'user_id', 'banned_by'])]
class ConversationBan extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
