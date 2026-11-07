<?php

namespace App\Models;

use App\Support\Live;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable(['type', 'dm_key', 'created_by', 'last_message_at', 'name', 'description', 'visibility', 'invite_code'])]
class Conversation extends Model
{
    public const MAX_MEMBERS = 200;

    private const INVITE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function dmKey(User $a, User $b): string
    {
        return min($a->id, $b->id).':'.max($a->id, $b->id);
    }

    public static function newInviteCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < 8; $i++) {
                $code .= self::INVITE_ALPHABET[random_int(0, strlen(self::INVITE_ALPHABET) - 1)];
            }
        } while (static::where('invite_code', $code)->exists());

        return $code;
    }

    /** @param Builder<Conversation> $query */
    public function scopeWithMember(Builder $query, User $user): void
    {
        $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
    }

    /**
     * Adds `unread_count`: other people's messages past the viewer's read marker. System
     * messages ("Bob joined") don't count.
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeForViewer(Builder $query, User $me): void
    {
        $query->select('conversations.*')
            ->withCount('members')
            ->with('members.user', 'lastMessage.user', 'lastMessage.replyTo.user')
            ->addSelect(['unread_count' => ChatMessage::selectRaw('count(*)')
                ->whereColumn('chat_messages.conversation_id', 'conversations.id')
                ->where('chat_messages.type', 'text')
                ->where(fn ($q) => $q->whereNull('chat_messages.user_id')->orWhere('chat_messages.user_id', '!=', $me->id))
                ->where('chat_messages.id', '>', ConversationMember::selectRaw('coalesce(max(last_read_message_id), 0)')
                    ->whereColumn('conversation_members.conversation_id', 'conversations.id')
                    ->where('conversation_members.user_id', $me->id)),
            ]);
    }

    /** One conversation as its member sees it; a 404 for anyone else. */
    public static function viewFor(int $id, User $me): array
    {
        return static::whereKey($id)->forViewer($me)->firstOrFail()->presentFor($me);
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

    /** @return HasMany<ConversationBan, $this> */
    public function bans(): HasMany
    {
        return $this->hasMany(ConversationBan::class);
    }

    /** @return HasOne<ChatMessage, $this> */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
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

    /** @return Collection<int, User> */
    public function memberUsers(): Collection
    {
        return User::whereIn('id', $this->members()->select('user_id'))->get();
    }

    /** @return array<string, mixed> */
    public function presentFor(User $me): array
    {
        $mine = $this->membershipOf($me);
        $common = [
            'id' => $this->id,
            'type' => $this->type,
            'last_message' => $this->lastMessage?->toPublicArray(),
            'last_read_message_id' => $mine->last_read_message_id,
            'unread_count' => (int) $this->unread_count,
        ];

        if ($this->isGroup()) {
            return [...$common,
                'name' => $this->name,
                'description' => $this->description,
                'visibility' => $this->visibility,
                'invite_code' => $this->invite_code,
                'role' => $mine->role,
                'member_count' => (int) ($this->members_count ?? $this->members->count()),
                'joined_at' => $mine->created_at->toIso8601String(),
            ];
        }

        $partner = $this->partnerOf($me);

        return [...$common,
            'user' => $partner?->toPublicArray(),
            'blocked' => $partner ? $me->blockedEitherWay($partner) : false,
        ];
    }

    /** What anyone holding the invite (or browsing, for public groups) may see. */
    public function toPreviewFor(User $me): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'member_count' => (int) ($this->members_count ?? $this->members()->count()),
            'joined' => $this->members()->where('user_id', $me->id)->exists(),
        ];
    }

    /** Add someone to a group, unless they are banned or it is full. Joining twice is harmless. */
    public function addMember(User $user): void
    {
        $message = DB::transaction(function () use ($user) {
            if ($this->members()->where('user_id', $user->id)->exists()) {
                return null;
            }

            abort_if($this->bans()->where('user_id', $user->id)->exists(), 403, 'You are banned from this group.');

            if ($this->members()->count() >= self::MAX_MEMBERS) {
                throw ValidationException::withMessages(['group' => 'This group is full.']);
            }

            $this->members()->create(['user_id' => $user->id, 'role' => 'member']);

            return $this->postSystem('joined', $user);
        });

        $this->unsetRelation('members');

        if ($message) {
            $this->broadcastMessages([$message]);
        }
    }

    /**
     * Take someone out of a group: they left, were kicked or were banned. If the owner goes,
     * the oldest of the highest remaining rank takes over; the last one out deletes the group.
     */
    public function removeMember(User $user, string $event = 'left', ?User $by = null): void
    {
        $recipients = $this->memberUsers();

        $messages = DB::transaction(function () use ($user, $event, $by) {
            $member = $this->members()->where('user_id', $user->id)->first();

            if (! $member) {
                return [];
            }

            $member->delete();
            $remaining = $this->members()->orderBy('id')->get();

            if ($remaining->isEmpty()) {
                $this->delete();

                return [];
            }

            $messages = [$this->postSystem($event, $user, $by)];

            if ($member->role === 'owner') {
                $heir = $remaining->sort(fn ($a, $b) => [$b->rank(), $a->id] <=> [$a->rank(), $b->id])->first();
                $heir->update(['role' => 'owner']);
                $messages[] = $this->postSystem('owner', $heir->user);
            }

            return $messages;
        });

        $this->unsetRelation('members');

        // The person removed hears only that their list changed: no more of the group's messages.
        Live::to($recipients->reject(fn (User $u) => $u->is($user)), 'conversations.changed', ['conversation_id' => $this->id]);
        Live::to($user, 'conversations.changed', ['conversation_id' => $this->id]);
        $this->broadcastMessages($messages);
    }

    /**
     * A grey line in the chat recording a membership or role change. Names are a snapshot,
     * so the line still reads right after a rename or once the person is deleted.
     *
     * @param  array<string, mixed>  $extra
     */
    public function postSystem(string $event, User $user, ?User $by = null, array $extra = []): ChatMessage
    {
        return $this->messages()->create([
            'type' => 'system',
            'body' => '',
            'meta' => array_filter([
                'event' => $event,
                'user' => $user->toPublicArray(),
                'by' => $by?->toPublicArray(),
                ...$extra,
            ], fn ($value) => $value !== null),
        ]);
    }

    /** @param  array<int, ChatMessage>  $messages */
    public function broadcastMessages(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $users = $this->memberUsers();

        foreach ($messages as $message) {
            Live::to($users, 'message.sent', $message->toPublicArray());
        }
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
