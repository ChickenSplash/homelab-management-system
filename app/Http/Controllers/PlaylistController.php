<?php

namespace App\Http\Controllers;

use App\Models\Music\Playlist;
use App\Models\Music\PlaylistTrack;
use App\Models\Music\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

// Playlists are private: only their owner can see or change them. A guest's go when they log out.
class PlaylistController
{
    private const MAX_PLAYLISTS = 200;

    private const MAX_ENTRIES = 2000;

    public function index(Request $request): JsonResponse
    {
        $playlists = Playlist::where('user_id', $request->user()->id)->latest('updated_at')->latest('id')->get();

        return response()->json($playlists->map->toSummaryArray());
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $validated = $request->validate([
            ...$this->rules(),
            // Optional, so "Add to new playlist" is one request.
            'track_ids' => ['sometimes', 'array', 'max:500'],
            'track_ids.*' => ['integer'],
        ]);
        $count = Playlist::where('user_id', $me->id)->count();

        abort_if($count >= self::MAX_PLAYLISTS, 422, 'You have too many playlists.');

        $playlist = Playlist::create([
            'user_id' => $me->id,
            'name' => $validated['name'] ?? 'My Playlist #'.($count + 1),
            'description' => $validated['description'] ?? null,
        ]);

        $this->append($playlist, $validated['track_ids'] ?? []);

        return response()->json($playlist->refresh()->toSummaryArray(), 201);
    }

    /** The playlist with its listed tracks. Entries whose track was unpublished or went missing are left out. */
    public function show(Request $request, Playlist $playlist): JsonResponse
    {
        $me = $request->user();
        $this->own($request, $playlist);

        $entries = $playlist->entries()
            ->whereHas('track', fn ($q) => $q->listed())
            ->with(['track' => fn ($q) => $q->forListener($me)])
            ->get();

        return response()->json([
            ...$playlist->toSummaryArray(),
            'owner' => $me->toPublicArray(),
            'duration_ms' => $entries->sum('track.duration_ms'),
            'entries' => $entries->map(fn (PlaylistTrack $entry) => [
                'entry_id' => $entry->id,
                'added_at' => $entry->created_at?->toIso8601String(),
                'track' => $entry->track->toPublicArray(),
            ]),
        ]);
    }

    public function update(Request $request, Playlist $playlist): JsonResponse
    {
        $this->own($request, $playlist);
        $playlist->update($request->validate($this->rules()));

        return response()->json($playlist->toSummaryArray());
    }

    public function destroy(Request $request, Playlist $playlist): Response
    {
        $this->own($request, $playlist);
        $playlist->delete();

        return response()->noContent();
    }

    /** Adds tracks to the end, in the order given (a whole album at once, say). */
    public function addTracks(Request $request, Playlist $playlist): JsonResponse
    {
        $this->own($request, $playlist);
        $validated = $request->validate([
            'track_ids' => ['required', 'array', 'min:1', 'max:500'],
            'track_ids.*' => ['integer'],
        ]);

        $added = $this->append($playlist, $validated['track_ids']);

        return response()->json(['added' => $added, ...$playlist->refresh()->toSummaryArray()]);
    }

    public function removeEntry(Request $request, Playlist $playlist, PlaylistTrack $entry): Response
    {
        $this->own($request, $playlist);

        DB::transaction(function () use ($playlist, $entry) {
            $entry->delete();
            $playlist->renumber();
            $playlist->touch();
        });

        return response()->noContent();
    }

    /**
     * Moves one entry to `position` (0 is the top), shifting the rest. Positions count what the
     * listener sees, so hidden entries (unpublished tracks) don't throw a drag off.
     */
    public function moveEntry(Request $request, Playlist $playlist, PlaylistTrack $entry): Response
    {
        $this->own($request, $playlist);
        $position = $request->validate(['position' => ['required', 'integer', 'min:0']])['position'];

        DB::transaction(function () use ($playlist, $entry, $position) {
            $others = fn ($query) => $query->pluck('id')->reject(fn (int $id) => $id === $entry->id)->values();
            $ids = $others($playlist->entries());
            $anchor = $others($playlist->entries()->whereHas('track', fn ($q) => $q->listed()))->get($position);

            $ids->splice($anchor ? $ids->search($anchor) : $ids->count(), 0, [$entry->id]);

            foreach ($ids as $index => $id) {
                PlaylistTrack::whereKey($id)->update(['position' => $index]);
            }

            $playlist->touch();
        });

        return response()->noContent();
    }

    /** @param  array<int, int>  $ids */
    private function append(Playlist $playlist, array $ids): int
    {
        $listed = Track::listed()->whereIn('id', $ids)->pluck('id')->flip();
        // Keep the order asked for, repeats included, and drop anything a listener can't see.
        $ids = collect($ids)->filter(fn (int $id) => $listed->has($id))->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($playlist, $ids) {
            $next = (int) $playlist->entries()->max('position') + ($playlist->entries()->exists() ? 1 : 0);

            abort_if($next + $ids->count() > self::MAX_ENTRIES, 422, 'That playlist is full.');

            PlaylistTrack::insert($ids->map(fn (int $id, int $i) => [
                'playlist_id' => $playlist->id,
                'track_id' => $id,
                'position' => $next + $i,
                'created_at' => now(),
            ])->all());

            $playlist->touch();

            return $ids->count();
        });
    }

    private function own(Request $request, Playlist $playlist): void
    {
        abort_unless($playlist->user_id === $request->user()->id, 404);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100', 'regex:/\S/u', 'not_regex:/\p{C}/u'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }
}
