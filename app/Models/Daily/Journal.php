<?php

namespace App\Models\Daily;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    protected $table = 'daily_journals';

    protected $guarded = ['id'];

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }
}
