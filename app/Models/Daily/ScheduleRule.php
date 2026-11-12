<?php

namespace App\Models\Daily;

use Illuminate\Database\Eloquent\Model;

class ScheduleRule extends Model
{
    protected $table = 'daily_schedule_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'target' => 'float'];
    }
}
