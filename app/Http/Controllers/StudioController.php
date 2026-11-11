<?php

namespace App\Http\Controllers;

use App\Models\Music\Album;
use App\Models\Music\Track;
use App\Models\User;
use App\Support\Music\Covers;
use App\Support\Music\Scanner;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Where creators look after their own music. Files arrive by being placed in their folder
 * (named after their public ID); the scanner turns each into an unpublished track here, and
 * everything else (titles, albums, art, publishing) is edited in the database.
 */
class StudioController
{
    public function index(Request $request): JsonResponse
    {
        $me = $this->creator($request);

        $tracks = Track::where('user_id', $me->id)->with(['user', 'album'])->latest('id')->get();
        $albums = Album::where('user_id', $me->id)->with('user')->withCount('tracks')->latest('id')->get();

        return response()->json([
            'folder' => $me->public_id,
            'tracks' => $tracks->map->toStudioArray(),
            'albums' => $albums->map(fn (Album $album) => [...$album->toPublicArray(), 'track_count' => $album->tracks_count]),
        ]);
    }

    public function updateTrack(Request $request, Track $track): JsonResponse
    {
        $me = $this->creator($request);
        abort_unless($track->user_id === $me->id, 404);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200', 'regex:/\S/u'],
            'album_id' => ['sometimes', 'nullable', 'integer', Rule::exists('music_albums', 'id')->where('user_id', $me->id)],
            'track_no' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'genre' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_ai' => ['sometimes', 'boolean'],
            'lyrics' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'published' => ['sometimes', 'boolean'],
        ]);

        $track->fill(collect($validated)->except('published')->all());

        if (array_key_exists('published', $validated)) {
            $track->published_at = $validated['published'] ? ($track->published_at ?? now()) : null;
        }

        $track->save();

        return response()->json($track->refresh()->load(['user', 'album'])->toStudioArray());
    }

    /** One change to several tracks at once, for clearing the inbox. */
    public function updateTracks(Request $request): Response
    {
        $me = $this->creator($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'album_id' => ['sometimes', 'nullable', 'integer', Rule::exists('music_albums', 'id')->where('user_id', $me->id)],
            'is_ai' => ['sometimes', 'boolean'],
            'published' => ['sometimes', 'boolean'],
        ]);

        $tracks = Track::where('user_id', $me->id)->whereIn('id', $validated['ids']);

        DB::transaction(function () use ($tracks, $validated) {
            $changes = collect($validated)->only(['album_id', 'is_ai'])->all();

            if ($changes) {
                (clone $tracks)->update($changes);
            }

            if (array_key_exists('published', $validated)) {
                $validated['published']
                    ? (clone $tracks)->whereNull('published_at')->update(['published_at' => now()])
                    : (clone $tracks)->update(['published_at' => null]);
            }
        });

        return response()->noContent();
    }

    public function trackCover(Request $request, Track $track): JsonResponse
    {
        $me = $this->creator($request);
        abort_unless($track->user_id === $me->id, 404);

        $track->update(['cover_path' => $this->replaceCover($request, $track->cover_path)]);

        return response()->json($track->load(['user', 'album'])->toStudioArray());
    }

    public function removeTrackCover(Request $request, Track $track): JsonResponse
    {
        $me = $this->creator($request);
        abort_unless($track->user_id === $me->id, 404);

        Covers::delete($track->cover_path);
        $track->update(['cover_path' => null]);

        return response()->json($track->load(['user', 'album'])->toStudioArray());
    }

    public function storeAlbum(Request $request): JsonResponse
    {
        $me = $this->creator($request);
        abort_if(Album::where('user_id', $me->id)->count() >= 500, 422, 'You have too many albums.');

        $album = Album::create([...$request->validate($this->albumRules(required: true)), 'user_id' => $me->id]);

        return response()->json([...$album->load('user')->toPublicArray(), 'track_count' => 0], 201);
    }

    public function updateAlbum(Request $request, Album $album): JsonResponse
    {
        $me = $this->creator($request);
        abort_unless($album->user_id === $me->id, 404);

        $album->update($request->validate($this->albumRules(required: false)));

        return response()->json([...$album->load('user')->toPublicArray(), 'track_count' => $album->tracks()->count()]);
    }

    /** Its tracks stay, back out of any album. */
    public function destroyAlbum(Request $request, Album $album): Response
    {
        $me = $this->creator($request);
        abort_unless($album->user_id === $me->id, 404);

        Covers::delete($album->cover_path);
        $album->delete();

        return response()->noContent();
    }

    public function albumCover(Request $request, Album $album): JsonResponse
    {
        $me = $this->creator($request);
        abort_unless($album->user_id === $me->id, 404);

        $album->update(['cover_path' => $this->replaceCover($request, $album->cover_path)]);

        return response()->json([...$album->load('user')->toPublicArray(), 'track_count' => $album->tracks()->count()]);
    }

    /** Looks for new, changed, moved and missing files now instead of waiting for the scheduler. */
    public function scan(Request $request, Scanner $scanner): JsonResponse
    {
        $me = $this->creator($request);

        // The scheduler or another creator may be scanning already; two at once would both add
        // the same new files. A scan takes moments, so wait for theirs to finish.
        try {
            Cache::lock('music:scan', 300)->block(30, fn () => $scanner->scan());
        } catch (LockTimeoutException) {
            abort(409, 'A scan is already running. Try again in a moment.');
        }

        // Only their own folder: what others have added isn't theirs to know about.
        return response()->json($scanner->countsFor($me));
    }

    /** Guests can listen, but can't own music: they vanish when they log out. */
    private function creator(Request $request): User
    {
        $user = $request->user();
        abort_if($user->is_guest, 403, 'Register an account to publish music.');

        return $user;
    }

    private function replaceCover(Request $request, ?string $old): string
    {
        $request->validate([
            'cover' => ['required', 'file', 'max:5120', 'mimetypes:'.implode(',', array_keys(Covers::EXTENSIONS)), 'dimensions:min_width=64,min_height=64,max_width=6000,max_height=6000'],
        ]);

        $name = Covers::storeUpload($request->file('cover'));
        Covers::delete($old);

        return $name;
    }

    /** @return array<string, array<int, mixed>> */
    private function albumRules(bool $required): array
    {
        return [
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:200', 'regex:/\S/u'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'released_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
