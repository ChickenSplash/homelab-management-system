<?php

namespace Tests\Feature;

use App\Events\LiveUpdate;
use App\Jobs\SyncPresence;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\User;
use App\Support\Presence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Reverb\Events\ChannelCreated;
use Laravel\Reverb\Events\ChannelRemoved;
use Laravel\Reverb\Protocols\Pusher\Channels\Channel;
use Mockery;
use Tests\TestCase;

class PresenceTest extends TestCase
{
    use RefreshDatabase;

    /** Public IDs Reverb reports as connected. */
    private array $connected = [];

    private User $me;

    private User $friend;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([LiveUpdate::class]);
        $test = $this;
        $this->app->instance(Presence::class, new class($test) extends Presence
        {
            public function __construct(private PresenceTest $test) {}

            public function isConnected(string $publicId): bool
            {
                return in_array($publicId, $this->test->connectedIds(), true);
            }

            public function connectedIds(): array
            {
                return $this->test->connectedIds();
            }
        });

        $this->me = User::factory()->create();
        $this->friend = User::factory()->create();
        Friendship::create(['requester_id' => $this->me->id, 'addressee_id' => $this->friend->id, 'accepted_at' => now()]);
    }

    public function connectedIds(): array
    {
        return $this->connected;
    }

    private function connect(User $user): void
    {
        $this->connected[] = $user->public_id;
        SyncPresence::dispatchSync($user->public_id);
        $user->refresh();
    }

    private function disconnect(User $user): void
    {
        $this->connected = array_values(array_diff($this->connected, [$user->public_id]));
        SyncPresence::dispatchSync($user->public_id);
        $user->refresh();
    }

    /** Reverb's channels need its server running, so a stand-in that only knows its name. */
    private function channel(string $name): Channel
    {
        return Mockery::mock(Channel::class, ['name' => $name]);
    }

    private function presenceOf(User $user): string
    {
        return $user->fresh()->presence;
    }

    private function announced(User $about, string $status, User $to): bool
    {
        return Event::dispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->name === 'presence.changed'
            && $e->payload === ['id' => $about->public_id, 'status' => $status] && $e->reaches($to))->isNotEmpty();
    }

    public function test_reverb_channel_events_queue_a_check_and_leaving_waits_a_few_seconds(): void
    {
        Queue::fake();

        event(new ChannelCreated($this->channel("private-user.{$this->me->public_id}")));
        event(new ChannelRemoved($this->channel("private-user.{$this->me->public_id}")));
        event(new ChannelCreated($this->channel('private-something.else')));

        Queue::assertPushed(SyncPresence::class, 2);
        Queue::assertPushed(SyncPresence::class, fn (SyncPresence $job) => $job->publicId === $this->me->public_id && $job->delay === 0);
        Queue::assertPushed(SyncPresence::class, fn (SyncPresence $job) => $job->delay === 5);
    }

    public function test_connecting_shows_you_online_to_friends_and_yourself_and_leaving_shows_you_offline(): void
    {
        $this->connect($this->me);

        $this->assertSame('online', $this->presenceOf($this->me));
        $this->assertTrue($this->announced($this->me, 'online', $this->friend));
        $this->assertTrue($this->announced($this->me, 'online', $this->me));

        $this->disconnect($this->me);

        $this->assertSame('offline', $this->presenceOf($this->me));
        $this->assertTrue($this->announced($this->me, 'offline', $this->friend));
    }

    public function test_a_check_that_finds_you_still_connected_changes_nothing(): void
    {
        $this->connect($this->me);
        Event::fake([LiveUpdate::class]);

        SyncPresence::dispatchSync($this->me->public_id);

        Event::assertNotDispatched(LiveUpdate::class);
    }

    public function test_only_friends_and_people_you_share_a_conversation_with_hear_about_it(): void
    {
        $groupmate = User::factory()->create();
        $dmPartner = User::factory()->create();
        $requested = User::factory()->create();
        $stranger = User::factory()->create();
        $blocked = User::factory()->create();

        $group = Conversation::create(['type' => 'group', 'name' => 'g', 'visibility' => 'public']);
        foreach ([$this->me, $groupmate, $blocked] as $user) {
            $group->members()->create(['user_id' => $user->id, 'role' => 'member']);
        }
        $dm = Conversation::create(['type' => 'dm', 'dm_key' => Conversation::dmKey($this->me, $dmPartner)]);
        foreach ([$this->me, $dmPartner] as $user) {
            $dm->members()->create(['user_id' => $user->id, 'role' => 'member']);
        }
        Friendship::create(['requester_id' => $this->me->id, 'addressee_id' => $requested->id]);
        Block::create(['blocker_id' => $blocked->id, 'blocked_id' => $this->me->id]);

        $this->connect($this->me);

        foreach ([$this->friend, $groupmate, $dmPartner] as $user) {
            $this->assertTrue($this->announced($this->me, 'online', $user));
        }
        foreach ([$requested, $stranger, $blocked] as $user) {
            $this->assertFalse($this->announced($this->me, 'online', $user));
        }
    }

    public function test_the_presence_list_holds_only_people_you_can_see_who_are_not_offline(): void
    {
        $stranger = User::factory()->create();
        $offlineFriend = User::factory()->create();
        Friendship::create(['requester_id' => $offlineFriend->id, 'addressee_id' => $this->me->id, 'accepted_at' => now()]);
        $this->connect($this->me);
        $this->connect($this->friend);
        $this->connect($stranger);

        $this->actingAs($this->me)->getJson('/api/presence')->assertExactJson([
            $this->me->public_id => 'online',
            $this->friend->public_id => 'online',
        ]);

        Block::create(['blocker_id' => $this->me->id, 'blocked_id' => $this->friend->id]);
        $this->actingAs($this->me)->getJson('/api/presence')->assertExactJson([$this->me->public_id => 'online']);
    }

    public function test_an_empty_presence_list_is_still_an_object(): void
    {
        $this->assertSame('{}', $this->actingAs($this->me)->getJson('/api/presence')->getContent());
    }

    public function test_do_not_disturb_idle_and_invisible_are_what_others_see(): void
    {
        $this->connect($this->me);

        $this->actingAs($this->me)->putJson('/api/presence/status', ['status' => 'dnd'])->assertOk()->assertJson(['status' => 'dnd']);
        $this->assertTrue($this->announced($this->me, 'dnd', $this->friend));

        $this->actingAs($this->me)->putJson('/api/presence/status', ['status' => 'idle']);
        $this->assertTrue($this->announced($this->me, 'idle', $this->friend));

        $this->actingAs($this->me)->putJson('/api/presence/status', ['status' => 'invisible']);
        $this->assertTrue($this->announced($this->me, 'offline', $this->friend));
        $this->actingAs($this->friend)->getJson('/api/presence')->assertExactJson([]);

        $this->actingAs($this->me)->putJson('/api/presence/status', ['status' => 'away'])->assertStatus(422);
        $this->actingAs($this->me)->getJson('/api/auth/me')->assertJson(['status' => 'invisible']);
    }

    public function test_your_chosen_status_is_only_shown_to_you(): void
    {
        $this->me->forceFill(['status' => 'invisible'])->save();

        $this->actingAs($this->friend)->getJson('/api/friends')->assertJsonMissingPath('0.user.status');
    }

    public function test_ten_minutes_without_activity_turns_you_idle_and_activity_brings_you_back(): void
    {
        $this->connect($this->me);

        $this->travel(11)->minutes();
        $this->connected = [$this->me->public_id];
        $this->artisan('presence:sync')->assertSuccessful();
        $this->assertSame('idle', $this->presenceOf($this->me));
        $this->assertTrue($this->announced($this->me, 'idle', $this->friend));

        $this->actingAs($this->me->fresh())->postJson('/api/presence/active')->assertNoContent();
        $this->assertSame('online', $this->presenceOf($this->me));
    }

    public function test_the_minutely_sync_fixes_anyone_reverb_disagrees_with(): void
    {
        $this->connect($this->me);

        // Reverb restarted: nobody is connected, and no "left" events were sent.
        $this->connected = [$this->friend->public_id];
        $this->artisan('presence:sync')->assertSuccessful();

        $this->assertSame('offline', $this->presenceOf($this->me));
        $this->assertSame('online', $this->presenceOf($this->friend));
    }

    public function test_activity_while_disconnected_does_not_show_you_online(): void
    {
        $this->actingAs($this->me)->postJson('/api/presence/active')->assertNoContent();

        $this->assertSame('offline', $this->presenceOf($this->me));
    }

    public function test_typing_reaches_the_other_members_but_not_you_or_people_blocked_either_way(): void
    {
        $other = User::factory()->create();
        $blocker = User::factory()->create();
        $group = Conversation::create(['type' => 'group', 'name' => 'g', 'visibility' => 'public']);
        foreach ([$this->me, $this->friend, $other, $blocker] as $user) {
            $group->members()->create(['user_id' => $user->id, 'role' => 'member']);
        }
        Block::create(['blocker_id' => $blocker->id, 'blocked_id' => $this->me->id]);

        $this->actingAs($this->me)->postJson("/api/conversations/{$group->id}/typing")->assertNoContent();

        Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->name === 'typing'
            && $e->payload['conversation_id'] === $group->id
            && $e->payload['user']['id'] === $this->me->public_id
            && $e->reaches($this->friend) && $e->reaches($other) && ! $e->reaches($this->me) && ! $e->reaches($blocker));

        $this->actingAs(User::factory()->create())->postJson("/api/conversations/{$group->id}/typing")->assertNotFound();
    }
}
