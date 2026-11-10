# Backing up and restoring the OpenRiC Workspace

A backup is tested only when it has been restored. This runbook is the routine for both, and the drill that proves a backup good (roadmap phase 3). `bin/backup` and `bin/restore` do the work; `INSTALL.md` (Backups) and `docker/README.md` say where they fit.

## What a backup holds

`bin/backup` writes one folder, readable by its owner only, holding:

| File | What it is |
|---|---|
| `database.dump` | The database, `pg_dump --format=custom` |
| `objects.tar` | The OCFL object store: every kept file, in objects any OCFL tool reads |
| `keys.tar` | `storage/keys`: the audit signing key. Without it nothing can be changed, and federation peers no longer recognise the server |
| `branding.tar` | The institution's logo and banner, when there are any |
| `env` | `.env`, with the application key that encrypts the passwords and secrets stored in the database |
| `manifest.json` | The release, the database's migrations, the audit chain's last hash, and the counts of records, files and audit rows |
| `SHA256SUMS` | The digest of each file |

The database is dumped first and the object store archived after it. OCFL only ever adds, so every file the dump refers to is in the archive.

## Back up

On a server, as the clone's owner or `www-data` (who can read `.env` and the signing key):

    sudo -u www-data bin/backup --to=/mnt/backups --keep=14

In Docker:

    dc exec workspace bin/backup --to=/backups --keep=14

Schedule it each night, and copy each backup off the machine. It holds the signing key and `.env`: keep it where those may be kept.

## Restore

Onto a Workspace installed by `bin/install` or Docker, of the backup's release or newer:

    sudo bin/restore --from=/mnt/backups/openric-workspace-<time>
    dc exec workspace bin/restore --from=/backups/openric-workspace-<time>

It refuses a Workspace that already holds records unless given `--replace`, which keeps the old database as `<name>_before_restore_<time>` and the old files and keys beside them. In order it:

1. checks every file of the backup against `SHA256SUMS`, before it touches anything;
2. takes the site down and makes the database afresh as PostgreSQL's superuser, with pgvector, pg_trgm and unaccent, then restores the dump owned by the Workspace's own role;
3. puts back the object store, the signing key and the branding, and takes the backup's application key into `.env` (the whole of the backup's `.env` is left beside it as `.env.from-backup`, to compare);
4. migrates a backup of an older release up to this one, caches, and brings the site back;
5. verifies, and fails unless all pass: the audit chain (`workspace:audit:verify`), the object store (`workspace:ocfl:verify`), every file's digest (`workspace:fixity`), and the counts of records, files and audit rows against the manifest.

On a server the superuser is reached by `sudo -u postgres`; in Docker by `PG_SUPERUSER` and `PG_SUPERPASSWORD`, which the compose file gives the container.

Afterwards, sign in, and check Admin, Operations. The settings in `.env` that are the server's own (its address, mail, paths) stay as they were; compare them with `.env.from-backup` when moving to a new server.

## The drill

At least each quarter, and after any change to these scripts: restore the newest backup onto a fresh install that is not the live one, and keep the record of it here.

1. Install a fresh Workspace: in Docker, with its own project name and port (`docker compose -p restore-drill ... up -d`), or with `bin/install` on a spare server.
2. Copy the newest backup to it, and run `bin/restore --from=...`.
3. It passes when `bin/restore` ends with "Restored and verified", the catalogue answers, and an administrator signs in.
4. Remove the drill's install (`docker compose -p restore-drill down -v`).

| Date | Backup of | Onto | Result |
|---|---|---|---|
| 9 Nov 2026 | A Docker install with the demonstration dataset (v0.0.146: 39 records, 8 files, 387 audit rows) | A fresh Docker install with other passwords and another institution | Passed: digests, database, files, keys and application key restored; the audit chain intact (387 rows), 3 OCFL objects without problems, 8 files' digests good, counts as in the manifest |
| 9 Nov 2026 | The same, as the demonstration's baseline, after a visitor's change | The same install (`bin/demo-reset`) | Passed: the change undone, everything verified as above, the public account put back, in about 30 seconds |

## The demonstration's nightly reset

A public demonstration (`WORKSPACE_DEMO_EMAIL` and `WORKSPACE_DEMO_PASSWORD` set, shown on the sign-in page with full administrator rights) is put back each night to a baseline, after Heratio's demo:

- `bin/demo-snapshot` takes the baseline (a `bin/backup` into `storage/app/demo-baseline/`), from cron every afternoon at 16:00, so what is made during the day is kept; run it by hand for a clean baseline at any other time.
- `bin/demo-reset`, from `deploy/cron/openric-workspace-demo-reset` at 02:00, restores it with `bin/restore --discard` and puts the public account back (`workspace:demo:account`). It refuses on an installation that is not a demonstration, without a baseline, or with a baseline from a newer release than the code.
- The public account cannot be changed, disabled or deleted, nor set up for two-factor sign-in, by anyone, so no visitor can take it from the next.
