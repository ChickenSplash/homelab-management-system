# Homelab Management System (HMS)

The API behind the homelab's apps: accounts (including guests), real-time events, and the
portfolio's contact form. No front end of its own; each app brings its own and talks to this.

The current roadmap is in [docs/plans/messaging-service.md](docs/plans/messaging-service.md).

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
| `hms-scheduler` | Timed jobs (`guests:prune`, hourly) |

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
SANCTUM_STATEFUL_DOMAINS=chat.chickensplash.dpdns.org
REVERB_ALLOWED_ORIGINS=chat.chickensplash.dpdns.org

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
| `POST` | `/api/broadcasting/auth` | Private channel auth (`private-user.{id}`). Events on it: `ping`, `friends.changed`, `message.sent`, `message.deleted`, `conversation.read`, `conversations.changed`. Group messages reach each member here too |
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
| `POST` | `/api/conversations/{c}/messages` | `body` (max 2,000), `nonce` (client temp ID; a retry returns the saved copy) |
| `DELETE` | `/api/conversations/{c}/messages/{m}` | Delete your own message |
| `POST` | `/api/conversations/{c}/read` | `message_id`: move your read marker forward |
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

Users are identified by `id`, a random 8 character public ID. Display names are free text and
need not be unique.

## Development

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve & php artisan reverb:start & php artisan queue:work
php artisan test
```
