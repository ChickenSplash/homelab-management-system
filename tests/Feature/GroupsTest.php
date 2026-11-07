<?php

namespace Tests\Feature;

use App\Events\LiveUpdate;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GroupsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([LiveUpdate::class]);
        $this->owner = User::factory()->create();
    }

    private function createGroup(array $overrides = [], ?User $as = null): int
    {
        return $this->actingAs($as ?? $this->owner)->postJson('/api/groups', [
            'name' => 'Book club',
            'visibility' => 'public',
            ...$overrides,
        ])->assertCreated()->json('id');
    }

    /** A group with the owner plus one member of each given role, joined in that order. */
    private function groupWith(string ...$roles): array
    {
        $group = $this->createGroup();
        $users = [];

        foreach ($roles as $role) {
            $user = User::factory()->create();
            ConversationMember::create(['conversation_id' => $group, 'user_id' => $user->id, 'role' => $role]);
            $users[] = $user;
        }

        return [$group, ...$users];
    }

    private function roleOf(int $group, User $user): ?string
    {
        return ConversationMember::where(['conversation_id' => $group, 'user_id' => $user->id])->value('role');
    }

    private function systemEvents(int $group): array
    {
        return ChatMessage::where(['conversation_id' => $group, 'type' => 'system'])->orderBy('id')->get()
            ->map(fn (ChatMessage $m) => $m->meta['event'])->all();
    }

    public function test_the_creator_owns_a_new_group_and_it_shows_in_their_list(): void
    {
        $this->actingAs($this->owner)->postJson('/api/groups', ['name' => ' Book club ', 'description' => 'Monthly reads', 'visibility' => 'private'])
            ->assertCreated()
            ->assertJson(['type' => 'group', 'name' => 'Book club', 'description' => 'Monthly reads', 'visibility' => 'private', 'role' => 'owner', 'member_count' => 1])
            ->assertJsonPath('invite_code', fn ($code) => strlen($code) === 8);

        $this->assertSame(['Book club'], array_column($this->actingAs($this->owner)->getJson('/api/conversations')->json(), 'name'));
    }

    public function test_group_details_are_checked(): void
    {
        foreach ([['name' => ''], ['name' => '   '], ['name' => str_repeat('a', 51)], ['visibility' => 'secret'], ['description' => str_repeat('a', 201)]] as $bad) {
            $this->actingAs($this->owner)->postJson('/api/groups', ['name' => 'Ok', 'visibility' => 'public', ...$bad])->assertStatus(422);
        }
    }

    public function test_guests_can_create_groups_but_nobody_can_own_more_than_ten(): void
    {
        $guest = User::factory()->guest()->create();

        foreach (range(1, 10) as $i) {
            Conversation::create(['type' => 'group', 'name' => "g{$i}", 'visibility' => 'public'])
                ->members()->create(['user_id' => $guest->id, 'role' => 'owner']);
        }

        $this->actingAs($guest)->postJson('/api/groups', ['name' => 'One more', 'visibility' => 'public'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_public_groups_can_be_browsed_and_joined_but_private_ones_cannot(): void
    {
        $public = $this->createGroup(['name' => 'Cyclists']);
        $private = $this->createGroup(['name' => 'Secret cyclists', 'visibility' => 'private']);
        $me = User::factory()->create();

        $browse = $this->actingAs($me)->getJson('/api/groups?q=cycl')->assertOk()->json();
        $this->assertSame([['id' => $public, 'name' => 'Cyclists', 'description' => null, 'visibility' => 'public', 'member_count' => 1, 'joined' => false]], $browse);

        $this->actingAs($me)->postJson("/api/groups/{$private}/join")->assertNotFound();
        $this->actingAs($me)->postJson("/api/groups/{$public}/join")->assertCreated()->assertJson(['role' => 'member', 'member_count' => 2]);
        $this->actingAs($me)->postJson("/api/groups/{$public}/join")->assertOk();

        $this->assertSame(['joined'], $this->systemEvents($public));
        Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->reaches($this->owner) && $e->name === 'message.sent' && $e->payload['meta']['event'] === 'joined');
    }

    public function test_an_invite_link_shows_the_group_and_joins_it_even_when_private(): void
    {
        $group = $this->createGroup(['name' => 'Hidden', 'visibility' => 'private']);
        $code = Conversation::find($group)->invite_code;
        $me = User::factory()->create();

        $this->actingAs($me)->getJson("/api/invites/{$code}")->assertOk()
            ->assertJson(['id' => $group, 'name' => 'Hidden', 'member_count' => 1, 'joined' => false])
            ->assertJsonMissingPath('invite_code');
        $this->actingAs($me)->postJson('/api/invites/'.strtoupper($code))->assertCreated();
        $this->actingAs($me)->getJson('/api/invites/nope1234')->assertNotFound();
    }

    public function test_any_member_sees_the_invite_but_only_admins_can_reset_it(): void
    {
        [$group, $admin, $mod] = $this->groupWith('admin', 'mod');
        $old = $this->actingAs($mod)->getJson("/api/conversations/{$group}")->json('invite_code');
        $this->assertNotNull($old);

        $this->actingAs($mod)->postJson("/api/groups/{$group}/invite")->assertForbidden();
        $new = $this->actingAs($admin)->postJson("/api/groups/{$group}/invite")->assertOk()->json('invite_code');

        $this->assertNotSame($old, $new);
        $this->actingAs(User::factory()->create())->getJson("/api/invites/{$old}")->assertNotFound();
    }

    public function test_outsiders_cannot_see_or_use_a_group(): void
    {
        $group = $this->createGroup(['visibility' => 'private']);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson("/api/conversations/{$group}")->assertNotFound();
        $this->actingAs($outsider)->getJson("/api/conversations/{$group}/messages")->assertNotFound();
        $this->actingAs($outsider)->getJson("/api/groups/{$group}/members")->assertNotFound();
        $this->actingAs($outsider)->postJson("/api/conversations/{$group}/messages", ['body' => 'hi'])->assertNotFound();
    }

    public function test_members_can_chat_and_everyone_hears_it(): void
    {
        [$group, $member] = $this->groupWith('member');

        $id = $this->actingAs($member)->postJson("/api/conversations/{$group}/messages", ['body' => 'Hello all'])
            ->assertCreated()->assertJson(['type' => 'text'])->json('id');

        Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->name === 'message.sent'
            && $e->payload['id'] === $id && $e->reaches($this->owner) && $e->reaches($member));
        $this->assertSame(1, collect($this->actingAs($this->owner)->getJson('/api/conversations')->json())->firstWhere('id', $group)['unread_count']);
    }

    public function test_system_messages_do_not_count_as_unread_and_cannot_be_deleted(): void
    {
        $group = $this->createGroup();
        $this->actingAs(User::factory()->create())->postJson("/api/groups/{$group}/join");

        $this->assertSame(0, $this->actingAs($this->owner)->getJson("/api/conversations/{$group}")->json('unread_count'));

        $system = ChatMessage::where('type', 'system')->value('id');
        $this->actingAs($this->owner)->deleteJson("/api/conversations/{$group}/messages/{$system}")->assertForbidden();
    }

    public function test_only_admins_and_the_owner_can_edit_the_group(): void
    {
        [$group, $admin, $mod] = $this->groupWith('admin', 'mod');

        $this->actingAs($mod)->patchJson("/api/groups/{$group}", ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($admin)->patchJson("/api/groups/{$group}", ['name' => 'Renamed', 'description' => '', 'visibility' => 'private'])
            ->assertOk()->assertJson(['name' => 'Renamed', 'description' => null, 'visibility' => 'private']);
        Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->name === 'conversations.changed' && $e->reaches($mod));
    }

    public function test_only_the_owner_can_delete_the_group(): void
    {
        [$group, $admin] = $this->groupWith('admin');

        $this->actingAs($admin)->deleteJson("/api/groups/{$group}")->assertForbidden();
        $this->actingAs($this->owner)->deleteJson("/api/groups/{$group}")->assertNoContent();

        $this->assertDatabaseCount('conversations', 0);
        Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->name === 'conversations.changed' && $e->reaches($admin));
    }

    public function test_the_member_list_runs_from_highest_rank_then_join_order(): void
    {
        [$group, $m1, $admin, $m2, $mod] = $this->groupWith('member', 'admin', 'member', 'mod');

        $list = $this->actingAs($m1)->getJson("/api/groups/{$group}/members")->assertOk()->json();

        $this->assertSame(
            [$this->owner->public_id, $admin->public_id, $mod->public_id, $m1->public_id, $m2->public_id],
            array_column(array_column($list, 'user'), 'id'),
        );
        $this->assertSame(['owner', 'admin', 'mod', 'member', 'member'], array_column($list, 'role'));
    }

    public function test_admins_give_roles_up_to_admin_but_never_owner(): void
    {
        [$group, $admin, $member] = $this->groupWith('admin', 'member');

        $this->actingAs($admin)->putJson("/api/groups/{$group}/members/{$member->public_id}", ['role' => 'mod'])->assertOk();
        $this->actingAs($admin)->putJson("/api/groups/{$group}/members/{$member->public_id}", ['role' => 'admin'])->assertOk();
        $this->assertSame('admin', $this->roleOf($group, $member));

        $this->actingAs($this->owner)->putJson("/api/groups/{$group}/members/{$member->public_id}", ['role' => 'owner'])->assertStatus(422);
        $this->assertSame(['role', 'role'], $this->systemEvents($group));
    }

    public function test_only_the_owner_can_demote_an_admin(): void
    {
        [$group, $admin, $other] = $this->groupWith('admin', 'admin');

        $this->actingAs($admin)->putJson("/api/groups/{$group}/members/{$other->public_id}", ['role' => 'member'])->assertForbidden();
        $this->actingAs($this->owner)->putJson("/api/groups/{$group}/members/{$other->public_id}", ['role' => 'member'])->assertOk();
        $this->assertSame('member', $this->roleOf($group, $other));
    }

    public function test_mods_and_members_cannot_change_roles_and_nobody_changes_the_owners(): void
    {
        [$group, $admin, $mod, $member] = $this->groupWith('admin', 'mod', 'member');

        $this->actingAs($mod)->putJson("/api/groups/{$group}/members/{$member->public_id}", ['role' => 'mod'])->assertForbidden();
        $this->actingAs($member)->putJson("/api/groups/{$group}/members/{$member->public_id}", ['role' => 'admin'])->assertForbidden();
        $this->actingAs($admin)->putJson("/api/groups/{$group}/members/{$this->owner->public_id}", ['role' => 'member'])->assertForbidden();
        $this->actingAs($admin)->putJson("/api/groups/{$group}/members/{$admin->public_id}", ['role' => 'member'])->assertForbidden();
    }

    public function test_mods_and_above_kick_lower_ranks_only(): void
    {
        [$group, $admin, $mod, $mod2, $member] = $this->groupWith('admin', 'mod', 'mod', 'member');

        $this->actingAs($member)->deleteJson("/api/groups/{$group}/members/{$mod->public_id}")->assertForbidden();
        $this->actingAs($mod)->deleteJson("/api/groups/{$group}/members/{$mod2->public_id}")->assertForbidden();
        $this->actingAs($mod)->deleteJson("/api/groups/{$group}/members/{$admin->public_id}")->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/groups/{$group}/members/{$this->owner->public_id}")->assertForbidden();

        $this->actingAs($mod)->deleteJson("/api/groups/{$group}/members/{$member->public_id}")->assertNoContent();
        $this->assertNull($this->roleOf($group, $member));
        $this->assertSame(['kicked'], $this->systemEvents($group));

        // The kicked person is told their list changed, but not sent the group's messages.
        Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->name === 'conversations.changed' && $e->reaches($member));
        Event::assertNotDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->name === 'message.sent' && $e->reaches($member));

        // A kick is not a ban: they can come back to a public group.
        $this->actingAs($member)->postJson("/api/groups/{$group}/join")->assertCreated();
    }

    public function test_admins_ban_lower_ranks_and_a_ban_keeps_them_out(): void
    {
        [$group, $admin, $mod, $member] = $this->groupWith('admin', 'mod', 'member');
        $code = Conversation::find($group)->invite_code;

        $this->actingAs($mod)->putJson("/api/groups/{$group}/bans/{$member->public_id}")->assertForbidden();
        $this->actingAs($admin)->putJson("/api/groups/{$group}/bans/{$this->owner->public_id}")->assertForbidden();
        $this->actingAs($admin)->putJson("/api/groups/{$group}/bans/{$admin->public_id}")->assertStatus(422);

        $this->actingAs($admin)->putJson("/api/groups/{$group}/bans/{$member->public_id}")->assertNoContent();
        $this->assertNull($this->roleOf($group, $member));
        $this->assertSame([$member->public_id], array_column($this->actingAs($admin)->getJson("/api/groups/{$group}/bans")->json(), 'id'));

        $this->actingAs($member)->postJson("/api/groups/{$group}/join")->assertForbidden();
        $this->actingAs($member)->postJson("/api/invites/{$code}")->assertForbidden()->assertJson(['message' => 'You are banned from this group.']);

        $this->actingAs($admin)->deleteJson("/api/groups/{$group}/bans/{$member->public_id}")->assertNoContent();
        $this->actingAs($member)->postJson("/api/groups/{$group}/join")->assertCreated();
    }

    public function test_someone_can_be_banned_before_they_ever_join(): void
    {
        $group = $this->createGroup();
        $stranger = User::factory()->create();

        $this->actingAs($this->owner)->putJson("/api/groups/{$group}/bans/{$stranger->public_id}")->assertNoContent();
        $this->actingAs($stranger)->postJson("/api/groups/{$group}/join")->assertForbidden();
    }

    public function test_mods_delete_lower_ranks_messages_but_not_equal_or_higher(): void
    {
        [$group, $mod, $mod2, $member] = $this->groupWith('mod', 'mod', 'member');
        $say = fn (User $u) => ChatMessage::create(['conversation_id' => $group, 'user_id' => $u->id, 'body' => 'x'])->id;
        $fromMember = $say($member);
        $fromMod2 = $say($mod2);
        $fromOwner = $say($this->owner);

        $this->actingAs($member)->deleteJson("/api/conversations/{$group}/messages/{$fromMod2}")->assertForbidden();
        $this->actingAs($mod)->deleteJson("/api/conversations/{$group}/messages/{$fromMod2}")->assertForbidden();
        $this->actingAs($mod)->deleteJson("/api/conversations/{$group}/messages/{$fromOwner}")->assertForbidden();
        $this->actingAs($mod)->deleteJson("/api/conversations/{$group}/messages/{$fromMember}")->assertNoContent();
        $this->actingAs($member)->deleteJson("/api/conversations/{$group}/messages/{$say($member)}")->assertNoContent();
    }

    public function test_a_mod_can_delete_messages_from_someone_who_has_left(): void
    {
        [$group, $mod, $member] = $this->groupWith('mod', 'member');
        $message = ChatMessage::create(['conversation_id' => $group, 'user_id' => $member->id, 'body' => 'spam'])->id;
        $this->actingAs($member)->postJson("/api/groups/{$group}/leave")->assertNoContent();

        $this->actingAs($mod)->deleteJson("/api/conversations/{$group}/messages/{$message}")->assertNoContent();
    }

    public function test_when_the_owner_leaves_the_oldest_admin_takes_over(): void
    {
        [$group, , $admin1, $admin2] = $this->groupWith('mod', 'admin', 'admin');

        $this->actingAs($this->owner)->postJson("/api/groups/{$group}/leave")->assertNoContent();

        $this->assertSame('owner', $this->roleOf($group, $admin1));
        $this->assertSame('admin', $this->roleOf($group, $admin2));
        $this->assertSame(['left', 'owner'], $this->systemEvents($group));
    }

    public function test_with_no_admins_the_oldest_mod_takes_over_else_the_oldest_member(): void
    {
        [$group, $member, $mod] = $this->groupWith('member', 'mod');
        $this->actingAs($this->owner)->postJson("/api/groups/{$group}/leave");
        $this->assertSame('owner', $this->roleOf($group, $mod));

        [$group, $first, $second] = $this->groupWith('member', 'member');
        $this->actingAs($this->owner)->postJson("/api/groups/{$group}/leave");
        $this->assertSame('owner', $this->roleOf($group, $first));
        $this->assertSame('member', $this->roleOf($group, $second));
    }

    public function test_a_guest_owner_logging_out_hands_the_group_on(): void
    {
        $guest = User::factory()->guest()->create();
        $group = $this->createGroup(as: $guest);
        $member = User::factory()->create();
        $this->actingAs($member)->postJson("/api/groups/{$group}/join");

        $this->actingAs($guest)->fromSpa()->postJson('/api/auth/logout')->assertNoContent();

        $this->assertSame('owner', $this->roleOf($group, $member));
        $this->assertSame(['joined', 'left', 'owner'], $this->systemEvents($group));
    }

    public function test_the_last_one_out_deletes_the_group(): void
    {
        $group = $this->createGroup();
        ChatMessage::create(['conversation_id' => $group, 'user_id' => $this->owner->id, 'body' => 'hi']);

        $this->actingAs($this->owner)->postJson("/api/groups/{$group}/leave")->assertNoContent();

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_the_owner_can_hand_the_group_over_and_becomes_an_admin(): void
    {
        [$group, $member] = $this->groupWith('member');

        $this->actingAs($member)->postJson("/api/groups/{$group}/transfer", ['id' => $this->owner->public_id])->assertForbidden();
        $this->actingAs($this->owner)->postJson("/api/groups/{$group}/transfer", ['id' => 'nobody99'])->assertStatus(422);
        $this->actingAs($this->owner)->postJson("/api/groups/{$group}/transfer", ['id' => $member->public_id])->assertNoContent();

        $this->assertSame('owner', $this->roleOf($group, $member));
        $this->assertSame('admin', $this->roleOf($group, $this->owner));
    }

    public function test_a_full_group_turns_people_away(): void
    {
        $group = $this->createGroup();
        $users = User::factory()->count(Conversation::MAX_MEMBERS - 1)->create();
        ConversationMember::insert($users->map(fn (User $u) => [
            'conversation_id' => $group, 'user_id' => $u->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now(),
        ])->all());

        $this->actingAs(User::factory()->create())->postJson("/api/groups/{$group}/join")
            ->assertStatus(422)->assertJsonValidationErrors(['group' => 'This group is full.']);
    }

    public function test_a_large_group_gets_each_message_in_one_broadcast(): void
    {
        [$group] = $this->groupWith(...array_fill(0, 150, 'member'));

        $this->actingAs($this->owner)->postJson("/api/conversations/{$group}/messages", ['body' => 'hi all'])->assertCreated();

        $sent = Event::dispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->name === 'message.sent');
        $this->assertCount(1, $sent);
        $this->assertCount(151, $sent->first()[0]->recipients);
    }

    public function test_group_routes_refuse_dms(): void
    {
        $other = User::factory()->create();
        $dm = $this->actingAs($this->owner)->postJson('/api/dms', ['id' => $other->public_id])->json('id');

        $this->actingAs($this->owner)->getJson("/api/groups/{$dm}/members")->assertNotFound();
        $this->actingAs($this->owner)->postJson("/api/groups/{$dm}/leave")->assertNotFound();
        $this->actingAs($this->owner)->postJson("/api/groups/{$dm}/join")->assertNotFound();
    }
}
