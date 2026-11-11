<?php

namespace App\Models\Music;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('music_albums')]
#[Fillable(['user_id', 'title', 'description', 'cover_path', 'released_at'])]
class Album extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Track, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    /**
     * Albums with at least one listed track. An album with none is still being put together.
     *
     * @param  Builder<Album>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->whereHas('tracks', fn ($q) => $q->listed());
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->user_id === $user->id || $this->tracks()->listed()->exists();
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path
            ? "/api/music/albums/{$this->id}/cover?v=".substr(md5($this->cover_path), 0, 8)
            : null;
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'cover_url' => $this->coverUrl(),
            'released_at' => $this->released_at?->toDateString(),
            'creator' => $this->user?->toPublicArray(),
            ...$this->hasAttribute('listed_tracks_count') ? ['track_count' => $this->listed_tracks_count] : [],
        ];
    }

    protected function casts(): array
    {
        return [
            'released_at' => 'date',
        ];
    }
}
