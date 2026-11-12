<?php

namespace App\Models\Daily;

use Illuminate\Database\Eloquent\Model;

class ActivityDay extends Model
{
    protected $table = 'daily_activity_days';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['target' => 'float', 'actual' => 'float', 'is_one_off' => 'boolean'];
    }
}
