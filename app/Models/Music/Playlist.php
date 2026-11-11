<?php

namespace App\Models\Music;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Private to whoever made it, for now.
#[Table('music_playlists')]
#[Fillable(['user_id', 'name', 'description'])]
class Playlist extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PlaylistTrack, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(PlaylistTrack::class)->orderBy('position');
    }

    /** Closes the gaps a removal leaves, so positions always run 0, 1, 2, … */
    public function renumber(): void
    {
        foreach ($this->entries()->pluck('id')->values() as $position => $id) {
            PlaylistTrack::whereKey($id)->update(['position' => $position]);
        }
    }

    /**
     * For the sidebar: a count and the covers of the first few listed tracks, for a mosaic.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        $listed = $this->entries()->whereHas('track', fn ($q) => $q->listed());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'track_count' => (clone $listed)->count(),
            'covers' => (clone $listed)->with('track.album')->limit(12)->get()
                ->map(fn (PlaylistTrack $entry) => $entry->track->coverUrl())
                ->filter()->unique()->take(4)->values(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
