<?php

namespace App\Models\Daily;

use Illuminate\Database\Eloquent\Model;

class Entry extends Model
{
    protected $table = 'daily_entries';

    protected $guarded = ['id'];
}
