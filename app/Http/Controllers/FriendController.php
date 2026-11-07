<?php

namespace App\Http\Controllers;

use App\Models\Block;
use App\Models\Friendship;
use App\Models\User;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Friends, requests and blocks. Blocking is silent: a blocked person's requests seem to go
// through, but never reach the person who blocked them.
class FriendController
{
    /** Friends plus pending requests both ways, each tagged friend, incoming or outgoing. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $blocked = Block::where('blocker_id', $me->id)->pluck('blocked_id');

        $entries = Friendship::involving($me)->with('requester', 'addressee')->latest('id')->get()
            ->reject(fn (Friendship $f) => $f->statusFor($me) === 'incoming' && $blocked->contains($f->requester_id))
            ->map(fn (Friendship $f) => $f->toEntryFor($me))
            ->values();

        return response()->json($entries);
    }

    /** Send a request by ID. If they already asked you, you are friends straight away. */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $them = self::findByPublicId($request, $me);

        if ($me->hasBlocked($them)) {
            throw ValidationException::withMessages(['id' => 'You have blocked this user. Unblock them first.']);
        }

        [$friendship, $created, $changed] = DB::transaction(function () use ($me, $them) {
            $existing = Friendship::between($me, $them)->first();

            if (! $existing) {
                return [Friendship::create(['requester_id' => $me->id, 'addressee_id' => $them->id]), true, true];
            }

            if ($existing->statusFor($me) === 'incoming') {
                $existing->update(['accepted_at' => now()]);

                return [$existing, false, true];
            }

            return [$existing, false, false];
        });

        if ($changed) {
            $this->tell($me, $them);
        }

        return response()->json($friendship->toEntryFor($me), $created ? 201 : 200);
    }

    public function accept(Request $request, string $publicId): JsonResponse
    {
        $me = $request->user();
        $them = User::where('public_id', $publicId)->firstOrFail();

        $friendship = Friendship::where(['requester_id' => $them->id, 'addressee_id' => $me->id])
            ->whereNull('accepted_at')
            ->firstOrFail();

        $friendship->update(['accepted_at' => now()]);
        $this->tell($me, $them);

        return response()->json($friendship->toEntryFor($me));
    }

    /** Decline, cancel or unfriend: whatever there is between the two of you goes. */
    public function destroy(Request $request, string $publicId): Response
    {
        $me = $request->user();
        $them = User::where('public_id', $publicId)->firstOrFail();

        Friendship::between($me, $them)->firstOrFail()->delete();
        $this->tell($me, $them);

        return response()->noContent();
    }

    public function blocks(Request $request): JsonResponse
    {
        return response()->json(
            Block::where('blocker_id', $request->user()->id)->with('blocked')->latest('id')->get()
                ->map(fn (Block $block) => $block->blocked->toPublicArray()),
        );
    }

    /** Blocking also ends any friendship or request between you. */
    public function block(Request $request, string $publicId): Response
    {
        $me = $request->user();
        $them = User::where('public_id', $publicId)->firstOrFail();
        abort_if($me->is($them), 422, "You can't block yourself.");

        $ended = DB::transaction(function () use ($me, $them) {
            Block::firstOrCreate(['blocker_id' => $me->id, 'blocked_id' => $them->id]);

            return Friendship::between($me, $them)->delete() > 0;
        });

        Live::to($me, 'friends.changed');

        if ($ended) {
            Live::to($them, 'friends.changed');
        }

        return response()->noContent();
    }

    public function unblock(Request $request, string $publicId): Response
    {
        $me = $request->user();
        $them = User::where('public_id', $publicId)->firstOrFail();

        Block::where(['blocker_id' => $me->id, 'blocked_id' => $them->id])->delete();
        Live::to($me, 'friends.changed');

        return response()->noContent();
    }

    /** The user an `id` field names, as a validation error if there is no such person or it is you. */
    public static function findByPublicId(Request $request, User $me): User
    {
        $request->validate(['id' => ['required', 'string', 'max:16']]);

        $them = User::where('public_id', strtolower(ltrim(trim($request->input('id')), '#')))->first();

        if (! $them) {
            throw ValidationException::withMessages(['id' => 'No one has that ID.']);
        }

        if ($me->is($them)) {
            throw ValidationException::withMessages(['id' => "That's your own ID."]);
        }

        return $them;
    }

    /** Both sides' open tabs refresh their lists, unless the other side has blocked you. */
    private function tell(User $me, User $them): void
    {
        Live::to($me, 'friends.changed');

        if (! $them->hasBlocked($me)) {
            Live::to($them, 'friends.changed');
        }
    }
}
