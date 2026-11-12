<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('chat_conversation_members')]
#[Fillable(['conversation_id', 'user_id', 'role', 'last_read_message_id'])]
class ConversationMember extends Model
{
    /** Group roles, lowest first. DM members are all plain members. */
    public const RANKS = ['member' => 0, 'mod' => 1, 'admin' => 2, 'owner' => 3];

    public function rank(): int
    {
        return self::RANKS[$this->role] ?? 0;
    }

    public function atLeast(string $role): bool
    {
        return $this->rank() >= self::RANKS[$role];
    }

    /** Someone who is no longer a member counts as the lowest rank. */
    public function outranks(?ConversationMember $other): bool
    {
        return $this->rank() > ($other?->rank() ?? 0);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
