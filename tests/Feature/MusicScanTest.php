<?php

namespace Tests\Feature;

use App\Models\Music\Like;
use App\Models\Music\Track;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\MakesMusic;
use Tests\TestCase;

class MusicScanTest extends TestCase
{
    use MakesMusic;
    use RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMusicFolders();
        $this->creator = User::factory()->create();
    }

    public function test_a_new_file_becomes_an_unpublished_track_filled_in_from_its_tags(): void
    {
        $this->placeFile("{$this->creator->public_id}/Demos/tone.mp3");

        $this->artisan('music:scan')->expectsOutputToContain('added 1')->assertSuccessful();

        $track = Track::sole();
        $this->assertSame($this->creator->id, $track->user_id);
        $this->assertSame('Test Tone', $track->title);
        $this->assertSame('Synthwave', $track->genre);
        $this->assertSame(3, $track->track_no);
        $this->assertSame('La la la', $track->lyrics);
        $this->assertTrue($track->is_ai);
        $this->assertNull($track->published_at);
        $this->assertEqualsWithDelta(2000, $track->duration_ms, 100);
        $this->assertSame("{$this->creator->public_id}/Demos/tone.mp3", $track->path);

        // The embedded art was saved as the track's cover.
        $this->assertNotNull($track->cover_path);
        $this->assertFileExists(config('music.covers').'/'.$track->cover_path);
    }

    public function test_a_rescan_never_overwrites_what_the_creator_edited(): void
    {
        $file = $this->placeFile("{$this->creator->public_id}/tone.mp3");
        $this->artisan('music:scan');
        Track::sole()->update(['title' => 'My title', 'genre' => 'Ambient', 'published_at' => now()]);

        // Even when the file itself changes.
        touch($file, time() + 60);
        $this->artisan('music:scan')->expectsOutputToContain('updated 1');

        $track = Track::sole();
        $this->assertSame('My title', $track->title);
        $this->assertSame('Ambient', $track->genre);
        $this->assertNotNull($track->published_at);
    }

    public function test_a_moved_file_keeps_its_track_likes_and_edits(): void
    {
        $this->placeFile("{$this->creator->public_id}/old/tone.mp3");
        $this->artisan('music:scan');
        $track = Track::sole();
        $track->update(['title' => 'Kept', 'published_at' => now()]);
        Like::insert(['user_id' => User::factory()->create()->id, 'track_id' => $track->id, 'created_at' => now()]);

        File::moveDirectory("{$this->musicRoot}/{$this->creator->public_id}/old", "{$this->musicRoot}/{$this->creator->public_id}/new");
        $this->artisan('music:scan')->expectsOutputToContain('added 0, updated 0, moved 1');

        $track->refresh();
        $this->assertSame("{$this->creator->public_id}/new/tone.mp3", $track->path);
        $this->assertSame('Kept', $track->title);
        $this->assertNull($track->missing_at);
        $this->assertSame(1, Like::count());
    }

    public function test_a_vanished_file_is_hidden_then_restored_when_it_comes_back(): void
    {
        $file = $this->placeFile("{$this->creator->public_id}/tone.mp3");
        $this->artisan('music:scan');
        $backup = $file.'.bak';
        rename($file, $backup);

        $this->artisan('music:scan')->expectsOutputToContain('missing 1');
        $this->assertNotNull(Track::sole()->missing_at);

        rename($backup, $file);
        $this->artisan('music:scan')->expectsOutputToContain('restored 1');
        $this->assertNull(Track::sole()->missing_at);
    }

    public function test_files_outside_a_registered_creators_folder_are_ignored(): void
    {
        $guest = User::factory()->guest()->create();
        $this->placeFile('loose.mp3');
        $this->placeFile('nobody123/tone.mp3');
        $this->placeFile("{$guest->public_id}/tone.mp3");
        $this->placeFile("{$this->creator->public_id}/.hidden.mp3");
        File::put("{$this->musicRoot}/{$this->creator->public_id}/notes.txt", 'not music');

        $this->artisan('music:scan')->expectsOutputToContain('added 0')->assertSuccessful();

        $this->assertSame(0, Track::count());
    }

    public function test_a_creator_with_tracks_cannot_be_deleted_by_accident(): void
    {
        $this->track($this->creator);

        $this->expectException(QueryException::class);
        $this->creator->delete();
    }
}
