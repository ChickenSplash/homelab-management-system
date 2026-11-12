<?php

namespace App\Http\Controllers;

use App\Models\Chat\Conversation;
use App\Models\Chat\ConversationBan;
use App\Models\Chat\ConversationMember;
use App\Models\User;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Groups: owner > admin > mod > member. You can only act on people ranked below you;
// admins hand out roles up to admin, and only the owner can demote an admin.
class GroupController
{
    public const MAX_OWNED = 10;

    /** Public groups, biggest first, optionally filtered by name. */
    public function browse(Request $request): JsonResponse
    {
        $me = $request->user();
        $search = trim((string) $request->query('q', ''));

        $groups = Conversation::where(['type' => 'group', 'visibility' => 'public'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->withCount('members')
            ->orderByDesc('members_count')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json($groups->map(fn (Conversation $group) => $group->toPreviewFor($me)));
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $validated = $this->validateDetails($request, required: true);

        $owned = ConversationMember::where(['user_id' => $me->id, 'role' => 'owner'])
            ->whereHas('conversation', fn ($q) => $q->where('type', 'group'))
            ->count();

        if ($owned >= self::MAX_OWNED) {
            throw ValidationException::withMessages(['name' => 'You can own up to '.self::MAX_OWNED.' groups.']);
        }

        $group = DB::transaction(function () use ($me, $validated) {
            $group = Conversation::create([...$validated,
                'type' => 'group',
                'created_by' => $me->id,
                'invite_code' => Conversation::newInviteCode(),
            ]);
            $group->members()->create(['user_id' => $me->id, 'role' => 'owner']);

            return $group;
        });

        Live::to($me, 'conversations.changed', ['conversation_id' => $group->id]);

        return response()->json(Conversation::viewFor($group->id, $me), 201);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        $this->requireRole($conversation, $me, 'admin');

        $conversation->update($this->validateDetails($request, required: false));
        $this->tellMembers($conversation);

        return response()->json(Conversation::viewFor($conversation->id, $me));
    }

    public function destroy(Request $request, Conversation $conversation): Response
    {
        $this->requireRole($conversation, $request->user(), 'owner');

        $members = $conversation->memberUsers();
        $conversation->delete();
        Live::to($members, 'conversations.changed', ['conversation_id' => $conversation->id]);

        return response()->noContent();
    }

    /** Join a public group from the browse list. Private groups need the invite. */
    public function join(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->isGroup() && $conversation->visibility === 'public', 404);

        return $this->joinGroup($conversation, $request->user());
    }

    /** What an invite link leads to, so it can be shown before joining. */
    public function invite(Request $request, string $code): JsonResponse
    {
        return response()->json($this->byInvite($code)->toPreviewFor($request->user()));
    }

    public function acceptInvite(Request $request, string $code): JsonResponse
    {
        return $this->joinGroup($this->byInvite($code), $request->user());
    }

    /** A new invite code. The old link stops working. */
    public function resetInvite(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        $this->requireRole($conversation, $me, 'admin');

        $conversation->update(['invite_code' => Conversation::newInviteCode()]);
        $this->tellMembers($conversation);

        return response()->json(Conversation::viewFor($conversation->id, $me));
    }

    public function leave(Request $request, Conversation $conversation): Response
    {
        $me = $request->user();
        $this->requireRole($conversation, $me, 'member');

        $conversation->removeMember($me);

        return response()->noContent();
    }

    /** Everyone in the group, highest rank first, then in the order they joined. */
    public function members(Request $request, Conversation $conversation): JsonResponse
    {
        $this->requireRole($conversation, $request->user(), 'member');

        $members = $conversation->members()->with('user')->orderBy('id')->get()
            ->sortByDesc(fn (ConversationMember $m) => $m->rank())
            ->values()
            ->map(fn (ConversationMember $m) => [
                'user' => $m->user->toPublicArray(),
                'role' => $m->role,
                'joined_at' => $m->created_at->toIso8601String(),
            ]);

        return response()->json($members);
    }

    public function setRole(Request $request, Conversation $conversation, string $publicId): JsonResponse
    {
        $me = $request->user();
        $mine = $this->requireRole($conversation, $me, 'admin');
        $role = $request->validate(['role' => ['required', Rule::in(['admin', 'mod', 'member'])]])['role'];
        [$them, $theirs] = $this->memberById($conversation, $publicId);

        abort_unless($mine->outranks($theirs), 403, "You can't change this person's role.");

        if ($theirs->role !== $role) {
            $message = DB::transaction(function () use ($conversation, $theirs, $role, $them, $me) {
                $theirs->update(['role' => $role]);

                return $conversation->postSystem('role', $them, $me, ['role' => $role]);
            });

            $conversation->broadcastMessages([$message]);
        }

        return response()->json(['user' => $them->toPublicArray(), 'role' => $role]);
    }

    /** Hand the group to another member. You become an admin. */
    public function transfer(Request $request, Conversation $conversation): Response
    {
        $me = $request->user();
        $mine = $this->requireRole($conversation, $me, 'owner');
        $request->validate(['id' => ['required', 'string']]);
        [$them, $theirs] = $this->memberById($conversation, $request->input('id'));

        abort_if($them->is($me), 422, 'You already own this group.');

        $message = DB::transaction(function () use ($conversation, $mine, $theirs, $them, $me) {
            $mine->update(['role' => 'admin']);
            $theirs->update(['role' => 'owner']);

            return $conversation->postSystem('owner', $them, $me);
        });

        $conversation->broadcastMessages([$message]);

        return response()->noContent();
    }

    public function kick(Request $request, Conversation $conversation, string $publicId): Response
    {
        $me = $request->user();
        $mine = $this->requireRole($conversation, $me, 'mod');
        [$them, $theirs] = $this->memberById($conversation, $publicId);

        abort_unless($mine->outranks($theirs), 403, "You can't kick this person.");

        $conversation->removeMember($them, 'kicked', $me);

        return response()->noContent();
    }

    public function bans(Request $request, Conversation $conversation): JsonResponse
    {
        $this->requireRole($conversation, $request->user(), 'admin');

        return response()->json(
            $conversation->bans()->with('user')->latest('id')->get()
                ->map(fn (ConversationBan $ban) => $ban->user->toPublicArray()),
        );
    }

    /** Remove someone (if they are in) and keep them out. They needn't be a member. */
    public function ban(Request $request, Conversation $conversation, string $publicId): Response
    {
        $me = $request->user();
        $mine = $this->requireRole($conversation, $me, 'admin');
        $them = User::where('public_id', $publicId)->firstOrFail();
        $theirs = $conversation->members->firstWhere('user_id', $them->id);

        abort_if($them->is($me), 422, "You can't ban yourself.");
        abort_unless($mine->outranks($theirs), 403, "You can't ban this person.");

        ConversationBan::firstOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $them->id],
            ['banned_by' => $me->id],
        );

