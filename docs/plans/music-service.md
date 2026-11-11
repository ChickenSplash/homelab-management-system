# Music service

A Spotify-style streaming app for AI-generated music, with HMS as the back end and
`music-ui` (React, cloned from chat-ui's skeleton) as the front end, at
`music.chickensplash.dpdns.org`.

## Decisions

- **Accounts:** HMS users, no change to `users`. Anyone signed in can listen, guests included.
  Only registered users can own music (guests are deleted when they log out).
- **The database owns the catalogue.** Files only bring audio. The scanner fills a new track in
  from its tags once; titles, albums, art, lyrics and publishing are edited in the studio.
- **No uploads** for now. Creators' files go in `/srv/files/Music/<public_id>/` through copyparty
  (its root volume already covers that path, writable by its one account).
- **A track belongs to its creator** (the user whose folder it's in). There's no artist table.
  Albums are optional groupings owned by the creator.
- **Every track has `is_ai`**, true by default, shown as an AI badge.
- **Playlists are private.** Likes are "Liked Songs". The last 200 plays per listener feed
  "Recently played" and a creator's "Popular".
- **Streams go through nginx** (`X-Accel-Redirect`), so a slow listener never ties up an Octane
  worker. HMS still checks the session on every range request.

## Tables

| Table | Holds |
|---|---|
| `music_albums` | `user_id`, `title`, `description`, `cover_path`, `released_at` |
| `music_tracks` | `user_id` (restrict on delete), `album_id`, `track_no`, `title`, `duration_ms`, `bitrate`, `genre`, `is_ai`, `lyrics`, `cover_path`, `published_at`; file columns `path` (unique), `size`, `mtime`, `fingerprint`, `missing_at` |
| `music_playlists` | `user_id`, `name`, `description` |
| `music_playlist_tracks` | `playlist_id`, `track_id`, `position`, `created_at` (own `id`, so repeats are allowed) |
| `music_likes` | `user_id`, `track_id`, `created_at` (primary key on the pair) |
| `music_plays` | `user_id`, `track_id`, `played_at` |

Everything per user cascades when the user goes. Cover files live in `MUSIC_COVERS`
(`/app/data/music-covers` on the database volume).

## Status (11 November 2026)

- [x] Migration, models, scanner (`music:scan`, scheduled every five minutes)
- [x] Listener API: home, search, track, album, creator, stream, covers, plays, likes
- [x] Playlists: create (optionally with tracks), rename, delete, append, move, remove
- [x] Studio API: list, edit, bulk edit, covers, albums, Scan now (only reports your own folder)
- [x] music-ui: Spotify-style layout (top bar with search, library sidebar, page, now playing
      and lyrics or queue panel, player bar), phone layout (tabs, mini and full-screen player),
      Media Session controls, studio pages, resizable and collapsible animated sidebars
- [x] Tests: 147 backend; Playwright on Chromium and Firefox (7 tests) and WebKit phone (1),
      against the local stack in music-ui's `dev/docker-compose.yml`
- [ ] Deploy (below)

## Deploy

1. On the homelab, as `ecorreia`, create the folder (before compose does it as root):
   `mkdir -p /srv/files/Music/<your public ID>` (shown in account settings, `#xxxxxxxx`).
2. Back up the database:
   `docker exec hms php artisan tinker --execute='DB::statement("VACUUM INTO ?", ["/app/data/backup-before-music-2026-11-11.sqlite"]);'`
3. HMS: merge the `music` branch, then in `~/homelab/hms`: `git pull`, add
   `music.chickensplash.dpdns.org` to `SANCTUM_STATEFUL_DOMAINS` in `.env` if it's set there,
   then `docker compose up -d --build`.
4. music-ui: clone to `~/homelab/music-ui`, then `docker compose up -d --build`.
5. Cloudflare tunnel: public hostname `music.chickensplash.dpdns.org` → `http://music-ui:80`.
6. Put MP3s in your folder, open the studio, Scan now, publish.

## Later

- Uploads from the studio (tracks would still go into the uploader's folder).
- Public playlists with share links.
- Reordering by drag on a phone (desktop rows drag already).
