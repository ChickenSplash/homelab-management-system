<?php

namespace App\Http\Controllers;

use App\Models\Daily\Activity;
use App\Models\Daily\Entry;
use App\Models\Daily\Journal;
use App\Support\Daily\Diary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DailyController
{
    public function __construct(private Diary $diary) {}

    private function date(string $date): string
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31']])->validate();

        return $date;
    }

    private function journal(Request $request, int $id): Journal
    {
        return Journal::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function activity(Request $request, int $id): Activity
    {
        return Activity::where('user_id', $request->user()->id)->findOrFail($id);
    }

    public function bootstrap(Request $request)
    {
        $settings = $this->diary->initialise($request->user());

        return [
            'timezone' => $settings->timezone,
            'today' => $this->diary->today($request->user()),
            'journals' => Journal::where('user_id', $request->user()->id)->orderBy('position')->orderBy('id')->get(),
            'activities' => Activity::where('user_id', $request->user()->id)->with('rules')->orderBy('name')->get(),
        ];
    }

    public function settings(Request $request)
    {
        $data = $request->validate(['timezone' => ['required', 'timezone:all']]);
        $this->diary->initialise($request->user());
        DB::transaction(function () use ($request, $data) {
            $this->diary->freezeHistory($request->user());
            DB::table('daily_settings')->where('user_id', $request->user()->id)->update($data + ['updated_at' => now()]);
        });

        return $this->bootstrap($request);
    }

    public function createJournal(Request $request)
    {
        $this->diary->initialise($request->user());
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'prompt' => ['sometimes', 'nullable', 'string', 'max:300']]);
        $data['prompt'] = $data['prompt'] ?? '';
        $journal = Journal::create($data + ['user_id' => $request->user()->id, 'position' => Journal::where('user_id', $request->user()->id)->count()]);

        return response()->json($journal, 201);
    }

    public function updateJournal(Request $request, int $id)
    {
        $journal = $this->journal($request, $id);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:100'], 'prompt' => ['sometimes', 'nullable', 'string', 'max:300'], 'position' => ['sometimes', 'integer', 'min:0', 'max:1000'], 'archived' => ['sometimes', 'boolean']]);
        if (array_key_exists('archived', $data)) {
            $data['archived_at'] = $data['archived'] ? now() : null;
            unset($data['archived']);
        }
        if (array_key_exists('prompt', $data)) {
            $data['prompt'] ??= '';
        }
        $journal->update($data);

        return $journal;
    }

    public function deleteJournal(Request $request, int $id)
    {
        $this->journal($request, $id)->delete();

        return response()->noContent();
    }

    public function saveEntry(Request $request, int $id, string $date)
    {
        $date = $this->date($date);
        $journal = $this->journal($request, $id);
        $data = $request->validate(['body' => ['present', 'nullable', 'string', 'max:100000'], 'revision' => ['required', 'integer', 'min:0']]);

        return DB::transaction(function () use ($journal, $date, $data) {
            $entry = $journal->entries()->where('entry_date', $date)->first();
            abort_if(($entry?->revision ?? 0) !== $data['revision'], 409, 'This entry changed in another tab. Your draft has not been overwritten.');
            abort_if($journal->archived_at && ! $entry, 422, 'Restore this journal before adding entries.');
            $values = ['body' => $data['body'] ?? '', 'revision' => $data['revision'] + 1];
            if ($entry) {
                $entry->update($values);
            } else {
                $entry = $journal->entries()->create($values + ['entry_date' => $date]);
            }

            return response()->json($entry);
        });
    }

    public function createActivity(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'unit' => ['required', Rule::in(['repetitions', 'metres', 'seconds', 'checkbox'])]]);

        return response()->json(Activity::create($data + ['user_id' => $request->user()->id]), 201);
    }

    public function updateActivity(Request $request, int $id)
    {
        $activity = $this->activity($request, $id);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:100'], 'archived' => ['sometimes', 'boolean'], 'unit' => ['prohibited']]);

        return DB::transaction(function () use ($request, $activity, $data) {
            $this->diary->freezeHistory($request->user());
            if (array_key_exists('archived', $data)) {
                $data['archived_on'] = $data['archived'] ? CarbonImmutable::parse($this->diary->today($request->user()))->addDay()->toDateString() : null;
                if ($data['archived']) {
                    // End the routine too: restoring must not invent activities in the archived gap.
                    $activity->rules()->where('starts_on', '>=', $data['archived_on'])->delete();
                    $activity->rules()->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $data['archived_on']))
                        ->update(['ends_on' => CarbonImmutable::parse($data['archived_on'])->subDay()->toDateString()]);
                }
                unset($data['archived']);
            }
            $activity->update($data);

            return $activity;
        });
    }

    public function deleteActivity(Request $request, int $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $activity = $this->activity($request, $id);
            abort_unless($activity->archived_on, 422, 'Archive this activity before deleting it.');
            $activity->delete();

            return response()->noContent();
        });
    }

    public function schedule(Request $request, int $id)
    {
        $activity = $this->activity($request, $id);
        $today = $this->diary->today($request->user());
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before_or_equal:2100-12-31'],
            'weekdays' => ['present', 'array', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'target' => [$activity->unit === 'checkbox' ? 'nullable' : 'required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,2'],
        ]);
        if ($activity->unit === 'checkbox') {
            $data['target'] = null;
        }
        abort_if($activity->archived_on, 422, 'Restore this activity before changing its schedule.');

        return DB::transaction(function () use ($activity, $data) {
            // Replacement applies from this date, including any previously planned future changes.
            $activity->rules()->where('starts_on', '>=', $data['starts_on'])->delete();
            $activity->rules()->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $data['starts_on']))
                ->update(['ends_on' => CarbonImmutable::parse($data['starts_on'])->subDay()->toDateString()]);
            if ($data['weekdays']) {
                $activity->rules()->create($data);
            }

            return $activity->load('rules');
        });
    }

    public function saveActivityDay(Request $request, string $date, int $id)
    {
        $date = $this->date($date);
        $activity = $this->activity($request, $id);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'target' => [$activity->unit === 'checkbox' ? 'nullable' : 'required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,2'],
            'actual' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'],
            'status' => ['required', Rule::in(['pending', 'completed', 'skipped'])],
        ]);

        return DB::transaction(function () use ($activity, $date, $data) {
            $day = $activity->days()->where('activity_date', $date)->first();
            abort_if(($day?->revision ?? 0) !== $data['revision'], 409, 'This activity changed in another tab. Refresh before trying again.');
            abort_if(! $day && $activity->archived_on && $date >= $activity->archived_on, 422, 'Restore this activity before adding it.');
            $rule = $activity->rules()->where('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))->get()
                ->first(fn ($rule) => in_array(CarbonImmutable::parse($date)->isoWeekday(), $rule->weekdays, true));
            if ($activity->unit === 'checkbox') {
                $data['target'] = null;
                $data['actual'] = 0;
            } elseif ($data['status'] !== 'skipped') {
                $data['status'] = $data['actual'] >= $data['target'] ? 'completed' : 'pending';
            }
            $data['revision']++;
            $data['completed_at'] = $data['status'] === 'completed' ? ($day?->completed_at ?? now()) : null;
            if ($day) {
                $day->update($data);
            } else {
                $day = $activity->days()->create($data + ['activity_date' => $date, 'name' => $activity->name, 'unit' => $activity->unit, 'is_one_off' => ! $rule]);
            }

            return response()->json($day);
        });
    }

    public function day(Request $request, string $date)
    {
        $date = $this->date($date);
        $journals = Journal::where('user_id', $request->user()->id)->with(['entries' => fn ($q) => $q->where('entry_date', $date)])->orderBy('position')->orderBy('id')->get();

        return [
            'date' => $date,
            'journals' => $journals->filter(fn ($journal) => ! $journal->archived_at || $journal->entries->isNotEmpty())->map(function ($journal) {
                $entry = $journal->entries->first();
                $result = $journal->toArray();
                unset($result['entries']);

                return $result + ['entry' => $entry];
            })->values(),
            'activities' => $this->diary->days($request->user(), $date, $date),
        ];
    }

    public function calendar(Request $request)
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31']]);
        abort_if(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 92, 422, 'Request at most 93 days.');
        $activities = $this->diary->days($request->user(), $data['from'], $data['to'])->groupBy('activity_date');
        $entries = Entry::whereIn('journal_id', Journal::where('user_id', $request->user()->id)->select('id'))->whereBetween('entry_date', [$data['from'], $data['to']])->whereRaw("trim(body) != ''")
            ->selectRaw('entry_date, count(*) as total')->groupBy('entry_date')->pluck('total', 'entry_date');
        $result = [];
        for ($date = CarbonImmutable::parse($data['from']); $date->toDateString() <= $data['to']; $date = $date->addDay()) {
            $key = $date->toDateString();
            $items = $activities->get($key, collect());
            $result[] = ['date' => $key, 'entries' => (int) ($entries[$key] ?? 0), 'total' => $items->count(), 'completed' => $items->where('status', 'completed')->count(), 'skipped' => $items->where('status', 'skipped')->count()];
        }

        return $result;
    }

    public function export(Request $request)
    {
        $this->diary->freezeHistory($request->user());

        return response()->json([
            'format_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'timezone' => DB::table('daily_settings')->where('user_id', $request->user()->id)->value('timezone') ?? 'Europe/London',
            'journals' => Journal::where('user_id', $request->user()->id)->with('entries')->get(),
            'activities' => Activity::where('user_id', $request->user()->id)->with(['rules', 'days'])->get(),
        ])->header('Content-Disposition', 'attachment; filename="daily-export.json"');
    }
}
