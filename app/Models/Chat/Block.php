<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One-way: the blocker stops seeing the blocked person's requests and neither can DM the other.
#[Table('chat_blocks')]
#[Fillable(['blocker_id', 'blocked_id'])]
class Block extends Model
{
    /** @return BelongsTo<User, $this> */
    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }
}
