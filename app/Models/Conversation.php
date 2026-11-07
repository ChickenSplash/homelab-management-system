<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['type', 'dm_key', 'created_by', 'last_message_at'])]
class Conversation extends Model
{
    public static function dmKey(User $a, User $b): string
    {
        return min($a->id, $b->id).':'.max($a->id, $b->id);
    }

    /** @param Builder<Conversation> $query */
    public function scopeWithMember(Builder $query, User $user): void
    {
        $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
    }

    /** @return HasMany<ConversationMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /** @return HasOne<ChatMessage, $this> */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    /** The caller's membership; anyone else gets a 404, as if the conversation did not exist. */
    public function membershipOf(User $user): ConversationMember
    {
        $member = $this->members->firstWhere('user_id', $user->id);

        abort_unless($member, 404);

        return $member;
    }

    /** The other person in a DM, or null once they have been deleted. */
    public function partnerOf(User $user): ?User
    {
        return $this->members->firstWhere(fn (ConversationMember $m) => $m->user_id !== $user->id)?->user;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }
}
