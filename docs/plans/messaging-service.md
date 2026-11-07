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
  - `hms-queue`: background job worker (broadcasts, mail)
  - `hms-scheduler`: timed jobs (guest cleanup)
- **chat-ui**: separate repo, nginx serving the built React app and proxying `/api`,
  `/sanctum` and `/app` (Reverb WebSocket) so everything is same-origin.
- **Auth**: Sanctum SPA cookie (session) auth for browsers. Sanctum bearer tokens remain
  available for scripts and future apps.

## Data model

- **users**: `id`, `public_id` (unique, 8 chars), `display_name`, `email` (nullable, unique),
  `password` (nullable), `is_guest`, timestamps
- **friendships**: `requester_id`, `addressee_id`, `status` (pending / accepted / blocked), one row per pair
- **conversations**: `type` (dm / group), `name`, `visibility` (public / private), `invite_code`, `created_by`
- **conversation_members**: `conversation_id`, `user_id`, `role` (owner / admin / moderator / member), `last_read_message_id`
- **chat_messages**: `conversation_id`, `user_id`, `body`, `edited_at`, soft deletes
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
- [ ] Homelab: rename `~/homelab/projects-panel` → `~/homelab/hms`, update its git remote
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
- [ ] Copy volume `projects-panel_database` → `hms_database` (old volume kept as a backup)
- [ ] `project-panel:3000` → `hms:3000` in `glassbox/server.js` and `portfolio/nginx.conf`, restart both
- [ ] Remove the `chickensplash.dpdns.org` tunnel hostname (Cloudflare dashboard, by hand)
- [ ] Update the homelab skill file

**10 to 13. Chat front end**
- [ ] New repo `~/Projects/Apps/chat-ui`: Vite + React + TS + Tailwind 4, dev proxy to a local HMS
- [ ] nginx container on `edge` proxying to `hms` and `hms-reverb`
- [ ] Placeholder page: log in / register / join as guest, shows "connected as <name> (#public_id)", live ping round trip
- [ ] Tunnel hostname `chat.chickensplash.dpdns.org` → `http://chat-ui:80` (Cloudflare dashboard, by hand)

**Done when**: the contact form still delivers, and the chat placeholder logs in (user or guest)
and receives a live ping.

### Phase 1 · Auth UI
- Proper login, register, guest join and logout screens; profile (display name, password)

### Phase 2 · Friends
- Find users by `public_id`, request, accept, decline, remove, block

### Phase 3 · DMs
- Start a DM with anyone, real-time messages, history paging, unread counts

### Phase 4 · Groups
- Create (creator is owner), public / private, invite links, browse public groups
- Roles, kick, leave (with last-admin hand-over)

### Phase 5 · Polish
- Online presence, typing indicator, message rate limits, Playwright e2e tests (run on `HDMI-A-1`)

### Phase 6 · Later
- Edits, replies, reactions, attachments (maybe via copyparty), custom handles, voice

## Notes

- Messages are stored readable on the server; this is not end-to-end encrypted.
- Guests that close the tab without logging out are removed once their session expires.
