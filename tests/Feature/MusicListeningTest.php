<?php

namespace Tests\Feature;

use App\Models\Music\Play;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesMusic;
use Tests\TestCase;

class MusicListeningTest extends TestCase
{
    use MakesMusic;
    use RefreshDatabase;

    private User $creator;

    private User $guest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMusicFolders();
        $this->creator = User::factory()->create(['display_name' => 'Nova']);
        $this->guest = User::factory()->guest()->create();
    }

    public function test_signed_out_visitors_get_nothing(): void
    {
        $track = $this->track($this->creator);

        $this->getJson('/api/music/home')->assertUnauthorized();
        $this->get("/api/music/tracks/{$track->id}/stream")->assertUnauthorized();
    }

    public function test_listeners_only_see_published_tracks_whose_file_is_there(): void
    {
        $listed = $this->track($this->creator, ['title' => 'Listed']);
        $inbox = $this->track($this->creator, ['title' => 'Inbox', 'published_at' => null]);
        $missing = $this->track($this->creator, ['title' => 'Missing', 'missing_at' => now()]);

        $this->actingAs($this->guest)->getJson('/api/music/search')
            ->assertOk()
            ->assertJsonPath('tracks.*.title', ['Listed'])
            ->assertJsonPath('tracks.0.creator.display_name', 'Nova')
            ->assertJsonPath('tracks.0.is_ai', true);

        $this->getJson("/api/music/tracks/{$listed->id}")->assertOk();
        $this->getJson("/api/music/tracks/{$inbox->id}")->assertNotFound();
        $this->get("/api/music/tracks/{$missing->id}/stream")->assertNotFound();

        // The creator can still open their own, to check them before publishing. (Named guard:
        // the stream route above runs on the web guard, so it became the default.)
        $this->actingAs($this->creator, 'sanctum')->getJson("/api/music/tracks/{$inbox->id}")->assertOk();
    }

    public function test_search_matches_titles_genres_albums_and_creators(): void
    {
        $album = $this->album($this->creator, ['title' => 'Night Drive']);
        $this->track($this->creator, ['title' => 'Neon Rain', 'genre' => 'Synthwave', 'album_id' => $album->id]);
        $this->track($this->creator, ['title' => 'Quiet Morning', 'genre' => 'Ambient']);
        $this->track($this->creator, ['title' => '100% Real']);

        $search = fn (string $q) => $this->actingAs($this->guest)->getJson('/api/music/search?q='.urlencode($q))->assertOk();

        $search('neon')->assertJsonPath('tracks.*.title', ['Neon Rain']);
        $search('ambient')->assertJsonPath('tracks.*.title', ['Quiet Morning']);
        $search('night')->assertJsonPath('albums.*.title', ['Night Drive'])->assertJsonPath('tracks.*.title', ['Neon Rain']);
        $search('nova')->assertJsonPath('creators.0.display_name', 'Nova')->assertJsonPath('creators.0.track_count', 3);
        // LIKE wildcards are matched literally.
        $search('%')->assertJsonPath('tracks.*.title', ['100% Real']);
    }

    public function test_streaming_sends_the_file_and_honours_ranges(): void
    {
        $this->placeFile("{$this->creator->public_id}/tone.mp3");
        $track = $this->track($this->creator, ['path' => "{$this->creator->public_id}/tone.mp3"]);

        $this->actingAs($this->guest)->get("/api/music/tracks/{$track->id}/stream")
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');

        $this->get("/api/music/tracks/{$track->id}/stream", ['Range' => 'bytes=0-99'])
            ->assertStatus(206)
            ->assertHeader('Content-Length', '100');
    }

    public function test_with_an_accel_prefix_nginx_serves_the_file(): void
    {
        config(['music.accel_prefix' => '/_music/']);
        $track = $this->track($this->creator, ['path' => "{$this->creator->public_id}/My Album/Track #1.mp3"]);

        $this->actingAs($this->guest)->get("/api/music/tracks/{$track->id}/stream")
            ->assertOk()
            ->assertHeader('X-Accel-Redirect', "/_music/{$this->creator->public_id}/My%20Album/Track%20%231.mp3");
    }

    public function test_likes_are_per_listener_and_newest_first(): void
    {
        [$first, $second] = [$this->track($this->creator), $this->track($this->creator)];
        $other = User::factory()->create();

        $this->actingAs($this->guest)->putJson("/api/music/likes/{$first->id}")->assertNoContent();
        $this->travel(1)->minute();
        $this->putJson("/api/music/likes/{$second->id}")->assertNoContent();
        // Liking twice is harmless.
        $this->putJson("/api/music/likes/{$second->id}")->assertNoContent();

        $this->getJson('/api/music/likes')->assertJsonPath('*.id', [$second->id, $first->id]);
        $this->getJson("/api/music/tracks/{$first->id}")->assertJsonPath('liked', true);

        $this->actingAs($other)->getJson('/api/music/likes')->assertExactJson([]);
        $this->getJson("/api/music/tracks/{$first->id}")->assertJsonPath('liked', false);

        $this->actingAs($this->guest)->deleteJson("/api/music/likes/{$first->id}")->assertNoContent();
        $this->getJson('/api/music/likes')->assertJsonPath('*.id', [$second->id]);
    }

    public function test_home_shows_recent_plays_newest_first_without_repeats(): void
    {
        [$a, $b] = [$this->track($this->creator, ['title' => 'A']), $this->track($this->creator, ['title' => 'B'])];

        $this->actingAs($this->guest)->postJson("/api/music/tracks/{$a->id}/play")->assertNoContent();
        $this->travel(1)->minute();
        $this->postJson("/api/music/tracks/{$b->id}/play");
        $this->travel(1)->minute();
        $this->postJson("/api/music/tracks/{$a->id}/play");

        $this->getJson('/api/music/home')
            ->assertOk()
            ->assertJsonPath('recently_played.*.title', ['A', 'B'])
            ->assertJsonPath('creators.0.display_name', 'Nova');
    }

    public function test_play_history_is_capped_per_listener(): void
    {
        $track = $this->track($this->creator);

        for ($i = 0; $i < Play::KEEP_PER_USER + 5; $i++) {
            Play::record($this->guest, $track);
        }

        $this->assertSame(Play::KEEP_PER_USER, Play::where('user_id', $this->guest->id)->count());
    }

    public function test_a_creator_page_lists_popular_tracks_and_albums(): void
    {
        $album = $this->album($this->creator, ['title' => 'First']);
        $hit = $this->track($this->creator, ['title' => 'Hit', 'album_id' => $album->id]);
        $this->track($this->creator, ['title' => 'Deep cut']);
        $this->track($this->creator, ['title' => 'Unreleased', 'published_at' => null]);
        Play::record($this->guest, $hit);

        $this->actingAs($this->guest)->getJson("/api/music/creators/{$this->creator->public_id}")
            ->assertOk()
            ->assertJsonPath('track_count', 2)
            ->assertJsonPath('popular.0.title', 'Hit')
            ->assertJsonPath('albums.*.title', ['First']);

        // People with nothing published have no page.
        $this->getJson('/api/music/creators/'.User::factory()->create()->public_id)->assertNotFound();
    }

    public function test_an_album_page_lists_its_tracks_in_order(): void
    {
        $album = $this->album($this->creator, ['title' => 'Night Drive']);
        $this->track($this->creator, ['title' => 'Two', 'album_id' => $album->id, 'track_no' => 2]);
        $this->track($this->creator, ['title' => 'One', 'album_id' => $album->id, 'track_no' => 1]);
        $this->track($this->creator, ['title' => 'Draft', 'album_id' => $album->id, 'published_at' => null]);

        $this->actingAs($this->guest)->getJson("/api/music/albums/{$album->id}")
            ->assertOk()
            ->assertJsonPath('tracks.*.title', ['One', 'Two'])
            ->assertJsonPath('track_count', 2)
            ->assertJsonPath('duration_ms', 360000);

        // An album with nothing published yet is only visible to its creator.
        $empty = $this->album($this->creator);
        $this->getJson("/api/music/albums/{$empty->id}")->assertNotFound();
    }

    public function test_a_guest_logging_out_takes_their_likes_and_history_with_them(): void
    {
        $track = $this->track($this->creator);
        $this->actingAs($this->guest)->putJson("/api/music/likes/{$track->id}");
        Play::record($this->guest, $track);

        $this->guest->delete();

        $this->assertDatabaseCount('music_likes', 0);
        $this->assertDatabaseCount('music_plays', 0);
        $this->assertModelExists($track);
    }
}
