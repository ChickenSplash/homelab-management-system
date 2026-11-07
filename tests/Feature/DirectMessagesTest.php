<?php

namespace Tests\Feature;

use App\Events\LiveUpdate;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DirectMessagesTest extends TestCase
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

    private function openDm(?User $from = null, ?User $to = null): int
    {
        return $this->actingAs($from ?? $this->me)
            ->postJson('/api/dms', ['id' => ($to ?? $this->them)->public_id])
            ->json('id');
    }

    private function send(int $dm, string $body, ?User $as = null, ?string $nonce = null): TestResponse
    {
        return $this->actingAs($as ?? $this->me)
            ->postJson("/api/conversations/{$dm}/messages", ['body' => $body, 'nonce' => $nonce]);
    }

    private function conversationsOf(User $user): array
    {
        return $this->actingAs($user)->getJson('/api/conversations')->assertOk()->json();
    }

    public function test_anyone_can_open_a_dm_by_id_and_there_is_only_ever_one_per_pair(): void
    {
        $response = $this->actingAs($this->me)->postJson('/api/dms', ['id' => $this->them->public_id])
            ->assertCreated()
            ->assertJson(['type' => 'dm', 'user' => ['id' => $this->them->public_id], 'unread_count' => 0])
            ->assertJsonMissingPath('user.email');

        $this->actingAs($this->them)->postJson('/api/dms', ['id' => $this->me->public_id])
            ->assertOk()->assertJson(['id' => $response->json('id')]);
    }

    public function test_you_cannot_dm_yourself_or_a_missing_id(): void
    {
        $this->actingAs($this->me)->postJson('/api/dms', ['id' => $this->me->public_id])->assertStatus(422);
        $this->actingAs($this->me)->postJson('/api/dms', ['id' => 'nobody99'])->assertStatus(422);
    }

    public function test_an_empty_dm_only_shows_to_whoever_opened_it(): void
    {
        $dm = $this->openDm();

        $this->assertSame([$dm], array_column($this->conversationsOf($this->me), 'id'));
        $this->assertSame([], $this->conversationsOf($this->them));

        $this->send($dm, 'hello')->assertCreated();
        $this->assertSame([$dm], array_column($this->conversationsOf($this->them), 'id'));
    }

    public function test_a_message_is_saved_returned_and_pushed_to_both_people(): void
    {
        $dm = $this->openDm();

        $message = $this->send($dm, 'Hi there', nonce: 'tmp-1')
            ->assertCreated()
            ->assertJson(['body' => 'Hi there', 'nonce' => 'tmp-1', 'conversation_id' => $dm, 'author' => ['id' => $this->me->public_id]])
            ->assertJsonMissingPath('author.email')
            ->json();

        foreach ([$this->me, $this->them] as $user) {
            Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->recipient === $user->public_id
                && $e->name === 'message.sent' && $e->payload['id'] === $message['id'] && ! isset($e->payload['author']['email']));
        }
    }

    public function test_a_retried_send_with_the_same_nonce_does_not_post_twice(): void
    {
        $dm = $this->openDm();

        $first = $this->send($dm, 'once', nonce: 'abc')->assertCreated()->json('id');
        $this->send($dm, 'once', nonce: 'abc')->assertOk()->assertJson(['id' => $first]);

        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_messages_are_checked(): void
    {
        $dm = $this->openDm();

        foreach (['', '   ', str_repeat('a', 2001)] as $body) {
            $this->send($dm, $body)->assertStatus(422)->assertJsonValidationErrors('body');
        }

        $this->send($dm, str_repeat('a', 2000))->assertCreated();
    }

    public function test_outsiders_cannot_read_send_or_see_a_dm(): void
    {
        $dm = $this->openDm();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson("/api/conversations/{$dm}")->assertNotFound();
        $this->actingAs($outsider)->getJson("/api/conversations/{$dm}/messages")->assertNotFound();
        $this->send($dm, 'let me in', as: $outsider)->assertNotFound();
    }

    public function test_history_pages_backwards_oldest_first(): void
    {
        $dm = $this->openDm();
        foreach (range(1, 60) as $i) {
            ChatMessage::create(['conversation_id' => $dm, 'user_id' => $this->me->id, 'body' => "message {$i}"]);
        }

        $latest = $this->actingAs($this->them)->getJson("/api/conversations/{$dm}/messages")->assertOk();
        $this->assertTrue($latest->json('has_more'));
        $this->assertCount(50, $latest->json('data'));
        $this->assertSame('message 11', $latest->json('data.0.body'));
        $this->assertSame('message 60', $latest->json('data.49.body'));

        $older = $this->actingAs($this->them)->getJson("/api/conversations/{$dm}/messages?before=".$latest->json('data.0.id'));
        $this->assertFalse($older->json('has_more'));
        $this->assertSame(['message 1', 'message 10'], [$older->json('data.0.body'), $older->json('data.9.body')]);
    }

    public function test_unread_counts_follow_the_read_marker(): void
    {
        $dm = $this->openDm();
        $ids = collect(range(1, 3))->map(fn ($i) => $this->send($dm, "m{$i}")->json('id'));

        $this->assertSame(0, $this->conversationsOf($this->me)[0]['unread_count']);
        $this->assertSame(3, $this->conversationsOf($this->them)[0]['unread_count']);

        $this->actingAs($this->them)->postJson("/api/conversations/{$dm}/read", ['message_id' => $ids[1]])->assertNoContent();
        $this->assertSame(1, $this->conversationsOf($this->them)[0]['unread_count']);
        Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->recipient === $this->them->public_id && $e->name === 'conversation.read');

        // Never backwards, and never past the last message.
        $this->actingAs($this->them)->postJson("/api/conversations/{$dm}/read", ['message_id' => $ids[0]]);
        $this->assertSame(1, $this->conversationsOf($this->them)[0]['unread_count']);
        $this->actingAs($this->them)->postJson("/api/conversations/{$dm}/read", ['message_id' => 999999]);
        $this->assertSame($ids[2], $this->conversationsOf($this->them)[0]['last_read_message_id']);
    }

    public function test_the_list_is_sorted_by_latest_message_and_shows_it(): void
    {
        $other = User::factory()->create();
        $first = $this->openDm();
        $second = $this->openDm(to: $other);

        $this->travel(1)->minutes();
        $this->send($first, 'newest');

        $list = $this->conversationsOf($this->me);
        $this->assertSame([$first, $second], array_column($list, 'id'));
        $this->assertSame('newest', $list[0]['last_message']['body']);
    }

    public function test_you_can_delete_only_your_own_messages_and_both_sides_hear_of_it(): void
    {
        $dm = $this->openDm();
        $mine = $this->send($dm, 'oops')->json('id');
        $theirs = $this->send($dm, 'keep', as: $this->them)->json('id');

        $this->actingAs($this->me)->deleteJson("/api/conversations/{$dm}/messages/{$theirs}")->assertForbidden();
        $this->actingAs($this->me)->deleteJson("/api/conversations/{$dm}/messages/{$mine}")->assertNoContent();

        $this->assertSame(['keep'], array_column($this->actingAs($this->them)->getJson("/api/conversations/{$dm}/messages")->json('data'), 'body'));
        $this->assertSame('keep', $this->conversationsOf($this->them)[0]['last_message']['body']);
        Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->recipient === $this->them->public_id && $e->name === 'message.deleted' && $e->payload['id'] === $mine);
    }

    public function test_a_message_id_from_another_conversation_is_not_found(): void
    {
        $dm = $this->openDm();
        $elsewhere = $this->openDm(to: User::factory()->create());
        $message = $this->send($elsewhere, 'hi')->json('id');

        $this->actingAs($this->me)->deleteJson("/api/conversations/{$dm}/messages/{$message}")->assertNotFound();
    }

    public function test_a_block_either_way_stops_messages_both_ways(): void
    {
        $dm = $this->openDm();
        $this->actingAs($this->them)->putJson("/api/blocks/{$this->me->public_id}");

        $this->send($dm, 'hello?')->assertForbidden()->assertJson(['message' => "You can't message this user."]);
        $this->send($dm, 'hello?', as: $this->them)->assertForbidden();
        $this->assertTrue($this->conversationsOf($this->me)[0]['blocked']);
    }

    public function test_a_departed_guests_messages_stay_from_a_deleted_user(): void
    {
        $guest = User::factory()->guest()->create();
        $dm = $this->openDm(from: $guest, to: $this->me);
        $this->send($dm, 'bye', as: $guest);

        $this->actingAs($guest)->fromSpa()->postJson('/api/auth/logout')->assertNoContent();

        $list = $this->conversationsOf($this->me);
        $this->assertNull($list[0]['user']);
        $this->assertNull($this->actingAs($this->me)->getJson("/api/conversations/{$dm}/messages")->json('data.0.author'));
        $this->send($dm, 'hello?')->assertForbidden();
        Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->recipient === $this->me->public_id && $e->name === 'conversations.changed');
    }

    public function test_a_dm_with_nobody_left_is_removed(): void
    {
        $a = User::factory()->guest()->create();
        $b = User::factory()->guest()->create();
        $dm = $this->openDm(from: $a, to: $b);
        $this->send($dm, 'hi', as: $a);

        $a->delete();
        $b->delete();

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_sending_is_rate_limited(): void
    {
        $dm = $this->openDm();

        foreach (range(1, 10) as $i) {
            $this->send($dm, "spam {$i}")->assertCreated();
        }

        $this->send($dm, 'one too many')->assertTooManyRequests();
        $this->assertSame(10, ChatMessage::count());
    }
}
