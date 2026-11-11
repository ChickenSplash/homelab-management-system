<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A creator's grouping of their own tracks, like a release. Optional: a track needs no album.
        Schema::create('music_albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();
            $table->date('released_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        // The database owns everything but the file itself. The scanner only fills in a new
        // track from its tags once and keeps the file columns in step after that.
        Schema::create('music_tracks', function (Blueprint $table) {
            $table->id();
            // Restricted, so deleting a creator never silently empties everyone's playlists.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('album_id')->nullable()->constrained('music_albums')->nullOnDelete();
            $table->unsignedSmallInteger('track_no')->nullable();
            $table->string('title');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('bitrate')->nullable();
            $table->string('genre', 64)->nullable();
            $table->boolean('is_ai')->default(true);
            $table->text('lyrics')->nullable();
            $table->string('cover_path')->nullable();
            // Null while it waits in the creator's inbox.
            $table->timestamp('published_at')->nullable();

            // Relative to the music root, starting with the owner's public ID folder.
            $table->string('path')->unique();
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('mtime');
            // Size plus a hash of the first 64 KB: finds the track again after a rename or move.
            $table->string('fingerprint', 64)->index();
            // The file is gone. Kept hidden rather than deleted, so edits, playlists and likes survive.
            $table->timestamp('missing_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'published_at']);
            $table->index(['album_id', 'track_no']);
        });

        Schema::create('music_playlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 300)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        // Its own ID rather than a composite key, so a track can be in a playlist twice.
        Schema::create('music_playlist_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained('music_playlists')->cascadeOnDelete();
            $table->foreignId('track_id')->constrained('music_tracks')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestamp('created_at')->nullable();

            $table->index(['playlist_id', 'position']);
            $table->index('track_id');
        });

        Schema::create('music_likes', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained('music_tracks')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['user_id', 'track_id']);
            $table->index('track_id');
        });

        Schema::create('music_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained('music_tracks')->cascadeOnDelete();
            $table->timestamp('played_at');

            $table->index(['user_id', 'played_at']);
            $table->index('track_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_plays');
        Schema::dropIfExists('music_likes');
        Schema::dropIfExists('music_playlist_tracks');
        Schema::dropIfExists('music_playlists');
        Schema::dropIfExists('music_tracks');
        Schema::dropIfExists('music_albums');
    }
};
