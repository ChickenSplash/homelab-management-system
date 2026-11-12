<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// A message in a DM or group. Not to be confused with Message, the contact form inbox.
#[Table('chat_messages')]
#[Fillable(['conversation_id', 'user_id', 'body', 'nonce', 'type', 'meta', 'reply_to_message_id'])]
class ChatMessage extends Model
{
    use SoftDeletes;

    /** What a deleted message's text becomes. The original is not kept anywhere. */
    public const DELETED_BODY = '[message deleted]';

    protected static function booted(): void
    {
        // A soft delete only writes deleted_at, so the text is wiped in a second save.
        static::softDeleted(function (ChatMessage $message) {
            $message->forceFill(['body' => self::DELETED_BODY])->saveQuietly();
        });
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

    /** @return BelongsTo<ChatMessage, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id')->withTrashed();
    }

    /**
     * The same shape over HTTP and the WebSocket. The author never includes an email.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'type' => $this->type ?? 'text',
            'author' => $this->user?->toPublicArray(),
            'body' => $this->body,
            'meta' => $this->meta,
            'nonce' => $this->nonce,
            'created_at' => $this->created_at->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
            // A shallow, current preview, never a snapshot or a recursive reply chain.
            'reply_to' => $this->reply_to_message_id ? [
                'id' => $this->reply_to_message_id,
                'author' => $this->replyTo && ! $this->replyTo->trashed() ? $this->replyTo->user?->toPublicArray() : null,
                'body' => $this->replyTo && ! $this->replyTo->trashed() ? mb_substr($this->replyTo->body, 0, 200) : null,
                'deleted' => ! $this->replyTo || $this->replyTo->trashed(),
            ] : null,
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
            'edited_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
