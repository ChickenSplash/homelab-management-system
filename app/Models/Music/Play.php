<?php

namespace App\Models\Music;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('music_plays', timestamps: false)]
#[Fillable(['user_id', 'track_id', 'played_at'])]
class Play extends Model
{
    /** Each listener's history is cut back to this many plays. */
    public const KEEP_PER_USER = 200;

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public static function record(User $user, Track $track): void
    {
        static::create(['user_id' => $user->id, 'track_id' => $track->id, 'played_at' => now()]);

        $cutoff = static::where('user_id', $user->id)->orderByDesc('id')->skip(self::KEEP_PER_USER)->value('id');

        if ($cutoff) {
            static::where('user_id', $user->id)->where('id', '<=', $cutoff)->delete();
        }
    }

    protected function casts(): array
    {
        return [
            'played_at' => 'datetime',
        ];
    }
}
