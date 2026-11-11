<?php

namespace App\Models\Music;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// "Liked Songs". Keyed on the pair, so there is no id; written and deleted with the query builder.
#[Table('music_likes', key: false, incrementing: false, timestamps: false)]
#[Fillable(['user_id', 'track_id', 'created_at'])]
class Like extends Model
{
    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
