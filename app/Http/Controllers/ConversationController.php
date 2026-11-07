<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

// What DMs and groups share: listing, messages and read markers. Every live update goes to
// each member's own user channel, so a browser holds one subscription however many
// conversations it has, and someone removed from a group stops hearing it at once.
class ConversationController
{
    private const PAGE_SIZE = 50;

    /**
     * Your DMs and groups, most recent first. A DM nobody has written in yet only shows to
     * whoever opened it.
     */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $conversations = Conversation::withMember($me)->forViewer($me)
            ->where(fn ($q) => $q->where('type', 'group')->orWhereNotNull('last_message_at')->orWhere('created_by', $me->id))
            ->orderByRaw('coalesce(last_message_at, created_at) desc')
            ->orderByDesc('id')
            ->get();

        return response()->json($conversations->map(fn (Conversation $c) => $c->presentFor($me)));
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        return response()->json(Conversation::viewFor($conversation, $request->user()));
    }

    /** Find or start the DM with someone, by their ID. */
    public function openDm(Request $request): JsonResponse
    {
        $me = $request->user();
        $them = FriendController::findByPublicId($request, $me);

        [$conversation, $created] = DB::transaction(function () use ($me, $them) {
            $existing = Conversation::where('dm_key', Conversation::dmKey($me, $them))->first();

            if ($existing) {
                return [$existing, false];
            }

            $conversation = Conversation::create([
                'type' => 'dm',
                'dm_key' => Conversation::dmKey($me, $them),
                'created_by' => $me->id,
            ]);
            $conversation->members()->createMany([['user_id' => $me->id], ['user_id' => $them->id]]);

            return [$conversation, true];
        });

        return $this->show($request, $conversation->id)->setStatusCode($created ? 201 : 200);
    }

    /** A page of messages, oldest first. Pass `before` (a message ID) to page further back. */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->membershipOf($request->user());
        $before = $request->integer('before');

        $page = $conversation->messages()
            ->with('user')
            ->when($before > 0, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        return response()->json([
            'data' => $page->take(self::PAGE_SIZE)->reverse()->values()->map->toPublicArray(),
            'has_more' => $page->count() > self::PAGE_SIZE,
        ]);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        $member = $conversation->membershipOf($me);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'nonce' => ['nullable', 'string', 'max:64'],
        ]);

        if ($conversation->type === 'dm') {
            $partner = $conversation->partnerOf($me);
            abort_unless($partner, 403, 'This user no longer exists.');
            abort_if($me->blockedEitherWay($partner), 403, "You can't message this user.");
        }

        // A retry of a send that already landed (the reply got lost) gets the saved message back.
        if (filled($validated['nonce'] ?? null)) {
            $sent = $conversation->messages()->withTrashed()->where('nonce', $validated['nonce'])->first();

            if ($sent) {
                abort_unless($sent->user_id === $me->id, 409, 'That nonce is taken.');

                return response()->json($sent->load('user')->toPublicArray());
            }
        }

        $message = DB::transaction(function () use ($conversation, $member, $me, $validated) {
            $message = $conversation->messages()->create([
                'user_id' => $me->id,
                'body' => $validated['body'],
                'nonce' => $validated['nonce'] ?? null,
            ]);

            $conversation->update(['last_message_at' => $message->created_at]);
            $member->update(['last_read_message_id' => $message->id]);

            return $message;
        });

        $payload = $message->setRelation('user', $me)->toPublicArray();
        Live::to($conversation->memberUsers(), 'message.sent', $payload);

        return response()->json($payload, 201);
    }

    /** Tell the others you're typing. Browsers send this every few seconds while you type. */
    public function typing(Request $request, Conversation $conversation): Response
    {
        $me = $request->user();
        $conversation->membershipOf($me);
        $blocked = $me->blockedEitherWayIds();

        Live::to(
            $conversation->memberUsers()->reject(fn (User $u) => $u->is($me) || $blocked->contains($u->id)),
            'typing',
            ['conversation_id' => $conversation->id, 'user' => $me->toPublicArray()],
        );

        return response()->noContent();
    }

    /** Your own messages anywhere; in a group, mods and above can also delete lower ranks'. */
    public function deleteMessage(Request $request, Conversation $conversation, ChatMessage $message): Response
    {
        $me = $request->user();
        $member = $conversation->membershipOf($me);
        abort_if($message->type === 'system', 403, "System messages can't be deleted.");

        if ($message->user_id !== $me->id) {
            $author = $conversation->members->firstWhere('user_id', $message->user_id);
            abort_unless($conversation->isGroup() && $member->atLeast('mod') && $member->outranks($author),
                403, "You can't delete this message.");
        }

        $message->delete();

        Live::to($conversation->memberUsers(), 'message.deleted', [
            'conversation_id' => $conversation->id,
            'id' => $message->id,
        ]);

        return response()->noContent();
    }

    /** Mark everything up to a message as read. The marker only ever moves forward. */
    public function read(Request $request, Conversation $conversation): Response
    {
        $me = $request->user();
        $member = $conversation->membershipOf($me);
        $upTo = min($request->validate(['message_id' => ['required', 'integer']])['message_id'],
            (int) $conversation->messages()->withTrashed()->max('id'));

        if ($upTo > (int) $member->last_read_message_id) {
            $member->update(['last_read_message_id' => $upTo]);

            // Your other tabs clear their unread badge too.
            Live::to($me, 'conversation.read', ['conversation_id' => $conversation->id, 'last_read_message_id' => $upTo]);
        }

        return response()->noContent();
    }
}
