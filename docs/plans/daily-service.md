# Daily service

Status: implemented locally, not deployed. Frontend: `ChickenSplash/daily-ui`.

## Ownership and storage

The new `daily_*` module shares HMS accounts, Sanctum and SQLite. Guests have the same access as registered users, but their records are deleted when the guest is deleted. User-owned journals and activities cascade to entries, rules and daily results. Every endpoint scopes parent resources to the signed-in user; there is no public discovery or sharing.

- `daily_settings`: one timezone per user, defaults to Europe/London. Initialisation creates Daily journal and Gratitude journal once.
- `daily_journals`: ordered writing collections, optional prompt, archival timestamp.
- `daily_entries`: unique `(journal_id, entry_date)`, original Markdown, optimistic revision.
- `daily_activities`: immutable unit, name, optional archival date.
- `daily_schedule_rules`: ISO weekdays (Monday = 1), target, inclusive effective date range.
- `daily_activity_days`: unique `(activity_id, activity_date)`, target/name/unit snapshot, actual total, status, completion timestamp, optimistic revision and one-off flag.

Calendar dates are explicit `YYYY-MM-DD`, timestamps are UTC. User timezone determines today, not the server or browser clock. Quantities use repetitions, metres or seconds; checkbox activities have no numeric target and actual remains zero.

## Scheduling semantics

Rules can only start today or later. Replacing a routine closes its preceding rule and replaces rules from the chosen date onward. Earlier rules and stored daily targets are untouched. An empty weekday list stops a routine. Daily overrides never edit the rule.

Elapsed scheduled dates are snapshotted idempotently when read. `daily:sync`, scheduled hourly, snapshots yesterday and today in each user's timezone even if they never visit. Older gaps after scheduler downtime are reconstructed from historical rules on access. Future occurrences are previews, not stored rows, unless the user explicitly adds or edits that day's item.

Before rename, archive or timezone change, history is materialised in bounded 90-day chunks so historical labels and targets stay stable. Archiving retains today's checklist, stops future rules and preserves results. Restoring an activity does not resume its ended routine: choose repeating days again. Existing one-off future records are explicit user data and are retained.

Daily statuses: pending, completed, skipped. Numeric completion is derived from actual >= target, unless skipped. Checkbox completion is explicit. Future days can be planned but not completed or skipped. Partial totals, exceeding a target, undo and corrections to earlier dates are supported.

## API

All under `/api/daily`, authenticated, rate-limited and private/no-store.

| Method | Path | Purpose |
|---|---|---|
| GET | `/bootstrap` | Preferences, user's today, journals and activities with rules |
| PATCH | `/settings` | `timezone`, valid IANA zone |
| GET | `/calendar?from=&to=` | Up to 93 dates, entry counts and activity completion/skip totals |
| GET | `/days/{date}` | Journals and their entry, activity results or future previews |
| POST | `/journals` | `name`, optional `prompt` |
| PATCH | `/journals/{id}` | `name`, `prompt`, `position`, `archived` |
| DELETE | `/journals/{id}` | Permanently delete journal and all entries |
| PUT | `/journals/{id}/entries/{date}` | `body`, `revision` (0 for new) |
| POST | `/activities` | `name`, `unit` |
| PATCH | `/activities/{id}` | `name`, `archived`; unit cannot change |
| DELETE | `/activities/{id}` | Permanently delete an archived activity, cascading to its routines and all daily results; active activities must be archived first |
| PUT | `/activities/{id}/schedule` | `starts_on`, `weekdays`, `target` (null for checkbox) |
| PUT | `/days/{date}/activities/{id}` | `target`, `actual`, `status`, `revision` (0 for new) |
| GET | `/export` | Versioned JSON including Markdown entries, routines and results |

Writes with a stale revision return 409 without changing data. Entry bodies preserve Markdown whitespace, max 100,000 characters. Empty bodies are retained but not counted as writing on the calendar. Archived journals remain visible on dates with entries, and those entries remain editable. New entries require restoring the journal.

## Local verification

Backend feature tests cover owner isolation, guest cascades, revisions, exact Markdown storage, weekday rules, history, archiving, bounds, exports and timezone-aware idempotent sync. Frontend tests run against a separate Docker stack in daily-ui, never production.

## Deployment checklist

- Back up SQLite before migrating and verify automated off-host backups and restore.
- Deploy frontend, add tunnel hostname and set `DAILY_URL` and Sanctum's allowed host.
- `DAILY_URL` defaults empty, so the launcher does not advertise an undeployed app.
- Verify the shared terms reflect journal data, temporary guest data, browser recovery drafts and absence of end-to-end encryption.
- Check `hms-scheduler` is running and `daily:sync` succeeds.
- Production remains untouched until a separate deployment step.
