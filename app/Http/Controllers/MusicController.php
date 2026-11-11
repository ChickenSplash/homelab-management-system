<?php

namespace App\Http\Controllers;

use App\Models\Music\Album;
use App\Models\Music\Like;
use App\Models\Music\Play;
use App\Models\Music\Track;
use App\Models\User;
use App\Support\Music\Covers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Listening: browsing, search, streams and likes. Anyone signed in may listen, guests too.
class MusicController
{
    private const SHELF = 12;

    public function home(Request $request): JsonResponse
    {
        $me = $request->user();

        $recentIds = Play::where('user_id', $me->id)
            ->select('track_id', DB::raw('max(played_at) as last_played'))
            ->groupBy('track_id')
            ->orderByDesc('last_played')
            ->limit(self::SHELF)
            ->pluck('track_id');

        return response()->json([
            'recently_played' => $this->inOrder($this->tracks($me)->whereIn('music_tracks.id', $recentIds)->get(), $recentIds),
            'new_tracks' => $this->present($this->tracks($me)->latest('published_at')->latest('id')->limit(self::SHELF)->get()),
            'albums' => $this->albums()->latest('id')->limit(self::SHELF)->get()->map->toPublicArray(),
            'creators' => $this->creators()->limit(self::SHELF)->get()->map(fn (User $u) => $this->creatorCard($u)),
        ]);
    }

    /** Matches by title, genre, album and creator name. With no query it lists everything, newest first. */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $me = $request->user();
        $q = trim((string) $request->query('q'));
        $like = '%'.addcslashes($q, '%_\\').'%';
        $matches = fn (string $column) => fn (Builder $query) => $query->whereRaw("{$column} like ? escape '\\'", [$like]);

        if ($q === '') {
            return response()->json([
                'tracks' => $this->present($this->tracks($me)->latest('published_at')->latest('id')->limit(200)->get()),
                'albums' => $this->albums()->orderBy('title')->get()->map->toPublicArray(),
                'creators' => $this->creators()->get()->map(fn (User $u) => $this->creatorCard($u)),
            ]);
        }

        $tracks = $this->tracks($me)
            ->where(fn ($query) => $query->where($matches('music_tracks.title'))
                ->orWhere($matches('music_tracks.genre'))
                ->orWhereHas('album', $matches('music_albums.title'))
                ->orWhereHas('user', $matches('users.display_name')))
            ->latest('published_at')
            ->limit(50)
            ->get();

