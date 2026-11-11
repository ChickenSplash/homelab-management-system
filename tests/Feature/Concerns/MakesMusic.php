<?php

namespace Tests\Feature\Concerns;

use App\Models\Music\Album;
use App\Models\Music\Track;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait MakesMusic
{
    protected string $musicRoot;

    protected function setUpMusicFolders(): void
    {
        $base = sys_get_temp_dir().'/hms-music-'.Str::random(8);
        $this->musicRoot = "{$base}/library";
        File::ensureDirectoryExists($this->musicRoot);
        config(['music.root' => $this->musicRoot, 'music.covers' => "{$base}/covers", 'music.accel_prefix' => null]);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($base));
    }

    /** Copies the test MP3 into the library, relative to its root. */
    protected function placeFile(string $path): string
    {
        $full = "{$this->musicRoot}/{$path}";
        File::ensureDirectoryExists(dirname($full));
        File::copy(base_path('tests/fixtures/tone.mp3'), $full);

        return $full;
    }

    /** A track with no file behind it, published unless told otherwise. */
    protected function track(User $owner, array $attributes = []): Track
    {
        return Track::create([
            'user_id' => $owner->id,
            'title' => 'Song '.Str::random(4),
            'duration_ms' => 180000,
            'published_at' => now(),
            'path' => $owner->public_id.'/'.Str::random(10).'.mp3',
            'size' => 1000,
            'mtime' => 1,
            'fingerprint' => Str::random(40),
            ...$attributes,
        ]);
    }

    protected function album(User $owner, array $attributes = []): Album
    {
        return Album::create(['user_id' => $owner->id, 'title' => 'Album '.Str::random(4), ...$attributes]);
    }
}
