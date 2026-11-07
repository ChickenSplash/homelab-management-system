# Plan: Homelab Management System + messaging service

A bespoke chat app (Discord / WhatsApp / Telegram inspired) running on the homelab.
This repo (formerly `projects-panel`) becomes the **Homelab Management System (HMS)**: a
front-end-free Laravel API that serves this chat app and any future homelab apps.

Tick items off as they land so this file stays the source of truth.

## Decisions

| # | Topic | Decision |
|---|---|---|
| 1 | Front-end framework | React + TypeScript + Vite + Tailwind 4 |
| 2 | Chat domain | `chat.chickensplash.dpdns.org` |
| 3 | Registration | Open |
| 4 | Panel front end | **Removed entirely.** HMS is API only |
| 5 | Who can DM whom | Anyone can DM anyone |
| 6 | Guests | Can do everything a registered user can |
| 7 | Joining groups | Groups are public (findable, join freely) or private (invite link) |
| 8 | Admins | Admins can promote others to admin. The creator is the owner: cannot be kicked or demoted |
| 9 | Last admin leaves | Promote the oldest moderator, else the oldest member |
| 10 | Guest lifetime | Until logout, or until their session expires (they can never log back in) |
| 11 | Identity | Every user has a unique `public_id`; `display_name` is free text, not unique, guests included |
| 12 | Runtime | FrankenPHP (Laravel Octane) instead of `artisan serve` |
| A | Public ID format | Random 8 characters, e.g. `k7m2qx9p` |
| B | Custom handles (`@name`) | Not now, maybe later |
| C | Session length | 30 days since last visit, for everyone |
| D | Abuse | Rate limits on messages and guest creation, plus a block-user feature in phase 2 |
| E | Development | Local clone on the PC, deploy to the homelab |
| | Short name | `hms` (containers, hostname, homelab folder) |
| | `chickensplash.dpdns.org` | Tunnel hostname removed (the panel's pages are gone) |
| | Projects feature | Dropped: model, table, API endpoint, pages and tests |
| | Contact inbox | Email only for now; messages still stored in the `messages` table |

## Architecture

```
browser ──https──► chat-ui (nginx) ──► hms (FrankenPHP, :3000) ─┐
   ▲                    │                                        ├─ SQLite (WAL)
   └──websocket─────────┴──────────► hms-reverb ◄── hms-queue ───┤
                                                   hms-scheduler ┘
portfolio / glassbox ──/api/contact──► hms
```

- **hms**: the Laravel API. One image, four containers started with different commands:
  - `hms`: HTTP API (Octane + FrankenPHP)
  - `hms-reverb`: WebSocket server (Laravel Reverb), pushes live events to browsers
  - `hms-queue`: background job worker (mail and other slow jobs)
  - `hms-scheduler`: timed jobs (guest cleanup)
- **chat-ui**: separate repo, nginx serving the built React app and proxying `/api`,
  `/sanctum` and `/app` (Reverb WebSocket) so everything is same-origin.
- **Auth**: Sanctum SPA cookie (session) auth for browsers. Sanctum bearer tokens remain
  available for scripts and future apps.

## Data model

- **users**: `id`, `public_id` (unique, 8 chars), `display_name`, `email` (nullable, unique),
  `password` (nullable), `is_guest`, timestamps
- **friendships**: `requester_id`, `addressee_id`, `accepted_at` (null while pending), one row per pair
- **blocks**: `blocker_id`, `blocked_id`. Separate from friendships: blocks are one-way and two people can block each other
- **conversations**: `type` (dm / group), `dm_key` (one DM per pair), `created_by`, `last_message_at`; groups add `name`, `visibility`, `invite_code` in phase 4
- **conversation_members**: `conversation_id`, `user_id`, `role` (owner / admin / moderator / member), `last_read_message_id`
- **chat_messages**: `conversation_id`, `user_id` (null once the author is deleted), `body`, `nonce`, `edited_at`, soft deletes
- **messages**: unchanged, the portfolio contact form

DMs are two-member conversations, so DMs and groups share one message and real-time path.

## Permissions

| Action | Member | Moderator | Admin | Owner |
|---|---|---|---|---|
| Send and read | ✓ | ✓ | ✓ | ✓ |
| Kick someone of lower rank | | ✓ | ✓ | ✓ |
| Assign roles (up to admin) | | | ✓ | ✓ |
| Be kicked or demoted | ✓ | ✓ | ✓ | |

Enforced with Laravel Policies, with a test per rule.

## Phases

### Phase 0 · Groundwork

**1. Rename and clone**
- [x] Rename GitHub repo `projects-panel` → `homelab-management-system`
- [x] Clone to `~/Projects/Apps/homelab-management-system`
- [x] Homelab: rename `~/homelab/projects-panel` → `~/homelab/hms`, update its git remote
- [x] Compose project `hms`; services `hms`, `hms-reverb`, `hms-queue`, `hms-scheduler`; README renamed

**2. Strip to an API**
- [x] Remove Livewire, Volt, Blade pages, layouts, components, Tailwind, Vite, `package.json`, Node build stage
- [x] Remove projects: model, factory, migration (plus a drop-table migration for live data), endpoint, tests
- [x] Remove the `/messages` admin page and its gate; keep `/api/contact` and the mail template
- [x] `GET /` returns a small JSON health response
- [x] Users migration: `public_id`, `username` → `display_name` (not unique), `is_guest`, nullable `email` / `password`; backfill `public_id`
- [x] Auth API under `/api/auth`: `register`, `login`, `guest`, `logout`, `me`, `PATCH me` (display name). Guest logout deletes the guest
- [x] Rate limits on login, register and guest creation

**3 to 8. Platform**
- [x] Sanctum SPA cookie auth (`statefulApi`), stateful domains configurable
- [x] Reverb + broadcasting, private `user.{public_id}` channel, `/api/broadcasting/auth`
- [x] Database queue (jobs table) and `POST /api/ping` that broadcasts a test event to yourself
- [x] Scheduler: hourly `guests:prune` deletes guests with no live session
- [x] Session lifetime 30 days
- [x] SQLite WAL mode + busy timeout
- [x] Tests for every endpoint and guest pruning; migration run (and rolled back) on a copy of the live database
- [x] Dependencies updated: the old lock had 14 advisories, including a critical `laravel/framework` one

**9. Runtime and cut-over**
- [x] Dockerfile on FrankenPHP + Octane; compose with the four services
- [x] Copy volume `projects-panel_database` → `hms_database` (old volume kept as a backup)
- [x] `project-panel:3000` → `hms:3000` in `glassbox/server.js` and `portfolio/nginx.conf`, restart both
- [ ] Remove the `chickensplash.dpdns.org` tunnel hostname (Cloudflare dashboard, by hand)
- [x] Update the homelab skill file

**10 to 13. Chat front end**
- [x] New repo `~/Projects/Apps/chat-ui`: Vite + React + TS + Tailwind 4, dev proxy to a local HMS
- [x] nginx container on `edge` proxying to `hms` and `hms-reverb`
- [x] Placeholder page: log in / register / join as guest, shows "connected as <name> (#public_id)", live ping round trip
- [x] Tunnel hostname `chat.chickensplash.dpdns.org` → `http://chat-ui:80` (Cloudflare dashboard, by hand)

**Done when**: the contact form still delivers, and the chat placeholder logs in (user or guest)
and receives a live ping.

### Phase 1 · Auth UI
- [x] Login, register, guest join and logout screens (guests confirm before logout deletes them)
- [x] Profile: change display name; change password (`PUT /api/auth/password`, not for guests)
- [x] Playwright e2e tests (Chromium + Firefox) in chat-ui, run against a local Docker copy of the stack

### Phase 2 · Friends
- [x] Request by `public_id` (exact ID only, no name search), accept, decline, cancel, unfriend
- [x] Asking someone who already asked you makes you friends at once
- [x] Block / unblock: silent (requests look sent but never arrive), and blocking unfriends
- [x] Live `friends.changed` events; incoming request badge
- [x] Guests can use friends; their friendships go when they log out
- [x] Discord-style layout: server rail, sidebar (Friends, DMs, user panel), main panel; dark only for now
- Online status waits for phase 5

### Phase 3 · DMs
- [x] Start a DM with anyone (from the friends list, or by ID in the sidebar)
- [x] Live messages over each member's user channel; history in pages of 50
- [x] Send receipts: a message shows grey until HMS saves it, red with Retry / Discard if it fails;
  the client `nonce` stops duplicates on retry and when the WebSocket copy arrives first
- [x] Unread badges per DM, on the rail and in the tab title; no "seen" receipts
- [x] Delete your own messages; plain text with clickable links; 2,000 character limit
- [x] Rate limit 10 messages per 10 seconds; blocked either way means no messages either way
- [x] A deleted guest's messages stay, shown from "Deleted user"; a DM with nobody left is removed
- [x] Playwright: two browsers befriend, chat, delete, fail and retry a send, and block

### Phase 4 · Groups
- Create (creator is owner), public / private, invite links, browse public groups
- Roles, kick, leave (with last-admin hand-over)

### Phase 5 · Polish
- Online presence, typing indicator, unread divider, mobile layout
- Playwright e2e tests grow with each phase (headless)

### Phase 6 · Later
- Edits, replies, reactions, attachments (maybe via copyparty), custom handles, voice

## Notes

- Live events go through `App\Support\Live`, which broadcasts `LiveUpdate` to each user's
  private channel and reports (never throws) if Reverb is down.
- Live events implement `ShouldBroadcastNow`: sent to Reverb during the request (~15 ms
  round trip). Queued broadcasts waited up to a second for the worker's next poll.
- SQLite transactions are `IMMEDIATE`: deferred ones failed with "database is locked" under
  concurrent writes instead of waiting out `busy_timeout`.
- No PHP on the PC: run composer and tests in a `composer:2` container (Docker Desktop locally).
- To test the whole stack locally, run HMS, Reverb, the queue and chat-ui with Docker Compose,
  with `SANCTUM_STATEFUL_DOMAINS=localhost:8090`, then `BASE_URL=http://localhost:8090 npx playwright test`.

- Messages are stored readable on the server; this is not end-to-end encrypted.
- Guests that close the tab without logging out are removed once their session expires.
