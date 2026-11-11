# Homelab Management System (HMS)

The API behind the homelab's apps: accounts (including guests), real-time events, the music
service and the portfolio's contact form. No front end of its own; each app brings its own and
talks to this ([chat-ui](https://github.com/ChickenSplash/chat-ui), music-ui).

Plans: [messaging](docs/plans/messaging-service.md) (closed), [music](docs/plans/music-service.md).

## Stack

- Laravel 13 on Octane + FrankenPHP
- Laravel Reverb (WebSockets), a database queue and the scheduler
- SQLite (WAL mode)
- Laravel Sanctum: session cookies for browser apps, bearer tokens for scripts

## Containers

One image, four services on the external `edge` network:

| Service | Does |
|---|---|
| `hms` | The HTTP API on port 3000. Runs migrations on start |
| `hms-reverb` | WebSocket server on port 8080 |
| `hms-queue` | Background jobs (broadcasts) |
| `hms-scheduler` | Timed jobs (`guests:prune` hourly, `presence:sync` every minute, `music:scan` every five minutes) |

No port is published, so only the tunnel and other `edge` containers can reach them.

```bash
docker network create edge   # only if it does not exist yet
docker compose up -d --build
```

The SQLite file lives in the `hms_database` volume, so data survives `docker compose down`.

## Configuration

Put these in `.env` next to `docker-compose.yml`:

```bash
APP_KEY=base64:...                 # docker compose run --rm --no-deps hms php artisan key:generate --show
REVERB_APP_KEY=...                 # openssl rand -hex 16
REVERB_APP_SECRET=...              # openssl rand -hex 16

# Browser apps allowed to sign in, and to open WebSockets (comma separated hosts)
SANCTUM_STATEFUL_DOMAINS=chat.chickensplash.dpdns.org,music.chickensplash.dpdns.org
REVERB_ALLOWED_ORIGINS=chat.chickensplash.dpdns.org

# The music folder on the host, mounted read-only at /music (default /srv/files/Music).
# Create it as your user first, or Docker makes a root-owned one copyparty can't write to.
MUSIC_PATH=/srv/files/Music

# Contact form mail (leave MAIL_MAILER unset to log it instead: docker logs hms)
ADMIN_EMAIL=you@gmail.com
MAIL_MAILER=smtp
MAIL_USERNAME=you@gmail.com
MAIL_PASSWORD=your-16-char-app-password   # Google account > Security > App passwords
```

## Connecting a browser app

Serve the app from its own nginx and proxy these to HMS, so everything is same-origin:

- `/api/` and `/sanctum/` → `http://hms:3000`
- `/app/` and `/apps/` (WebSocket upgrade) → `http://hms-reverb:8080`

Set `X-Forwarded-For` to `$http_cf_connecting_ip` and keep the `Host` header. Then add the
app's host to `SANCTUM_STATEFUL_DOMAINS` and `REVERB_ALLOWED_ORIGINS`.

Sign-in flow: `GET /sanctum/csrf-cookie`, then send the `XSRF-TOKEN` cookie back as the
`X-XSRF-TOKEN` header on every write.

## API

| Method | Path | |
|---|---|---|
| `POST` | `/api/auth/register` | `display_name`, `email`, `password`, `password_confirmation` |
| `POST` | `/api/auth/login` | `email`, `password`, `remember` |
| `POST` | `/api/auth/guest` | `display_name`. A guest lasts until logout or session expiry |
| `POST` | `/api/auth/logout` | Deletes the account if it is a guest |
| `GET` / `PATCH` | `/api/auth/me` | Current user; change `display_name` |
| `PUT` | `/api/auth/password` | `current_password`, `password`, `password_confirmation` (not for guests) |
| `GET` | `/api/realtime` | Reverb app key for the WebSocket client |
| `POST` | `/api/broadcasting/auth` | Private channel auth (`private-user.{id}`). Events on it: `ping`, `friends.changed`, `message.sent`, `message.updated`, `message.deleted`, `conversation.read`, `conversations.changed`, `presence.changed`, `typing`. Group messages reach each member here too |
| `POST` | `/api/ping` | Broadcasts a `ping` to your own channel |
| `GET` | `/api/friends` | Friends and requests: `[{user, status: friend/incoming/outgoing, since}]` |
| `POST` | `/api/friends` | `id`: send a request (accepts at once if they already asked you) |
| `POST` | `/api/friends/{id}/accept` | Accept an incoming request |
| `DELETE` | `/api/friends/{id}` | Decline, cancel or unfriend |
| `GET` | `/api/blocks` | People you have blocked |
| `PUT` / `DELETE` | `/api/blocks/{id}` | Block (also unfriends) / unblock. Blocking is silent |
| `POST` | `/api/dms` | `id`: find or start the DM with someone |
| `GET` | `/api/conversations` | Your DMs and groups, with last message and `unread_count`; groups add `name`, `role`, `member_count`, `invite_code` |
| `GET` | `/api/conversations/{c}` | One conversation |
| `GET` | `/api/conversations/{c}/messages` | 50 messages, oldest first; `?before={messageId}` pages back |
| `POST` | `/api/conversations/{c}/messages` | `body` (max 2,000), `nonce` (client temp ID; a retry returns the saved copy), optional `reply_to_message_id` (live text in this conversation). Returns a shallow `reply_to` preview |
| `DELETE` | `/api/conversations/{c}/messages/{m}` | Delete your own message |
| `POST` | `/api/conversations/{c}/read` | `message_id`: move your read marker forward |
| `PATCH` | `/api/conversations/{c}/messages/{m}` | Edit your own text: `body`, up to 2,000 characters. Sets `edited_at` and broadcasts `message.updated`, without new unread messages |
| `POST` | `/api/conversations/{c}/typing` | Tell the other members you're typing |
| `GET` | `/api/presence` | Everyone you can see who isn't offline (yourself included): `{public_id: status}` |
| `POST` | `/api/presence/active` | You did something; ten quiet minutes means idle |
| `PUT` | `/api/presence/status` | `status`: online / idle / dnd / invisible |
| `GET` | `/api/groups` | Public groups, biggest first; `?q=` searches names |
| `POST` | `/api/groups` | `name`, `description`, `visibility` (public / private). You become the owner |
| `PATCH` / `DELETE` | `/api/groups/{g}` | Edit details (admin+) / delete the group (owner) |
| `POST` | `/api/groups/{g}/join` / `leave` | Join a public group / leave (the owner's role passes on) |
| `POST` | `/api/groups/{g}/invite` | New invite code (admin+) |
| `POST` | `/api/groups/{g}/transfer` | `id`: hand ownership to a member (owner) |
| `GET` | `/api/groups/{g}/members` | Members with roles, highest rank first |
| `PUT` / `DELETE` | `/api/groups/{g}/members/{id}` | `role` (admin / mod / member; admin+) / kick (mod+) |
| `GET` | `/api/groups/{g}/bans` | Banned users (admin+) |
| `PUT` / `DELETE` | `/api/groups/{g}/bans/{id}` | Ban (also removes) / unban (admin+) |
| `GET` / `POST` | `/api/invites/{code}` | Preview the group / join it, private or public |
| `POST` | `/api/contact` | Portfolio contact form: `email`, `subject`, `message` (5 a minute per IP) |

### Music

Anyone signed in (guests too) can listen. Listeners only ever see *listed* tracks: published,
with their file present. Tracks are shaped `{id, title, duration_ms, is_ai, genre, track_no,
has_lyrics, cover_url, creator, album: {id, title}, liked, published_at}`.

| Method | Path | |
|---|---|---|
| `GET` | `/api/music/home` | `recently_played`, `new_tracks`, `albums`, `creators` |
| `GET` | `/api/music/search?q=` | `tracks`, `albums`, `creators`. Matches titles, genres, album titles and creator names; no `q` lists everything |
| `GET` | `/api/music/tracks/{t}` | One track, plus `lyrics` |
| `GET` | `/api/music/tracks/{t}/stream` | The MP3, with Range support. Via nginx's `X-Accel-Redirect` when `MUSIC_ACCEL_PREFIX` is set |
| `GET` | `/api/music/tracks/{t}/cover`, `/api/music/albums/{a}/cover` | Cover art (URLs carry a version, cached for good) |
| `POST` | `/api/music/tracks/{t}/play` | Count a play (the app sends it after 30 s). Keeps each listener's last 200 |
| `GET` | `/api/music/albums/{a}` | Album with its tracks, `track_count`, `duration_ms` |
| `GET` | `/api/music/creators/{id}` | `creator`, `popular` (by plays), `tracks`, `albums` |
| `GET` | `/api/music/likes` | Liked Songs, newest first, with `added_at` |
| `PUT` / `DELETE` | `/api/music/likes/{t}` | Like / unlike |
| `GET` / `POST` | `/api/music/playlists` | Your playlists / a new one (`name` defaults to "My Playlist #n", optional `track_ids`) |
| `GET` / `PATCH` / `DELETE` | `/api/music/playlists/{p}` | Private to their owner. `PATCH`: `name`, `description` |
| `POST` | `/api/music/playlists/{p}/tracks` | `track_ids`: append, in order, repeats allowed |
| `PUT` / `DELETE` | `/api/music/playlists/{p}/tracks/{entry}` | Move (`position`, counted among the visible entries) / remove |

The stream and cover routes run on the `web` middleware group rather than Sanctum's API
stack: `<audio>` and `<img>` requests don't reliably send the Origin or Referer Sanctum looks
for (iOS plays audio from a separate process), so they authenticate with the session cookie alone.

**Studio** (registered accounts only; guests get 403):

| Method | Path | |
|---|---|---|
| `GET` | `/api/music/studio` | `folder` (your public ID), all your `tracks` (inbox, published, missing) and `albums` |
| `PATCH` | `/api/music/studio/tracks/{t}` | `title`, `album_id`, `track_no`, `genre`, `is_ai`, `lyrics`, `published` |
| `PATCH` | `/api/music/studio/tracks` | `ids` plus `album_id`, `is_ai` or `published`, for several at once |
| `POST` / `DELETE` | `/api/music/studio/tracks/{t}/cover` | Upload (`cover`: JPEG, PNG or WebP up to 5 MB) / remove |
| `POST` / `PATCH` / `DELETE` | `/api/music/studio/albums[/{a}]` | `title`, `description`, `released_at`. Deleting keeps the tracks |
| `POST` | `/api/music/studio/albums/{a}/cover` | Upload the album's cover |
| `POST` | `/api/music/studio/scan` | Scan now; returns what changed in your own folder |

Users are identified by `id`, a random 8 character public ID. Display names are free text and
need not be unique.

## Music files

There's no upload: files are put in the music folder (through copyparty), under a folder
named after the creator's public ID, e.g. `/srv/files/Music/ajb4wuzy/Album/track.mp3`.
`music:scan` (every five minutes, or Scan now in the studio) keeps `music_tracks` in step:

- A new MP3 becomes an unpublished track, filled in once from its ID3 tags (title, genre,
  track number, lyrics, embedded cover). From then on the database owns those details.
- A changed file only has its size, duration and fingerprint refreshed.
- A moved or renamed file is found again by its fingerprint (size plus the first 64 KB), so
  its details, playlist entries and likes stay.
- A vanished file is hidden (`missing_at`), not deleted, and comes back if the file does.
- Folders that aren't a registered user's public ID are ignored. Guests can't own music.

A creator with tracks can't be deleted (`restrictOnDelete`), so removing an account never
silently empties other people's playlists.

## Development

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve & php artisan reverb:start & php artisan queue:work
php artisan test
```
