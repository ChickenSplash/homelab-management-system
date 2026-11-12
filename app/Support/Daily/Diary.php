<?php

namespace App\Support\Daily;

use App\Models\Daily\Activity;
use App\Models\Daily\ActivityDay;
use App\Models\Daily\Journal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Diary
{
    public function initialise(User $user): object
    {
        return DB::transaction(function () use ($user) {
            $settings = DB::table('daily_settings')->where('user_id', $user->id)->first();
            if (! $settings) {
                DB::table('daily_settings')->insert(['user_id' => $user->id, 'timezone' => 'Europe/London', 'created_at' => now(), 'updated_at' => now()]);
                foreach ([['Daily journal', 'How was your day?'], ['Gratitude journal', 'What are you grateful for?']] as $position => [$name, $prompt]) {
                    Journal::create(['user_id' => $user->id, 'name' => $name, 'prompt' => $prompt, 'position' => $position]);
                }
            }

            return DB::table('daily_settings')->where('user_id', $user->id)->first();
        });
    }

    public function today(User $user): string
    {
        $timezone = DB::table('daily_settings')->where('user_id', $user->id)->value('timezone') ?? 'Europe/London';

        return CarbonImmutable::now($timezone)->toDateString();
    }

    /** Persist elapsed occurrences idempotently; future occurrences are previews only. */
    public function days(User $user, string $from, string $to): Collection
    {
        $today = $this->today($user);
        $activities = Activity::where('user_id', $user->id)->with('rules')->get();
        $saved = ActivityDay::whereIn('activity_id', $activities->pluck('id'))->whereBetween('activity_date', [$from, $to])->get()
            ->keyBy(fn ($day) => $day->activity_id.':'.$day->activity_date);
        $result = collect();
        foreach ($activities as $activity) {
            for ($date = CarbonImmutable::parse($from); $date->toDateString() <= $to; $date = $date->addDay()) {
                $key = $date->toDateString();
                $existing = $saved->get($activity->id.':'.$key);
                if ($existing) {
                    $result->push($existing->toArray() + ['preview' => false]);

                    continue;
                }
                if ($activity->archived_on && $key >= $activity->archived_on) {
                    continue;
                }
                $rule = $activity->rules->first(fn ($rule) => $rule->starts_on <= $key && (! $rule->ends_on || $rule->ends_on >= $key) && in_array($date->isoWeekday(), $rule->weekdays, true));
                if (! $rule) {
                    continue;
                }
                $values = ['name' => $activity->name, 'unit' => $activity->unit, 'target' => $rule->target, 'actual' => 0, 'status' => 'pending', 'is_one_off' => false];
                if ($key <= $today) {
                    $day = ActivityDay::firstOrCreate(['activity_id' => $activity->id, 'activity_date' => $key], $values);
                    $result->push($day->toArray() + ['preview' => false]);
                } else {
                    $result->push($values + ['id' => null, 'activity_id' => $activity->id, 'activity_date' => $key, 'revision' => 0, 'completed_at' => null, 'preview' => true]);
                }
            }
        }

        return $result->sortBy(fn ($day) => $day['name'])->values();
    }

    /** Before renaming or archiving, freeze all earlier targets and labels in bounded chunks. */
    public function freezeHistory(User $user): void
    {
        $first = DB::table('daily_schedule_rules')->whereIn('activity_id', Activity::where('user_id', $user->id)->select('id'))->min('starts_on');
        if (! $first) {
            return;
        }
        $today = $this->today($user);
        for ($from = CarbonImmutable::parse($first); $from->toDateString() <= $today; $from = $from->addDays(90)) {
            $to = min($from->addDays(89)->toDateString(), $today);
            $this->days($user, $from->toDateString(), $to);
        }
    }
}
