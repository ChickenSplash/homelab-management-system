# Plan: Homelab Management System + messaging service

A bespoke chat app (Discord / WhatsApp / Telegram inspired) running on the homelab.
This repo (formerly `projects-panel`) becomes the **Homelab Management System (HMS)**: a
front-end-free Laravel API that serves this chat app and any future homelab apps.

**Status: closed, 7 November 2026.** Phases 0 to 5 and the final editing/replies batch are
complete. The user chose to stop here. Reactions, attachments, custom handles, voice and the
visual redesign are deferred, not outstanding work for this plan.

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
  - `hms-scheduler`: timed jobs (guest cleanup and presence reconciliation)
- **chat-ui**: separate repo, nginx serving the built React app and proxying `/api`,
  `/sanctum` and `/app` (Reverb WebSocket) so everything is same-origin.
- **Auth**: Sanctum SPA cookie (session) auth for browsers. Sanctum bearer tokens remain
  available for scripts and future apps.

## Data model

Chat tables took a `chat_` prefix on 2026-11-12 (`2026_11_12_000000_prefix_chat_tables`), to
match the music ones; their models are in `App\Models\Chat` and their routes in `routes/chat.php`.


- **users**: `id`, `public_id` (unique, 8 chars), `display_name`, `email` (nullable, unique),
  `password` (nullable), `is_guest`, `status` (chosen: online / idle / dnd / invisible),
  `presence` (what others last heard: online / idle / dnd / offline), `connected_at` (null while
  no tab is connected), `active_at`, timestamps
- **chat_friendships**: `requester_id`, `addressee_id`, `accepted_at` (null while pending), one row per pair
- **chat_blocks**: `blocker_id`, `blocked_id`. Separate from friendships: blocks are one-way and two people can block each other
- **chat_conversations**: `type` (dm / group), `dm_key` (one DM per pair), `created_by`, `last_message_at`; groups add `name`, `description`, `visibility` (public / private), `invite_code`
- **chat_conversation_members**: `conversation_id`, `user_id`, `role` (owner / admin / mod / member), `last_read_message_id`
- **chat_messages**: `conversation_id`, `user_id` (null once the author is deleted), `type` (text / system), `body`, `meta` (system events: who did what), `nonce`, `edited_at`, nullable `reply_to_message_id` (self-reference), soft deletes
- **chat_conversation_bans**: `conversation_id`, `user_id`, `banned_by`
- **messages**: unchanged, the portfolio contact form

DMs are two-member conversations, so DMs and groups share one message and real-time path.

## Permissions

| Action | Member | Moderator | Admin | Owner |
|---|---|---|---|---|
| Send and read | ✓ | ✓ | ✓ | ✓ |
| Kick someone of lower rank | | ✓ | ✓ | ✓ |
| Assign roles (up to admin) | | | ✓ | ✓ |
| Be kicked or demoted | ✓ | ✓ | ✓ | |

Enforced server-side with membership and role checks; editing uses `ChatMessagePolicy`.
Tests cover each permission rule.

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
- [x] Remove the `chickensplash.dpdns.org` tunnel hostname (Cloudflare dashboard, by hand)
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
- [x] Create (creator is owner; up to 10 owned each, guests included), name, description
- [x] Public (Explore, search by name, biggest first) or private (invite link only)
- [x] One invite link per group, no expiry; any member can copy it, admins can reset it
- [x] Roles owner > admin > mod > member; you act only on lower ranks. Admins give roles up to
  admin, only the owner demotes admins; the owner can transfer ownership or delete the group
- [x] Mods and above kick and delete lower ranks' messages; admins and above ban and unban
  (bans stop rejoining by Explore or link, and can be made before someone joins)
- [x] Owner leaves (or a guest owner logs out): the oldest of the highest remaining rank takes
  over; the last one out deletes the group
- [x] System messages for joins, leaves, kicks, bans, role and owner changes; they don't count as unread
- [x] Blocked people's messages collapse to "Blocked message · Show"
- [x] 200 member cap; a message to a group goes to every member's channel in one broadcast
- [x] Rail icons with unread badges, group sidebar, member list, group settings, Explore,
  create / join page and `/invite/:code` landing page
- [x] Tests: one per permission rule (HMS), and Playwright for joining, invites, roles, kicks,
  bans, hand-over and blocked messages

### Phase 5 · Polish
- [x] Online status, near instant: Reverb fires `ChannelCreated` / `ChannelRemoved` as a user's
  first tab joins their private channel and their last one leaves. The listener (inside the
  Reverb process) queues `SyncPresence`, which asks Reverb's HTTP API whether they're
  connected, so going offline waits 5 s and a reload never flashes it
- [x] Dead connections (laptop shut, Wi-Fi gone): Reverb pings quiet sockets after 20 s
  (`REVERB_APP_PING_INTERVAL`) and HMS runs its prune and ping jobs every 10 s rather than
  every 60 s. A frozen client showed offline after 29 s
