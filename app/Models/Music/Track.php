<?php

namespace App\Models\Music;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('music_tracks')]
#[Fillable([
    'user_id', 'album_id', 'track_no', 'title', 'duration_ms', 'bitrate', 'genre', 'is_ai', 'lyrics',
    'cover_path', 'published_at', 'path', 'size', 'mtime', 'fingerprint', 'missing_at',
])]
class Track extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return HasMany<Play, $this> */
    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    /**
     * Published, with its file present: what listeners see.
     *
     * @param  Builder<Track>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->whereNotNull('music_tracks.published_at')->whereNull('music_tracks.missing_at');
    }

    /**
     * Adds `liked` for this listener, and loads what every track card shows.
     *
     * @param  Builder<Track>  $query
     */
    public function scopeForListener(Builder $query, User $user): void
    {
        $query->with(['user', 'album'])
            ->withExists(['likes as liked' => fn ($q) => $q->where('user_id', $user->id)]);
    }

    /** @return HasMany<Like, $this> */
    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function isListed(): bool
    {
        return $this->published_at !== null && $this->missing_at === null;
    }

    /** Listeners see listed tracks; creators also see their own inbox and missing files. */
    public function isVisibleTo(User $user): bool
    {
        return $this->isListed() || $this->user_id === $user->id;
    }

    /** Its own art, or its album's. */
    public function coverUrl(): ?string
    {
        if ($this->cover_path) {
            return "/api/music/tracks/{$this->id}/cover?v=".substr(md5($this->cover_path), 0, 8);
        }

        return $this->album?->coverUrl();
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'duration_ms' => $this->duration_ms,
            'is_ai' => $this->is_ai,
            'genre' => $this->genre,
            'track_no' => $this->track_no,
            'has_lyrics' => filled($this->lyrics),
            'cover_url' => $this->coverUrl(),
            'creator' => $this->user?->toPublicArray(),
            'album' => $this->album ? ['id' => $this->album->id, 'title' => $this->album->title] : null,
            'liked' => (bool) ($this->liked ?? false),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }

    /**
     * What the creator sees in their studio: everything, including where the file is.
     *
     * @return array<string, mixed>
     */
    public function toStudioArray(): array
    {
        return [
            ...$this->toPublicArray(),
            'lyrics' => $this->lyrics,
            'bitrate' => $this->bitrate,
            'has_own_cover' => $this->cover_path !== null,
            // Below their own folder, which they already know by their public ID.
            'file' => str($this->path)->after('/')->value(),
            'missing_at' => $this->missing_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    protected function casts(): array
    {
        return [
            'is_ai' => 'boolean',
            'published_at' => 'datetime',
            'missing_at' => 'datetime',
        ];
    }
}
