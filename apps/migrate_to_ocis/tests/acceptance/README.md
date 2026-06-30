# Acceptance test suite

End-to-end black-box test of the OC10 → oCIS migration. It spins up real
ownCloud 10 (+ MariaDB + Redis) and oCIS containers, seeds OC10 with users,
groups, files and every share type, runs the actual migration (the 7 `occ`
commands), and then asserts — independently, against the oCIS Graph API and
WebDAV — that everything migrated correctly, including the negative cases.

## Running

```bash
make test-acceptance            # full run: up → seed → migrate → assert → teardown
```

Requirements on the host: Docker + Compose v2, `composer`, `jq`. The app's
`vendor/` is installed automatically before the containers start (the app
autoloads from it and is mounted read-only).

The same entrypoint runs in CI via `.github/workflows/acceptance.yml`, so a
local green run and a green CI run exercise identical steps.

## What it checks

| Area   | Positive                                            | Negative                                        |
|--------|-----------------------------------------------------|-------------------------------------------------|
| Users  | active users migrated with correct email            | disabled user (`dave`) absent                   |
| Groups | groups migrated, active members present             | disabled member excluded                        |
| Roles  | each non-admin user has an app role; admin untouched |                                                 |
| Files  | files present under `/ownCloud/` with correct size  | never-logged-in user (`carol`) has no files     |
| Shares | user (view/edit), group, links (view/createOnly), expiry | link passwords NOT compared (regenerated)  |

## Layout

- `run.sh` — single entrypoint (used by `make` and CI).
- `docker/docker-compose.yml` — the OC10 + oCIS topology.
- `lib/` — shared bash helpers and readiness polls.
- `seed/` + `fixtures/` — idempotent OC10 data seeding.
- `migrate/migrate.sh` — drives the 7 `occ` migration commands.
- `assert/` — independent oCIS-side verification (bash + curl + jq).
- `artifacts/` — per-step logs (gitignored).

## Dev loop

Phases are individually toggleable, and `KEEP_UP=1` leaves the stack running so
you can re-run just the assertions against a live, already-migrated stack:

```bash
KEEP_UP=1 make test-acceptance                 # run once, keep containers up
cd tests/acceptance && bash assert/assert.sh    # iterate on assertions only
```

The migration is forward-only per oCIS instance. To re-run the migration
against a clean oCIS without recreating OC10 + seed data:

```bash
make test-acceptance-reset-ocis                # recycle only the oCIS container
```

Configurable via a `.env` file (see `.env.example`): image tags, credentials,
and the phase toggles.
