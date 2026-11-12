<?php

namespace Tests\Feature;

use App\Models\Daily\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
    }

    private function signIn(bool $guest = false): User
    {
        $user = $guest ? User::factory()->guest()->create() : User::factory()->create();
        $this->actingAs($user);
        $this->getJson('/api/daily/bootstrap')->assertOk();

        return $user;
    }

    private function exercise(): int
    {
        return $this->postJson('/api/daily/activities', ['name' => 'Push-ups', 'unit' => 'repetitions'])->assertCreated()->json('id');
    }

    private function routine(int $id, int $target = 20, string $start = '2026-11-10'): void
    {
        $this->putJson("/api/daily/activities/{$id}/schedule", ['starts_on' => $start, 'weekdays' => [2, 3], 'target' => $target])->assertOk();
    }

    public function test_authentication_is_required(): void
    {
        $this->getJson('/api/daily/bootstrap')->assertUnauthorized();
        $this->getJson('/api/daily/export')->assertUnauthorized();
        $this->postJson('/api/daily/activities', [])->assertUnauthorized();
    }

    public function test_guests_get_private_default_journals_only_once(): void
    {
        $this->signIn(true);
        $this->getJson('/api/daily/bootstrap')->assertJsonCount(2, 'journals')->assertJsonPath('timezone', 'Europe/London')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('daily_journals', 2);
    }

    public function test_entries_are_markdown_per_journal_and_day_with_conflict_detection(): void
    {
        $this->signIn();
        $id = Journal::first()->id;
        $url = "/api/daily/journals/{$id}/entries/2026-11-10";
        $this->putJson($url, ['body' => '**Hello**\n- grateful', 'revision' => 0])->assertOk()->assertJsonPath('revision', 1);
        $this->putJson($url, ['body' => 'stale', 'revision' => 0])->assertConflict();
        $this->putJson($url, ['body' => 'Updated', 'revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->putJson("/api/daily/journals/{$id}/entries/2026-11-09", ['body' => 'Yesterday', 'revision' => 0])->assertOk();
        $this->assertDatabaseCount('daily_entries', 2);
        $this->getJson('/api/daily/days/2026-11-10')->assertJsonPath('journals.0.entry.body', 'Updated');
    }

    public function test_all_nested_resources_and_exports_are_owner_scoped(): void
    {
        $this->signIn();
        $journal = Journal::first()->id;
        $activity = $this->exercise();
        $this->putJson("/api/daily/journals/{$journal}/entries/2026-11-10", ['body' => 'Private', 'revision' => 0])->assertOk();
        $this->signIn(true);
        $this->putJson("/api/daily/journals/{$journal}/entries/2026-11-10", ['body' => 'stolen', 'revision' => 1])->assertNotFound();
        $this->patchJson("/api/daily/journals/{$journal}", ['name' => 'stolen'])->assertNotFound();
        $this->deleteJson("/api/daily/journals/{$journal}")->assertNotFound();
        $this->putJson("/api/daily/activities/{$activity}/schedule", [])->assertNotFound();
        $this->putJson("/api/daily/days/2026-11-10/activities/{$activity}", [])->assertNotFound();
        $this->patchJson("/api/daily/activities/{$activity}", ['name' => 'stolen'])->assertNotFound();
        $this->getJson('/api/daily/export')->assertDontSee('Private')->assertJsonCount(0, 'activities');
        $this->getJson('/api/daily/calendar?from=2026-11-10&to=2026-11-10')->assertJsonPath('0.entries', 0);
    }

    public function test_weekdays_are_iso_and_future_days_are_previews_not_stored(): void
    {
        $this->signIn();
        $id = $this->exercise();
        $this->routine($id);
        $this->getJson('/api/daily/days/2026-11-10')->assertJsonPath('activities.0.target', 20)->assertJsonPath('activities.0.preview', false);
        $this->getJson('/api/daily/days/2026-11-11')->assertJsonPath('activities.0.preview', true);
        $this->getJson('/api/daily/days/2026-11-12')->assertJsonCount(0, 'activities');
        $this->getJson('/api/daily/days/2026-11-10')->assertOk();
        $this->assertDatabaseCount('daily_activity_days', 1);
    }

    public function test_schedule_changes_do_not_rewrite_past_or_saved_targets(): void
    {
        $this->signIn();
        $id = $this->exercise();
        $this->routine($id);
        $this->travelTo(now()->addWeek());
        $this->routine($id, 30, '2026-11-17');
        // Last week's target survives even if the page was never visited.
        $this->getJson('/api/daily/days/2026-11-10')->assertJsonPath('activities.0.target', 20);
        $this->getJson('/api/daily/days/2026-11-17')->assertJsonPath('activities.0.target', 30);
        $this->routine($id, 40, '2026-11-17');
        $this->getJson('/api/daily/days/2026-11-17')->assertJsonPath('activities.0.target', 30);
        $this->putJson("/api/daily/activities/{$id}/schedule", ['starts_on' => '2026-11-01', 'weekdays' => [2], 'target' => 50])->assertUnprocessable();
    }

    public function test_daily_totals_complete_skip_undo_and_detect_conflicts(): void
    {
        $this->signIn();
        $id = $this->exercise();
        $url = "/api/daily/days/2026-11-10/activities/{$id}";
        $this->putJson($url, ['target' => 20, 'actual' => 12, 'status' => 'pending', 'revision' => 0])->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('is_one_off', true);
        $this->putJson($url, ['target' => 20, 'actual' => 25, 'status' => 'pending', 'revision' => 1])->assertOk()->assertJsonPath('status', 'completed');
        $this->putJson($url, ['target' => 20, 'actual' => 0, 'status' => 'pending', 'revision' => 1])->assertConflict();
        $this->putJson($url, ['target' => 20, 'actual' => 0, 'status' => 'pending', 'revision' => 2])->assertOk()->assertJsonPath('completed_at', null);
        $this->putJson($url, ['target' => 20, 'actual' => 0, 'status' => 'skipped', 'revision' => 3])->assertOk()->assertJsonPath('status', 'skipped');
    }

    public function test_checkboxes_need_no_target_or_fake_quantity(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/daily/activities', ['name' => 'Read', 'unit' => 'checkbox'])->json('id');
        $this->putJson("/api/daily/days/2026-11-10/activities/{$id}", ['target' => null, 'actual' => 0, 'status' => 'completed', 'revision' => 0])->assertOk()->assertJsonPath('target', null)->assertJsonPath('actual', 0)->assertJsonPath('status', 'completed');
        $this->patchJson("/api/daily/activities/{$id}", ['unit' => 'seconds'])->assertUnprocessable();
    }

    public function test_future_completion_and_invalid_dates_and_ranges_are_rejected(): void
    {
        $this->signIn();
        $id = $this->exercise();
        $this->putJson("/api/daily/days/2026-11-11/activities/{$id}", ['target' => 20, 'actual' => 20, 'status' => 'completed', 'revision' => 0])->assertUnprocessable();
        $this->getJson('/api/daily/days/2026-02-30')->assertUnprocessable();
        $this->getJson('/api/daily/calendar?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
        $this->getJson('/api/daily/calendar?from=2026-11-11&to=2026-11-10')->assertUnprocessable();
        $this->patchJson('/api/daily/settings', ['timezone' => 'Made/Up'])->assertUnprocessable();
    }

    public function test_archiving_keeps_history_and_restoring_works(): void
    {
        $this->signIn();
        $id = $this->exercise();
        $this->routine($id);
        $this->patchJson("/api/daily/activities/{$id}", ['name' => 'New name', 'archived' => true])->assertOk();
        $this->getJson('/api/daily/days/2026-11-10')->assertJsonPath('activities.0.name', 'Push-ups');
        $this->getJson('/api/daily/days/2026-11-11')->assertJsonCount(0, 'activities');
        $this->patchJson("/api/daily/activities/{$id}", ['archived' => false])->assertOk();
        $this->getJson('/api/daily/days/2026-11-11')->assertJsonCount(0, 'activities');
        $this->routine($id, 20, '2026-11-11');
        $this->getJson('/api/daily/days/2026-11-11')->assertJsonPath('activities.0.name', 'New name');
    }

    public function test_calendar_and_export_include_writing_and_actual_progress(): void
    {
        $this->signIn();
        $journal = Journal::first()->id;
        $id = $this->exercise();
        $this->putJson("/api/daily/journals/{$journal}/entries/2026-11-10", ['body' => '# Lovely day', 'revision' => 0]);
        $this->putJson("/api/daily/days/2026-11-10/activities/{$id}", ['target' => 20, 'actual' => 20, 'status' => 'completed', 'revision' => 0]);
        $this->getJson('/api/daily/calendar?from=2026-11-10&to=2026-11-10')->assertJsonPath('0.entries', 1)->assertJsonPath('0.completed', 1)->assertJsonPath('0.total', 1);
        $this->getJson('/api/daily/export')->assertHeader('Content-Disposition', 'attachment; filename="daily-export.json"')->assertJsonPath('journals.0.entries.0.body', '# Lovely day')->assertJsonPath('activities.0.days.0.actual', 20);
    }

    public function test_guest_deletion_cascades_all_daily_data(): void
    {
        $user = $this->signIn(true);
        $journal = Journal::first()->id;
        $id = $this->exercise();
        $this->routine($id);
        $this->getJson('/api/daily/days/2026-11-10');
        $this->putJson("/api/daily/journals/{$journal}/entries/2026-11-10", ['body' => 'Temporary', 'revision' => 0]);
        $user->delete();
        foreach (['settings', 'journals', 'entries', 'activities', 'schedule_rules', 'activity_days'] as $table) {
            $this->assertDatabaseCount('daily_'.$table, 0);
        }
    }

    public function test_archived_journal_entries_remain_editable_but_new_ones_are_rejected(): void
    {
        $this->signIn();
        $id = Journal::first()->id;
        $this->putJson("/api/daily/journals/{$id}/entries/2026-11-10", ['body' => 'First', 'revision' => 0]);
        $this->patchJson("/api/daily/journals/{$id}", ['archived' => true])->assertOk();
        $this->putJson("/api/daily/journals/{$id}/entries/2026-11-10", ['body' => 'Edited', 'revision' => 1])->assertOk();
        $this->putJson("/api/daily/journals/{$id}/entries/2026-11-09", ['body' => 'New', 'revision' => 0])->assertUnprocessable();
        $this->deleteJson("/api/daily/journals/{$id}")->assertNoContent();
        $this->assertDatabaseCount('daily_entries', 0);
    }

    public function test_sync_is_idempotent_and_uses_each_users_timezone(): void
    {
        $user = $this->signIn();
        $id = $this->exercise();
        $this->routine($id);
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(0, 30));
        DB::table('daily_settings')->where('user_id', $user->id)->update(['timezone' => 'America/New_York']);
        $this->getJson('/api/daily/bootstrap')->assertJsonPath('today', '2026-11-10');
        $this->artisan('daily:sync')->assertSuccessful();
        $this->artisan('daily:sync')->assertSuccessful();
        $this->assertDatabaseCount('daily_activity_days', 1);
    }

    public function test_markdown_whitespace_is_preserved_exactly(): void
    {
        $this->signIn();
        $id = Journal::first()->id;
        $body = "    indented code\nA line with a break  \n\n";
        $this->putJson("/api/daily/journals/{$id}/entries/2026-11-10", ['body' => $body, 'revision' => 0])->assertOk()->assertJsonPath('body', $body);
    }
}