        return response()->json([
            'tracks' => $this->present($tracks),
            'albums' => $this->albums()->where($matches('music_albums.title'))->limit(20)->get()->map->toPublicArray(),
            'creators' => $this->creators()->where($matches('users.display_name'))->limit(20)->get()->map(fn (User $u) => $this->creatorCard($u)),
        ]);
    }

    public function track(Request $request, Track $track): JsonResponse
    {
        $track = $this->visible($request, $track);

        return response()->json([...$track->toPublicArray(), 'lyrics' => $track->lyrics]);
    }

    public function album(Request $request, Album $album): JsonResponse
    {
        $me = $request->user();
        abort_unless($album->isVisibleTo($me), 404);

        $tracks = $this->tracks($me)->where('album_id', $album->id)->orderByRaw('track_no is null, track_no')->orderBy('music_tracks.id')->get();

        return response()->json([
            ...$album->load('user')->toPublicArray(),
            'track_count' => $tracks->count(),
            'duration_ms' => $tracks->sum('duration_ms'),
            'tracks' => $this->present($tracks),
        ]);
    }

    /** A creator's page: their most played tracks, albums and everything else, newest first. */
    public function creator(Request $request, string $publicId): JsonResponse
    {
        $me = $request->user();
        $creator = User::where(['public_id' => $publicId, 'is_guest' => false])->firstOrFail();

        $tracks = $this->tracks($me)->where('music_tracks.user_id', $creator->id)
            ->withCount('plays')
            ->latest('published_at')
            ->get();

        abort_if($tracks->isEmpty() && ! $creator->is($me), 404);

        return response()->json([
            'creator' => $creator->toPublicArray(),
            'track_count' => $tracks->count(),
            'popular' => $this->present($tracks->sortByDesc('plays_count')->take(10)->values()),
            'tracks' => $this->present($tracks),
            'albums' => $this->albums()->where('user_id', $creator->id)->latest('released_at')->latest('id')->get()->map->toPublicArray(),
        ]);
    }

    public function stream(Request $request, Track $track): Response|BinaryFileResponse
    {
        $track = $this->visible($request, $track);
        $headers = ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=86400'];

        if ($prefix = config('music.accel_prefix')) {
            $uri = rtrim($prefix, '/').'/'.implode('/', array_map(rawurlencode(...), explode('/', $track->path)));

            return response('', 200, [...$headers, 'X-Accel-Redirect' => $uri]);
        }

        $file = rtrim(config('music.root'), '/').'/'.$track->path;
        abort_unless(is_file($file), 404);

        // Answers Range requests itself, so seeking works.
        return response()->file($file, $headers);
    }

    public function trackCover(Request $request, Track $track): BinaryFileResponse
    {
        $track = $this->visible($request, $track);
        abort_unless($track->cover_path, 404);

        return $this->cover($track->cover_path);
    }

    public function albumCover(Request $request, Album $album): BinaryFileResponse
    {
        abort_unless($album->cover_path && $album->isVisibleTo($request->user()), 404);

        return $this->cover($album->cover_path);
    }

    /** The front end calls this once a track has played for a while, not on every click. */
    public function play(Request $request, Track $track): Response
    {
        $track = $this->visible($request, $track);
        Play::record($request->user(), $track);

        return response()->noContent();
    }

    public function likes(Request $request): JsonResponse
    {
        $me = $request->user();

        $tracks = $this->tracks($me)
            ->join('music_likes', fn ($join) => $join->on('music_likes.track_id', '=', 'music_tracks.id')->where('music_likes.user_id', $me->id))
            ->select('music_tracks.*', 'music_likes.created_at as liked_at')
            ->orderByDesc('music_likes.created_at')
            ->get();

        return response()->json($tracks->map(fn (Track $t) => [...$t->toPublicArray(), 'added_at' => $this->iso($t->liked_at)]));
    }

    public function like(Request $request, Track $track): Response
    {
        $track = $this->visible($request, $track);
        Like::insertOrIgnore(['user_id' => $request->user()->id, 'track_id' => $track->id, 'created_at' => now()]);

        return response()->noContent();
    }

    public function unlike(Request $request, Track $track): Response
    {
        Like::where(['user_id' => $request->user()->id, 'track_id' => $track->id])->delete();

        return response()->noContent();
    }

    /** @return Builder<Track> */
    private function tracks(User $me): Builder
    {
        return Track::listed()->forListener($me);
    }

    /** @return Builder<Album> */
    private function albums(): Builder
    {
        return Album::listed()->with('user')
            ->withCount(['tracks as listed_tracks_count' => fn ($q) => $q->listed()]);
    }

    /** People with at least one listed track, most prolific first. @return Builder<User> */
    private function creators(): Builder
    {
        $listed = fn () => Track::listed()->whereColumn('music_tracks.user_id', 'users.id');

        return User::where('is_guest', false)
            ->whereExists($listed()->toBase())
            ->select('users.*')
            ->addSelect(['listed_tracks_count' => $listed()->selectRaw('count(*)')])
            ->orderByDesc('listed_tracks_count')
            ->orderBy('display_name');
    }

    /** @return array<string, mixed> */
    private function creatorCard(User $user): array
    {
        return [...$user->toPublicArray(), 'track_count' => $user->listed_tracks_count];
    }

    private function visible(Request $request, Track $track): Track
    {
        abort_unless($track->isVisibleTo($request->user()), 404);

        return $track->loadMissing(['user', 'album'])
            ->loadExists(['likes as liked' => fn ($q) => $q->where('user_id', $request->user()->id)]);
    }

    private function cover(string $name): BinaryFileResponse
    {
        $path = Covers::path($name);
        abort_unless(is_file($path), 404);

        // The URL changes with the image, so it can be kept for good.
        return response()->file($path, ['Cache-Control' => 'private, max-age=31536000, immutable']);
    }

    /**
     * @param  Collection<int, Track>|BaseCollection<int, Track>  $tracks
     * @return BaseCollection<int, array<string, mixed>>
     */
    private function present($tracks): BaseCollection
    {
        return collect($tracks)->map(fn (Track $track) => $track->toPublicArray())->values();
    }

    /**
     * @param  Collection<int, Track>  $tracks
     * @param  BaseCollection<int, int>  $ids
     */
    private function inOrder(Collection $tracks, $ids): BaseCollection
    {
        $byId = $tracks->keyBy('id');

        return $this->present($ids->map(fn (int $id) => $byId->get($id))->filter());
    }

    private function iso(mixed $time): ?string
    {
        return $time ? Carbon::parse($time)->toIso8601String() : null;
    }
}
