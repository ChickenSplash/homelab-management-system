<?php

namespace App\Support\Music;

use App\Models\Music\Track;
use App\Models\User;
use getID3;
use getid3_lib;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Keeps music_tracks in step with the files. It never touches what a creator has edited: a new
 * file becomes an unpublished track filled in from its tags, a changed file only gets its file
 * columns refreshed, a moved file is found again by its fingerprint, and a vanished one is hidden.
 */
class Scanner
{
    private const KINDS = ['added' => 0, 'updated' => 0, 'moved' => 0, 'missing' => 0, 'restored' => 0, 'skipped' => 0];

    /** @var array<string, int> */
    public array $counts = self::KINDS;

    /** @var array<int, array<string, int>> The same, per creator, so a creator only hears about their own files. */
    private array $countsByOwner = [];

    /** @var array<string, ?User> Creators by public ID, null for a folder that belongs to no one. */
    private array $owners = [];

    private ?getID3 $id3 = null;

    /** @var array{0: ?string, 1: array<string, mixed>} */
    private array $analysed = [null, []];

    /** @return array<string, int> What the last scan changed in this creator's folder. */
    public function countsFor(User $user): array
    {
        return $this->countsByOwner[$user->id] ?? self::KINDS;
    }

    private function count(string $kind, ?int $ownerId = null): void
    {
        $this->counts[$kind]++;

        if ($ownerId) {
            $this->countsByOwner[$ownerId] ??= self::KINDS;
            $this->countsByOwner[$ownerId][$kind]++;
        }
    }

    /** @return array<string, int> */
    public function scan(): array
    {
        $root = rtrim(config('music.root'), '/');

        if (! is_dir($root)) {
            Log::warning("music: the library folder {$root} doesn't exist.");

            return $this->counts;
        }

        $files = $this->files($root);
        $known = Track::all()->keyBy('path');

        // Tracks whose file isn't where it was: the only ones a new file can be a move of.
        $gone = $known->reject(fn (Track $track) => $files->has($track->path));

        foreach ($files as $path => $file) {
            try {
                $this->sync($path, $file, $known->get($path), $gone);
            } catch (Throwable $e) {
                $this->count('skipped');
                Log::warning("music: couldn't read {$path}: {$e->getMessage()}");
            }
        }

        foreach ($gone as $track) {
            if (! $track->missing_at && ! $files->has($track->path)) {
                $track->update(['missing_at' => now()]);
                $this->count('missing', $track->user_id);
            }
        }

        return $this->counts;
    }

    /** @return Collection<string, SplFileInfo> MP3s by path relative to the root. */
    private function files(string $root): Collection
    {
        $finder = Finder::create()->files()->in($root)->name('/\.mp3$/i')->ignoreDotFiles(true)->followLinks()->sortByName();

        return collect(iterator_to_array($finder, false))
            ->keyBy(fn (SplFileInfo $file) => str_replace('\\', '/', $file->getRelativePathname()));
    }

    /** @param  Collection<string, Track>  $gone */
    private function sync(string $path, SplFileInfo $file, ?Track $track, Collection $gone): void
    {
        $owner = $this->ownerOf($path);

        if (! $owner) {
            $this->count('skipped');

            return;
        }

        $size = $file->getSize();
        $mtime = $file->getMTime();

        if ($track) {
            if ($track->missing_at) {
                $track->missing_at = null;
                $this->count('restored', $owner->id);
            }

            if ($track->size !== $size || $track->mtime !== $mtime) {
                $track->fill([...$this->fileColumns($file), ...$this->audio($file)]);
                $this->count('updated', $owner->id);
            }

            $track->save();

            return;
        }

        $fingerprint = $this->fingerprint($file);
        $moved = $gone->first(fn (Track $t) => $t->fingerprint === $fingerprint && $t->user_id === $owner->id);

        if ($moved) {
            $gone->forget($moved->path);
            $moved->update(['path' => $path, 'size' => $size, 'mtime' => $mtime, 'missing_at' => null]);
            $this->count('moved', $owner->id);

            return;
        }

        $tags = $this->tags($file);

        Track::create([
            'user_id' => $owner->id,
            'title' => $tags['title'] ?: pathinfo($path, PATHINFO_FILENAME),
            'genre' => $tags['genre'],
            'track_no' => $tags['track_no'],
            'lyrics' => $tags['lyrics'],
            'cover_path' => $tags['cover'],
            'is_ai' => true,
            'path' => $path,
            'fingerprint' => $fingerprint,
            ...$this->fileColumns($file),
            ...$this->audio($file),
        ]);

        $this->count('added', $owner->id);
    }

    /** The top folder is the creator's public ID. Guests can listen but never own music. */
    private function ownerOf(string $path): ?User
    {
        $publicId = str_contains($path, '/') ? strstr($path, '/', true) : null;

        if (! $publicId) {
            return null;
        }

        return $this->owners[$publicId] ??= User::where(['public_id' => $publicId, 'is_guest' => false])->first();
    }

    /** @return array<string, mixed> */
    private function fileColumns(SplFileInfo $file): array
    {
        return ['size' => $file->getSize(), 'mtime' => $file->getMTime(), 'fingerprint' => $this->fingerprint($file)];
    }

    private function fingerprint(SplFileInfo $file): string
    {
        $handle = fopen($file->getPathname(), 'rb');
        $head = fread($handle, 65536);
        fclose($handle);

        return hash('sha256', $file->getSize().':'.$head);
    }

    /** @return array{duration_ms: int, bitrate: ?int} */
    private function audio(SplFileInfo $file): array
    {
        $info = $this->analyse($file);

        return [
            'duration_ms' => (int) round(($info['playtime_seconds'] ?? 0) * 1000),
            'bitrate' => isset($info['audio']['bitrate']) ? (int) round($info['audio']['bitrate']) : null,
        ];
    }

    /** @return array{title: ?string, genre: ?string, track_no: ?int, lyrics: ?string, cover: ?string} */
    private function tags(SplFileInfo $file): array
    {
        $info = $this->analyse($file);
        getid3_lib::CopyTagsToComments($info);
        $comments = $info['comments'] ?? [];
        $first = fn (string $key) => ($value = trim((string) ($comments[$key][0] ?? ''))) !== '' ? $value : null;

        $picture = $comments['picture'][0] ?? null;

        return [
            'title' => $first('title'),
            'genre' => ($genre = $first('genre')) ? mb_substr($genre, 0, 64) : null,
            // "3/12" or "3".
            'track_no' => ($number = (int) $first('track_number')) > 0 ? min($number, 65535) : null,
            // A real USLT frame, or the TXXX one some tools (ffmpeg among them) write instead.
            'lyrics' => $first('unsynchronised_lyric')
                ?? (collect($comments['text'] ?? [])->first(fn ($v, $k) => in_array(strtolower($k), ['uslt', 'lyrics', 'unsyncedlyrics'], true) && trim($v) !== '')),
            'cover' => $picture ? Covers::put($picture['data'], $picture['image_mime'] ?? null) : null,
        ];
    }

    /** @return array<string, mixed> A new file is read for its tags and its audio, so the last one is kept. */
    private function analyse(SplFileInfo $file): array
    {
        if ($this->analysed[0] !== $file->getPathname()) {
            $this->id3 ??= new getID3;
            $this->analysed = [$file->getPathname(), $this->id3->analyze($file->getPathname())];
        }

        return $this->analysed[1];
    }
}