- [x] `presence:sync` every minute: fixes anyone Reverb and the database disagree about (a
  Reverb restart sends no "left" events) and marks people idle after 10 quiet minutes
- [x] Online, idle, do not disturb, invisible; seen by friends and anyone you share a DM or
  group with, never by people blocked either way
- [x] Typing indicator: sent at most every 3 s while typing, shown for 5 s or until the message
  arrives; "Several people are typing…" from 4
- [x] Unread divider: a red "New" line where you'd read up to when you opened the chat (or
  switched tabs), a "N new messages · Jump · Mark as read" banner while it's off screen, Esc dismisses
- [x] Phone layout (under 768 px): navigation or page, a menu button back, the member list
  slides over the chat; installable (web app manifest, no service worker)
- [x] Tests: presence, statuses, typing (HMS); Playwright for status changes, closing a tab,
  reloads, typing, the divider and banner, and a WebKit run at iPhone size

### Phase 6 · Final batch: editing and replies
- [x] Edit your own text messages in DMs and groups, no time limit while still a member.
  Nobody, including owners/admins, can edit another person's text. System/deleted messages
  cannot be edited; blocked DMs and DMs with a deleted partner reject edits.
- [x] `PATCH /api/conversations/{conversation}/messages/{message}` with scoped binding,
  `ChatMessagePolicy::update`, body validation (nonblank, up to 2,000 characters) and the
  existing message rate limit. No-op saves don't set `edited_at` or broadcast.
- [x] Inline editor: Enter saves, Shift+Enter adds a line, Esc cancels; an empty composer's
  Up arrow edits your latest loaded message. Failed saves retain the draft for retry.
  An `(edited)` label shows the timestamp on hover, with no visible edit history.
- [x] Edits broadcast `message.updated` and replace text/previews in place. They do not
  bump conversation order, move read markers or add unread messages.
- [x] Replies use a nullable self-reference, `reply_to_message_id`. Sending validates that
  the target is a live text message in the same conversation. The composer shows who you're
  replying to and supports cancellation; pending/failed sends preserve the reply target.
- [x] Shallow author/text preview (up to 200 characters), kept current on edits. Deleted
  originals show `Original message deleted` without their old text or author. Quotes from
  blocked authors are collapsed. Clicking a live preview loads older pages as needed,
  scrolls to the original and briefly highlights it.
- [x] Desktop message tools work on hover or keyboard focus; phone tools appear on tap.
- [x] Backend permission/validation tests and Chromium, Firefox and WebKit phone coverage.
  Final local Docker verification: 114 backend tests (575 assertions), Pint/type-check/lint
  clean, all 44 desktop cases run twice (88 passes), all 3 phone cases run three times
  (9 passes). Checked desktop/phone screenshots and clean API/Reverb/queue/scheduler logs.
- [x] Deployed to the homelab after `backup-before-replies-2026-11-07.sqlite`; production
  smoke passed for replies, editing, live preview changes, deleted-original tombstones,
  persistence after reload and phone layout. Both smoke-test guests logged out.

### Deferred outside this closed plan
- Reactions, attachments (possibly copyparty), custom handles, voice and visual redesign.
- Notifications/sounds/push, group muting, swipe gestures, rail reordering and multiple
  channels within groups remain unscheduled.

## Notes

- Live events go through `App\Support\Live`, which broadcasts `LiveUpdate` to each user's
  private channel and reports (never throws) if Reverb is down. One event goes to all its
  recipients in one call (the Pusher API takes 100 channels a call). Groups use the members'
  own channels, not a shared group channel: a shared channel can't be revoked from someone
  kicked, while this way they stop hearing the group at once.
- Live events implement `ShouldBroadcastNow`: sent to Reverb during the request (~15 ms
  round trip). Queued broadcasts waited up to a second for the worker's next poll.
- Presence comes from Reverb itself, not client heartbeats. Reverb can't broadcast to itself from
  inside its own event loop, so the listener only queues a job; the worker does the rest.
- Reverb sends an empty channel list as `[]`, which the Pusher SDK's `getChannels()` can't read,
  so `App\Support\Presence` uses its raw `get()`.
- SQLite transactions are `IMMEDIATE`: deferred ones failed with "database is locked" under
  concurrent writes instead of waiting out `busy_timeout`.
- No PHP on the PC: run composer and tests in a `composer:2` container (Docker Desktop locally).
- To test the whole stack locally, run HMS, Reverb, the queue and chat-ui with Docker Compose,
  with `SANCTUM_STATEFUL_DOMAINS=localhost:8090` and `REVERB_ALLOWED_ORIGINS=localhost` (Reverb
  matches the origin's host without its port), then `BASE_URL=http://localhost:8090 npx playwright test`.

- Messages are stored readable on the server; this is not end-to-end encrypted.
- Guests that close the tab without logging out are removed once their session expires.
