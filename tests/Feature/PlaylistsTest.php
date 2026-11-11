<?php

namespace Tests\Feature;

use App\Models\Music\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MakesMusic;
use Tests\TestCase;

class PlaylistsTest extends TestCase
{
    use MakesMusic;
    use RefreshDatabase;

    private User $creator;

    private User $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMusicFolders();
        $this->creator = User::factory()->create();
        $this->listener = User::factory()->guest()->create();
    }

    private function newPlaylist(array $input = []): int
    {
        return $this->actingAs($this->listener)->postJson('/api/music/playlists', $input)->assertCreated()->json('id');
    }

    public function test_new_playlists_get_a_numbered_name_and_can_start_with_tracks(): void
    {
        $track = $this->track($this->creator);

        $this->newPlaylist();
        $id = $this->newPlaylist(['track_ids' => [$track->id]]);

        $this->getJson('/api/music/playlists')
            ->assertJsonPath('*.name', ['My Playlist #2', 'My Playlist #1'])
            ->assertJsonPath('0.track_count', 1);

        $this->getJson("/api/music/playlists/{$id}")->assertJsonPath('entries.0.track.id', $track->id);
    }

    public function test_tracks_are_appended_in_order_repeats_allowed_and_hidden_ones_dropped(): void
    {
        [$a, $b] = [$this->track($this->creator, ['title' => 'A']), $this->track($this->creator, ['title' => 'B'])];
        $draft = $this->track($this->creator, ['published_at' => null]);
        $id = $this->newPlaylist();

        $this->postJson("/api/music/playlists/{$id}/tracks", ['track_ids' => [$b->id, $draft->id, $a->id, 999]])
            ->assertOk()
            ->assertJsonPath('added', 2);
        $this->postJson("/api/music/playlists/{$id}/tracks", ['track_ids' => [$b->id]]);

        $this->getJson("/api/music/playlists/{$id}")->assertJsonPath('entries.*.track.title', ['B', 'A', 'B']);
    }

    public function test_entries_can_be_moved_and_removed(): void
    {
        $tracks = collect(['A', 'B', 'C'])->map(fn ($title) => $this->track($this->creator, ['title' => $title]));
        $id = $this->newPlaylist(['track_ids' => $tracks->pluck('id')->all()]);
        $entries = $this->getJson("/api/music/playlists/{$id}")->json('entries.*.entry_id');

        // C to the top.
        $this->putJson("/api/music/playlists/{$id}/tracks/{$entries[2]}", ['position' => 0])->assertNoContent();
        $this->getJson("/api/music/playlists/{$id}")->assertJsonPath('entries.*.track.title', ['C', 'A', 'B']);

        $this->deleteJson("/api/music/playlists/{$id}/tracks/{$entries[0]}")->assertNoContent();
        $this->getJson("/api/music/playlists/{$id}")->assertJsonPath('entries.*.track.title', ['C', 'B']);

        $this->assertSame([0, 1], Playlist::find($id)->entries()->pluck('position')->all());
    }

    public function test_moving_counts_only_the_entries_the_listener_can_see(): void
    {
        [$a, $hidden, $b, $c] = collect(['A', 'Hidden', 'B', 'C'])->map(fn ($title) => $this->track($this->creator, ['title' => $title]))->all();
        $id = $this->newPlaylist(['track_ids' => [$a->id, $hidden->id, $b->id, $c->id]]);
        $hidden->update(['published_at' => null]);
        $entries = $this->getJson("/api/music/playlists/{$id}")->json('entries.*.entry_id');

        // C to position 1 of what's on screen (A, B, C): just before B, wherever the hidden one is.
        $this->putJson("/api/music/playlists/{$id}/tracks/{$entries[2]}", ['position' => 1])->assertNoContent();
        $this->getJson("/api/music/playlists/{$id}")->assertJsonPath('entries.*.track.title', ['A', 'C', 'B']);

        $hidden->update(['published_at' => now()]);
        $this->getJson("/api/music/playlists/{$id}")->assertJsonPath('entries.*.track.title', ['A', 'Hidden', 'C', 'B']);
    }

    public function test_an_unpublished_track_drops_out_of_playlists_until_it_returns(): void
    {
        $track = $this->track($this->creator);
        $id = $this->newPlaylist(['track_ids' => [$track->id]]);

        $track->update(['published_at' => null]);
        $this->getJson("/api/music/playlists/{$id}")->assertJsonCount(0, 'entries')->assertJsonPath('track_count', 0);

        $track->update(['published_at' => now()]);
        $this->getJson("/api/music/playlists/{$id}")->assertJsonCount(1, 'entries');
    }

    public function test_playlists_are_private(): void
    {
        $id = $this->newPlaylist();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson("/api/music/playlists/{$id}")->assertNotFound();
        $this->patchJson("/api/music/playlists/{$id}", ['name' => 'Mine now'])->assertNotFound();
        $this->deleteJson("/api/music/playlists/{$id}")->assertNotFound();
        $this->getJson('/api/music/playlists')->assertExactJson([]);
    }

    public function test_renaming_validates_and_deleting_removes_it(): void
    {
        $id = $this->newPlaylist();

        $this->patchJson("/api/music/playlists/{$id}", ['name' => '   '])->assertJsonValidationErrors('name');
        $this->patchJson("/api/music/playlists/{$id}", ['name' => 'Road trip', 'description' => 'Loud'])
            ->assertOk()
            ->assertJsonPath('name', 'Road trip');

        $this->deleteJson("/api/music/playlists/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('music_playlists', ['id' => $id]);
    }

    public function test_an_entry_from_another_playlist_cannot_be_touched(): void
    {
        $track = $this->track($this->creator);
        $mine = $this->newPlaylist(['track_ids' => [$track->id]]);
        $other = $this->newPlaylist(['track_ids' => [$track->id]]);
        $otherEntry = $this->getJson("/api/music/playlists/{$other}")->json('entries.0.entry_id');

        $this->deleteJson("/api/music/playlists/{$mine}/tracks/{$otherEntry}")->assertNotFound();
    }
}