        if ($theirs) {
            $conversation->removeMember($them, 'banned', $me);
        }

        return response()->noContent();
    }

    public function unban(Request $request, Conversation $conversation, string $publicId): Response
    {
        $this->requireRole($conversation, $request->user(), 'admin');
        $them = User::where('public_id', $publicId)->firstOrFail();

        $conversation->bans()->where('user_id', $them->id)->delete();

        return response()->noContent();
    }

    private function joinGroup(Conversation $group, User $me): JsonResponse
    {
        $wasMember = $group->members()->where('user_id', $me->id)->exists();
        $group->addMember($me);

        if (! $wasMember) {
            Live::to($me, 'conversations.changed', ['conversation_id' => $group->id]);
        }

        return response()->json(Conversation::viewFor($group->id, $me), $wasMember ? 200 : 201);
    }

    private function byInvite(string $code): Conversation
    {
        return Conversation::where(['type' => 'group', 'invite_code' => strtolower(trim($code))])
            ->withCount('members')
            ->firstOrFail();
    }

    /** Your membership, if you are in the group and at least this rank. Outsiders get a 404. */
    private function requireRole(Conversation $conversation, User $me, string $role): ConversationMember
    {
        abort_unless($conversation->isGroup(), 404);
        $mine = $conversation->membershipOf($me);
        abort_unless($mine->atLeast($role), 403, "You don't have permission to do that.");

        return $mine;
    }

    /** @return array{0: User, 1: ConversationMember} */
    private function memberById(Conversation $conversation, string $publicId): array
    {
        $them = User::where('public_id', strtolower(ltrim(trim($publicId), '#')))->first();
        $theirs = $them ? $conversation->members->firstWhere('user_id', $them->id) : null;

        if (! $theirs) {
            throw ValidationException::withMessages(['id' => "That person isn't in this group."]);
        }

        return [$them, $theirs];
    }

    /** @return array<string, mixed> */
    private function validateDetails(Request $request, bool $required): array
    {
        $rule = $required ? 'required' : 'sometimes';

        $validated = $request->validate([
            'name' => [$rule, 'string', 'max:50', 'regex:/\S/u'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'visibility' => [$rule, Rule::in(['public', 'private'])],
        ]);

        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
        }

        if (array_key_exists('description', $validated)) {
            $validated['description'] = filled($validated['description']) ? trim($validated['description']) : null;
        }

        return $validated;
    }

    /** Settings changed: every member's open tabs refetch the group. */
    private function tellMembers(Conversation $conversation): void
    {
        Live::to($conversation->memberUsers(), 'conversations.changed', ['conversation_id' => $conversation->id]);
    }
}
