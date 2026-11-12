<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A friend request until accepted_at is set, then a friendship. One row per pair.
#[Table('chat_friendships')]
#[Fillable(['requester_id', 'addressee_id', 'accepted_at'])]
class Friendship extends Model
{
    /** @param Builder<Friendship> $query */
    public function scopeBetween(Builder $query, User $a, User $b): void
    {
        $query->where(fn ($q) => $q
            ->where(['requester_id' => $a->id, 'addressee_id' => $b->id])
            ->orWhere(fn ($q) => $q->where(['requester_id' => $b->id, 'addressee_id' => $a->id])));
    }

    /** @param Builder<Friendship> $query */
    public function scopeInvolving(Builder $query, User $user): void
    {
        $query->where(fn ($q) => $q->where('requester_id', $user->id)->orWhere('addressee_id', $user->id));
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    public function otherThan(User $user): User
    {
        return $this->requester_id === $user->id ? $this->addressee : $this->requester;
    }

    /** How the friendship looks from one side: friend, incoming or outgoing. */
    public function statusFor(User $user): string
    {
        if ($this->accepted_at) {
            return 'friend';
        }

        return $this->requester_id === $user->id ? 'outgoing' : 'incoming';
    }

    /** @return array<string, mixed> */
    public function toEntryFor(User $user): array
    {
        return [
            'user' => $this->otherThan($user)->toPublicArray(),
            'status' => $this->statusFor($user),
            'since' => ($this->accepted_at ?? $this->created_at)->toIso8601String(),
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }
}
