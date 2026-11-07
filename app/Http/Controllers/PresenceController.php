<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Presence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

// Online, idle, do not disturb or offline. Going online and offline comes from Reverb (see
// SyncPresence); this is the starting picture, activity and the status you pick.
class PresenceController
{
    /** Everyone you can see who isn't offline, yourself included: public ID => status. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $online = User::whereIn('id', $me->presenceAudienceIds()->push($me->id))
            ->where('presence', '!=', 'offline')
            ->pluck('presence', 'public_id');

        return response()->json((object) $online->all());
    }

    /** The browser saw you do something. Sent at most once a minute, so it's cheap to save. */
    public function active(Request $request): Response
    {
        $me = $request->user();
        $me->active_at = now();
        $me->refreshPresence();

        return response()->noContent();
    }

    public function status(Request $request): UserResource
    {
        $me = $request->user();
        $me->status = $request->validate(['status' => ['required', Rule::in(Presence::STATUSES)]])['status'];
        $me->refreshPresence();

        return new UserResource($me);
    }
}
