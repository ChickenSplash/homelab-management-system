<?php

namespace App\Models\Daily;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Activity extends Model
{
    protected $table = 'daily_activities';

    protected $guarded = ['id'];

    public function rules(): HasMany
    {
        return $this->hasMany(ScheduleRule::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(ActivityDay::class);
    }
}
