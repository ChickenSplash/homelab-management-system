<?php

namespace App\Models;

use App\Support\Live;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['display_name', 'email', 'password', 'is_guest'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    // No 0/o or 1/i/l, so an ID read out loud or copied by hand survives.
    private const PUBLIC_ID_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const PUBLIC_ID_LENGTH = 8;

    /** @var array<int, User> Friends and DM partners to tell once this user is deleted. */
    private array $leftBehind = [];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->public_id ??= static::newPublicId();
        });

        // Tokens hang off a morph relation, which has no foreign key to cascade.
        static::deleting(function (User $user) {
            $user->tokens()->delete();
            $user->leftBehind = $user->contacts()->all();

            // Leave groups properly, so ownership passes on and the others see them go.
            $user->memberships()->with('conversation')->get()
                ->filter(fn (ConversationMember $m) => $m->conversation?->isGroup())
                ->each(fn (ConversationMember $m) => $m->conversation->removeMember($user));
        });

        // Friendships and memberships cascade in the database. A DM nobody is left in goes too;
        // one with a survivor stays, its messages shown from "Deleted user".
        static::deleted(function (User $user) {
            Conversation::doesntHave('members')->delete();
            Live::to($user->leftBehind, 'friends.changed');
            Live::to($user->leftBehind, 'conversations.changed');
        });
    }

    public static function newPublicId(): string
    {
        do {
            $id = '';

            for ($i = 0; $i < self::PUBLIC_ID_LENGTH; $i++) {
                $id .= self::PUBLIC_ID_ALPHABET[random_int(0, strlen(self::PUBLIC_ID_ALPHABET) - 1)];
            }
        } while (DB::table('users')->where('public_id', $id)->exists());

        return $id;
    }

    /** What anyone may see about a user. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->public_id,
            'display_name' => $this->display_name,
            'is_guest' => $this->is_guest,
        ];
    }

    public function hasBlocked(User $other): bool
    {
        return Block::where(['blocker_id' => $this->id, 'blocked_id' => $other->id])->exists();
    }

    public function blockedEitherWay(User $other): bool
    {
        return $this->hasBlocked($other) || $other->hasBlocked($this);
    }

    /**
     * Everyone with a friendship, request or DM with this user. Group members are not
     * contacts: they hear about a departure from the group itself.
     *
     * @return Collection<int, User>
     */
    public function contacts(): Collection
    {
        $ids = Friendship::involving($this)->get(['requester_id', 'addressee_id'])
            ->flatMap(fn (Friendship $f) => [$f->requester_id, $f->addressee_id])
            ->merge(ConversationMember::whereIn('conversation_id', $this->memberships()
                ->whereHas('conversation', fn ($q) => $q->where('type', 'dm'))
                ->select('conversation_id'))->pluck('user_id'))
            ->reject(fn (int $id) => $id === $this->id)
            ->unique();

        return User::whereIn('id', $ids)->get();
    }

    /** @return HasMany<ConversationMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    /**
     * Guests that can no longer come back: their session expired or was never saved.
     *
     * @param  Builder<User>  $query
     */
    public function scopeAbandonedGuests(Builder $query): void
    {
        $since = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        $query->where('is_guest', true)
            // Grace period, so a guest made a moment ago is not swept before its session is written.
            ->where('created_at', '<', now()->subMinutes(10))
            ->whereNotExists(fn ($sessions) => $sessions
                ->from(config('session.table'))
                ->whereColumn('user_id', 'users.id')
                ->where('last_activity', '>=', $since));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_guest' => 'boolean',
        ];
    }
}
