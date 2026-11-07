<?php

namespace Tests\Feature;

use App\Events\LiveUpdate;
use App\Models\Block;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MessageEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $them;

    private Conversation $dm;

    private ChatMessage $message;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([LiveUpdate::class]);
        $this->me = User::factory()->guest()->create();
        $this->them = User::factory()->create();
        $id = $this->actingAs($this->me)->postJson('/api/dms', ['id' => $this->them->public_id])->json('id');
        $this->dm = Conversation::findOrFail($id);
        $this->message = $this->dm->messages()->create(['user_id' => $this->me->id, 'body' => 'Original']);
    }

    private function url(?ChatMessage $message = null, ?Conversation $conversation = null): string
    {
        return '/api/conversations/'.($conversation ?? $this->dm)->id.'/messages/'.($message ?? $this->message)->id;
    }

    private function sendReply(int $id, string $nonce = 'reply-1')
    {
        return $this->actingAs($this->them)->postJson('/api/conversations/'.$this->dm->id.'/messages', [
            'body' => 'A reply', 'reply_to_message_id' => $id, 'nonce' => $nonce,
        ]);
    }

    public function test_a_guest_can_edit_their_own_old_message_and_every_member_hears_it(): void
    {
        $created = $this->message->created_at->toIso8601String();
        $this->travel(30)->days();
        $this->actingAs($this->me)->patchJson($this->url(), ['body' => 'Corrected'])
            ->assertOk()->assertJson(['body' => 'Corrected', 'created_at' => $created])->assertJsonMissingPath('author.email');
        $this->assertNotNull($this->message->fresh()->edited_at);
        $this->assertDatabaseCount('chat_messages', 1);
        foreach ([$this->me, $this->them] as $user) {
            Event::assertDispatched(LiveUpdate::class, fn ($e) => $e->name === 'message.updated'
                && $e->reaches($user) && $e->payload['body'] === 'Corrected');
        }
    }

    public function test_unchanged_text_does_not_mark_as_edited_or_broadcast(): void
    {
        $this->patchJson($this->url(), ['body' => 'Original'])->assertOk()->assertJson(['edited_at' => null]);
        Event::assertNotDispatched(LiveUpdate::class);
    }

    public function test_edits_validate_body_and_ignore_other_fields(): void
    {
        foreach (['', '  ', str_repeat('a', 2001)] as $body) {
            $this->patchJson($this->url(), ['body' => $body])->assertUnprocessable()->assertJsonValidationErrors('body');
        }
        $this->patchJson($this->url(), ['body' => str_repeat('a', 2000), 'user_id' => $this->them->id, 'reply_to_message_id' => 999])
            ->assertOk()->assertJson(['author' => ['id' => $this->me->public_id], 'reply_to' => null]);
    }

    public function test_other_members_and_outsiders_cannot_edit_your_message(): void
    {
        $this->actingAs($this->them)->patchJson($this->url(), ['body' => 'No'])->assertForbidden();
        $this->actingAs(User::factory()->create())->patchJson($this->url(), ['body' => 'No'])->assertNotFound();
    }

    public function test_signed_out_requests_cannot_edit_or_reply(): void
    {
        auth()->forgetGuards();
        $this->patchJson($this->url(), ['body' => 'No'])->assertUnauthorized();
        $this->postJson('/api/conversations/'.$this->dm->id.'/messages', [
            'body' => 'No', 'reply_to_message_id' => $this->message->id,
        ])->assertUnauthorized();
    }

    public function test_cross_conversation_and_deleted_messages_are_not_found(): void
    {
        $other = Conversation::create(['type' => 'group', 'name' => 'Other']);
        $other->members()->create(['user_id' => $this->me->id, 'role' => 'owner']);
        $this->patchJson($this->url(conversation: $other), ['body' => 'No'])->assertNotFound();
        $this->message->delete();
        $this->patchJson($this->url(), ['body' => 'No'])->assertNotFound();
    }

    public function test_either_direction_of_block_prevents_editing(): void
    {
        foreach ([[$this->me, $this->them], [$this->them, $this->me]] as [$blocker, $blocked]) {
            $block = Block::create(['blocker_id' => $blocker->id, 'blocked_id' => $blocked->id]);
            $this->patchJson($this->url(), ['body' => 'No'])->assertForbidden();
            $block->delete();
        }
        $this->them->delete();
        $this->actingAs($this->me->fresh())->patchJson($this->url(), ['body' => 'No'])->assertForbidden();
    }

    public function test_group_owners_cannot_edit_others_and_departed_authors_cannot_edit(): void
    {
        $group = Conversation::create(['type' => 'group', 'name' => 'Group']);
        $group->members()->createMany([
            ['user_id' => $this->me->id, 'role' => 'member'], ['user_id' => $this->them->id, 'role' => 'owner'],
        ]);
        $message = $group->messages()->create(['user_id' => $this->me->id, 'body' => 'Mine']);
        $this->actingAs($this->them)->patchJson($this->url($message, $group), ['body' => 'No'])->assertForbidden();
        $this->actingAs($this->me)->patchJson($this->url($message, $group), ['body' => 'Yes'])->assertOk();
        $system = $group->messages()->create(['type' => 'system', 'body' => '', 'user_id' => $this->me->id, 'meta' => ['event' => 'joined']]);
        $this->patchJson($this->url($system, $group), ['body' => 'No'])->assertForbidden();
        $group->members()->where('user_id', $this->me->id)->delete();
        $this->patchJson($this->url($message, $group), ['body' => 'No'])->assertNotFound();
    }

    public function test_edits_do_not_bump_conversations_or_move_read_markers_or_change_unread_counts(): void
    {
        $this->dm->update(['last_message_at' => now()]);
        $before = $this->actingAs($this->them)->getJson('/api/conversations')->json('0');
        $at = $this->dm->fresh()->last_message_at;
        $this->travel(1)->hours();
        $this->actingAs($this->me)->patchJson($this->url(), ['body' => 'Corrected'])->assertOk();
        $after = $this->actingAs($this->them)->getJson('/api/conversations')->json('0');
        $this->assertSame($before['unread_count'], $after['unread_count']);
        $this->assertSame($before['last_read_message_id'], $after['last_read_message_id']);
        $this->assertEquals($at, $this->dm->fresh()->last_message_at);
        $this->assertSame('Corrected', $after['last_message']['body']);
    }

    public function test_a_reply_has_a_shallow_preview_and_current_edits_appear_in_history(): void
    {
        $reply = $this->sendReply($this->message->id)->assertCreated()->assertJson([
            'reply_to' => ['id' => $this->message->id, 'body' => 'Original', 'deleted' => false, 'author' => ['id' => $this->me->public_id]],
        ])->assertJsonMissingPath('reply_to.author.email')->assertJsonMissingPath('reply_to.reply_to')->json('id');
        $this->actingAs($this->me)->patchJson($this->url(), ['body' => 'Updated original'])->assertOk();
        $this->getJson('/api/conversations/'.$this->dm->id.'/messages')->assertJsonPath('data.1.reply_to.body', 'Updated original');
        $this->sendReply($reply, 'nested')->assertCreated()->assertJsonPath('reply_to.body', 'A reply')->assertJsonMissingPath('reply_to.reply_to');
        Event::assertDispatched(LiveUpdate::class, fn ($e) => $e->name === 'message.sent' && ($e->payload['reply_to']['id'] ?? null) === $reply);
    }

    public function test_deleted_originals_show_no_text_or_author_and_nonce_retries_still_work(): void
    {
        $id = $this->sendReply($this->message->id)->assertCreated()->json('id');
        $this->message->delete();
        $this->getJson('/api/conversations/'.$this->dm->id.'/messages')->assertJsonPath('data.0.reply_to', [
            'id' => $this->message->id, 'author' => null, 'body' => null, 'deleted' => true,
        ]);
        $this->sendReply($this->message->id)->assertOk()->assertJson(['id' => $id, 'reply_to' => ['deleted' => true]]);
        $this->assertSame(2, ChatMessage::withTrashed()->count());
    }

    public function test_reply_targets_must_be_live_text_messages_in_this_conversation(): void
    {
        $other = Conversation::create(['type' => 'group', 'name' => 'Elsewhere']);
        $foreign = $other->messages()->create(['user_id' => $this->me->id, 'body' => 'Secret']);
        $system = $this->dm->messages()->create(['type' => 'system', 'body' => '', 'meta' => ['event' => 'joined']]);
        $this->message->delete();
        foreach ([$foreign->id, $system->id, $this->message->id, 99999] as $id) {
            $this->sendReply($id)->assertUnprocessable()->assertJsonValidationErrors('reply_to_message_id');
        }
    }

    public function test_preview_is_limited_and_survives_the_authors_account_being_deleted(): void
    {
        $this->message->update(['body' => str_repeat('é', 300)]);
        $this->sendReply($this->message->id)->assertCreated()->assertJsonPath('reply_to.body', str_repeat('é', 200));
        $this->me->delete();
        $this->actingAs($this->them)->getJson('/api/conversations/'.$this->dm->id.'/messages')
            ->assertJsonPath('data.1.reply_to.author', null)->assertJsonPath('data.1.reply_to.deleted', false);
    }

    public function test_editing_is_rate_limited(): void
    {
        foreach (range(1, 10) as $i) {
            $this->patchJson($this->url(), ['body' => "Edit {$i}"])->assertOk();
        }
        $this->patchJson($this->url(), ['body' => 'Too many'])->assertTooManyRequests();
    }
}
