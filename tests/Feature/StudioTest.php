<?php

namespace Tests\Feature;

use App\Models\Music\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\MakesMusic;
use Tests\TestCase;

class StudioTest extends TestCase
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

    public function test_guests_have_no_studio(): void
    {
        $this->actingAs(User::factory()->guest()->create())->getJson('/api/music/studio')->assertForbidden();
    }

    public function test_the_studio_lists_only_your_own_tracks_inbox_included(): void
    {
        $this->track($this->creator, ['title' => 'Mine', 'published_at' => null]);
        $this->track(User::factory()->create(), ['title' => 'Theirs']);

        $this->actingAs($this->creator)->getJson('/api/music/studio')
            ->assertOk()
            ->assertJsonPath('folder', $this->creator->public_id)
            ->assertJsonPath('tracks.*.title', ['Mine'])
            ->assertJsonPath('tracks.0.published_at', null);
    }

    public function test_editing_and_publishing_a_track(): void
    {
        $track = $this->track($this->creator, ['published_at' => null]);
        $album = $this->album($this->creator);

        $this->actingAs($this->creator)->patchJson("/api/music/studio/tracks/{$track->id}", [
            'title' => 'Final name',
            'album_id' => $album->id,
            'track_no' => 2,
            'is_ai' => false,
            'lyrics' => 'Words',
            'published' => true,
        ])->assertOk()->assertJsonPath('title', 'Final name')->assertJsonPath('album.id', $album->id);

        $track->refresh();
        $this->assertFalse($track->is_ai);
        $this->assertNotNull($publishedAt = $track->published_at);

        // Saving again keeps the original publish date.
        $this->travel(1)->day();
        $this->patchJson("/api/music/studio/tracks/{$track->id}", ['published' => true]);
        $this->assertEquals($publishedAt, $track->refresh()->published_at);

        $this->patchJson("/api/music/studio/tracks/{$track->id}", ['published' => false]);
        $this->assertNull($track->refresh()->published_at);
    }

    public function test_you_cannot_edit_others_tracks_or_use_their_albums(): void
    {
        $other = User::factory()->create();
        $theirs = $this->track($other);
        $theirAlbum = $this->album($other);
        $mine = $this->track($this->creator);

        $this->actingAs($this->creator)->patchJson("/api/music/studio/tracks/{$theirs->id}", ['title' => 'Hijack'])->assertNotFound();
        $this->patchJson("/api/music/studio/tracks/{$mine->id}", ['album_id' => $theirAlbum->id])->assertJsonValidationErrors('album_id');
        $this->patchJson("/api/music/studio/albums/{$theirAlbum->id}", ['title' => 'Hijack'])->assertNotFound();
    }

    public function test_bulk_changes_only_touch_your_own_tracks(): void
    {
        $album = $this->album($this->creator);
        $mine = [$this->track($this->creator, ['published_at' => null]), $this->track($this->creator, ['published_at' => null])];
        $theirs = $this->track(User::factory()->create(), ['published_at' => null]);

        $this->actingAs($this->creator)->patchJson('/api/music/studio/tracks', [
            'ids' => [$mine[0]->id, $mine[1]->id, $theirs->id],
            'album_id' => $album->id,
            'published' => true,
        ])->assertNoContent();

        $this->assertSame(2, Track::where('album_id', $album->id)->whereNotNull('published_at')->count());
        $this->assertNull($theirs->refresh()->published_at);
    }

    public function test_albums_and_covers(): void
    {
        $this->actingAs($this->creator);
        $id = $this->postJson('/api/music/studio/albums', ['title' => 'Night Drive', 'released_at' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('released_at', '2026-11-01')
            ->json('id');

        $cover = $this->post("/api/music/studio/albums/{$id}/cover", ['cover' => new UploadedFile(base_path('tests/fixtures/cover.png'), 'cover.png', 'image/png', null, true)])
            ->assertOk()
            ->json('cover_url');

        $track = $this->track($this->creator, ['album_id' => $id]);
        // A track without its own art shows the album's.
        $this->getJson("/api/music/tracks/{$track->id}")->assertJsonPath('cover_url', $cover);
        $this->get($cover)->assertOk();

        $this->post("/api/music/studio/albums/{$id}/cover", ['cover' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
            ->assertJsonValidationErrors('cover');

        // Deleting the album keeps its tracks.
        $this->deleteJson("/api/music/studio/albums/{$id}")->assertNoContent();
        $this->assertNull($track->refresh()->album_id);
    }

    public function test_scan_now_picks_up_your_files(): void
    {
        $this->placeFile("{$this->creator->public_id}/tone.mp3");

        // Someone else's new file is added too, but not reported to this creator.
        $this->placeFile(User::factory()->create()->public_id.'/other.mp3');

        $this->actingAs($this->creator)->postJson('/api/music/studio/scan')->assertOk()->assertJsonPath('added', 1);
        $this->assertSame(2, Track::count());
        $this->getJson('/api/music/studio')->assertJsonPath('tracks.0.title', 'Test Tone')->assertJsonPath('tracks.0.file', 'tone.mp3');
    }
}
