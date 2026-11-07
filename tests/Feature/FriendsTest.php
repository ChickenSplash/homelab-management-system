<?php

namespace Tests\Feature;

use App\Events\LiveUpdate;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FriendsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $them;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([LiveUpdate::class]);
        $this->me = User::factory()->create();
        $this->them = User::factory()->create();
    }

    private function told(User $user): bool
    {
        return Event::dispatched(LiveUpdate::class,
            fn (LiveUpdate $e) => $e->recipient === $user->public_id && $e->name === 'friends.changed')->isNotEmpty();
    }

    private function friendsOf(User $user): array
    {
        return $this->actingAs($user)->getJson('/api/friends')->assertOk()->json();
    }

    public function test_a_request_by_id_shows_up_on_both_sides_and_tells_both(): void
    {
        $this->actingAs($this->me)->postJson('/api/friends', ['id' => $this->them->public_id])
            ->assertCreated()
            ->assertJson(['status' => 'outgoing', 'user' => ['id' => $this->them->public_id]])
            ->assertJsonMissingPath('user.email');

        $this->assertSame('outgoing', $this->friendsOf($this->me)[0]['status']);
        $this->assertSame('incoming', $this->friendsOf($this->them)[0]['status']);
        $this->assertTrue($this->told($this->me));
        $this->assertTrue($this->told($this->them));
    }

    public function test_ids_are_forgiving_about_case_and_a_leading_hash(): void
    {
        $this->actingAs($this->me)->postJson('/api/friends', ['id' => ' #'.strtoupper($this->them->public_id)])
            ->assertCreated();
    }

    public function test_unknown_ids_and_your_own_are_refused_under_the_field(): void
    {
        $this->actingAs($this->me)->postJson('/api/friends', ['id' => 'nobody99'])
            ->assertStatus(422)->assertJsonValidationErrors(['id' => 'No one has that ID.']);

        $this->actingAs($this->me)->postJson('/api/friends', ['id' => $this->me->public_id])
            ->assertStatus(422)->assertJsonValidationErrors(['id' => 'your own ID']);
    }

    public function test_asking_again_changes_nothing(): void
    {
        $this->actingAs($this->me)->postJson('/api/friends', ['id' => $this->them->public_id])->assertCreated();
        $this->actingAs($this->me)->postJson('/api/friends', ['id' => $this->them->public_id])
            ->assertOk()->assertJson(['status' => 'outgoing']);

        $this->assertDatabaseCount('friendships', 1);
    }

    public function test_asking_someone_who_already_asked_you_makes_you_friends(): void
    {
        Friendship::create(['requester_id' => $this->them->id, 'addressee_id' => $this->me->id]);

        $this->actingAs($this->me)->postJson('/api/friends', ['id' => $this->them->public_id])
            ->assertOk()->assertJson(['status' => 'friend']);

        $this->assertSame('friend', $this->friendsOf($this->them)[0]['status']);
        $this->assertDatabaseCount('friendships', 1);
    }

    public function test_only_the_addressee_can_accept(): void
    {
        Friendship::create(['requester_id' => $this->me->id, 'addressee_id' => $this->them->id]);

        $this->actingAs($this->me)->postJson("/api/friends/{$this->them->public_id}/accept")->assertNotFound();

        $this->actingAs($this->them)->postJson("/api/friends/{$this->me->public_id}/accept")
            ->assertOk()->assertJson(['status' => 'friend']);
        $this->assertTrue($this->told($this->me));
    }

    public function test_declining_cancelling_and_unfriending_all_remove_the_pair(): void
    {
        foreach ([[$this->them, $this->me, null], [$this->me, $this->me, null], [$this->me, $this->them, now()]] as [$from, $remover, $accepted]) {
            $to = $from->is($this->me) ? $this->them : $this->me;
            Friendship::create(['requester_id' => $from->id, 'addressee_id' => $to->id, 'accepted_at' => $accepted]);
            $other = $remover->is($this->me) ? $this->them : $this->me;

            $this->actingAs($remover)->deleteJson("/api/friends/{$other->public_id}")->assertNoContent();
            $this->assertDatabaseCount('friendships', 0);
        }

        $this->actingAs($this->me)->deleteJson("/api/friends/{$this->them->public_id}")->assertNotFound();
    }

    public function test_blocking_ends_the_friendship_and_lists_the_block(): void
    {
        Friendship::create(['requester_id' => $this->me->id, 'addressee_id' => $this->them->id, 'accepted_at' => now()]);

        $this->actingAs($this->me)->putJson("/api/blocks/{$this->them->public_id}")->assertNoContent();
        $this->actingAs($this->me)->putJson("/api/blocks/{$this->them->public_id}")->assertNoContent();

        $this->assertDatabaseCount('friendships', 0);
        $this->assertSame([$this->them->public_id], array_column($this->actingAs($this->me)->getJson('/api/blocks')->json(), 'id'));
        $this->assertTrue($this->told($this->them));
    }

    public function test_a_blocked_persons_request_looks_sent_but_never_arrives(): void
    {
        $this->actingAs($this->me)->putJson("/api/blocks/{$this->them->public_id}");
        Event::fake([LiveUpdate::class]);

        $this->actingAs($this->them)->postJson('/api/friends', ['id' => $this->me->public_id])
            ->assertCreated()->assertJson(['status' => 'outgoing']);

        $this->assertSame('outgoing', $this->friendsOf($this->them)[0]['status']);
        $this->assertSame([], $this->friendsOf($this->me));
        $this->assertFalse($this->told($this->me));
    }

    public function test_you_cannot_befriend_someone_you_blocked_until_you_unblock_them(): void
    {
        $this->actingAs($this->me)->putJson("/api/blocks/{$this->them->public_id}");

        $this->actingAs($this->me)->postJson('/api/friends', ['id' => $this->them->public_id])
            ->assertStatus(422)->assertJsonValidationErrors('id');

        $this->actingAs($this->me)->deleteJson("/api/blocks/{$this->them->public_id}")->assertNoContent();
        $this->actingAs($this->me)->postJson('/api/friends', ['id' => $this->them->public_id])->assertCreated();
    }

    public function test_you_cannot_block_yourself(): void
    {
        $this->actingAs($this->me)->putJson("/api/blocks/{$this->me->public_id}")->assertStatus(422);
    }

    public function test_a_guests_friendships_go_when_they_log_out_and_their_friends_hear_of_it(): void
    {
        $guest = User::factory()->guest()->create();
        Friendship::create(['requester_id' => $guest->id, 'addressee_id' => $this->me->id, 'accepted_at' => now()]);

        $this->actingAs($guest)->fromSpa()->postJson('/api/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('friendships', 0);
        $this->assertTrue($this->told($this->me));
    }

    public function test_friends_need_a_signed_in_user(): void
    {
        $this->getJson('/api/friends')->assertUnauthorized();
        $this->postJson('/api/friends', ['id' => $this->them->public_id])->assertUnauthorized();
    }
}
