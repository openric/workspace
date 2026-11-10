# OpenRiC Workspace in Docker

Two containers: the Workspace (nginx, PHP 8.3-FPM, the scheduler and a queue worker, under supervisord) and PostgreSQL 16 with pgvector. After Heratio's Docker install. For a server of its own, without Docker, `bin/install` does the same from the git clone (`INSTALL.md`).

The Workspace is in development and not yet released for use. Install it to try it, not yet to hold an archive's only copy of anything.

## Start

    git clone https://github.com/openric/workspace.git && cd workspace
    git checkout "$(git tag --sort=-v:refname | head -1)"
    cp docker/.env.docker.example docker/.env.docker

Edit `docker/.env.docker`: set the three `CHANGEME_` values (the administrator's password, at least 12 characters; the database's; PostgreSQL's superuser's), and the institution, its base IRI and its administrator. The Workspace refuses to start while a `CHANGEME_` value is left: the example is public, so its values would be known to anyone.

    docker compose -f docker/docker-compose.yml --env-file docker/.env.docker up -d --build
    docker compose -f docker/docker-compose.yml --env-file docker/.env.docker logs -f workspace

The first start migrates the database, makes the audit signing key and installs the institution; it says `[init] ready` when done. Sign in at http://localhost:8090/sign-in with the administrator's e-mail and password. `WORKSPACE_DEMO=true` loads the demonstration dataset at the first start.

Below, `dc` stands for `docker compose -f docker/docker-compose.yml --env-file docker/.env.docker`.

## What lasts

| Volume | Holds |
|---|---|
| `workspace-db` | The database |
| `workspace-storage` | `storage/`: the object store with every file, the audit signing key, the logs |
| `workspace-state` | `.env` with the application key, and the marker that the Workspace is installed |
| `./docker/backups` (a host folder) | What `bin/backup` writes and `bin/restore` reads, at `/backups` inside |

A rebuilt or recreated container keeps all four, so it never installs over what is there. `dc down -v` removes the volumes, and with them every record.

## Back up and restore

    dc exec workspace bin/backup --to=/backups --keep=7

writes `docker/backups/openric-workspace-<time>/`: the database, the object store, the audit signing key, `.env` and the branding, with their digests. Copy it off the machine. To restore one, onto this stack or a new one:

    dc exec workspace bin/restore --from=/backups/openric-workspace-<time>

It checks the backup's digests first, refuses a Workspace that already holds records unless given `--replace`, and fails unless the audit chain, the object store and every file's digest verify afterwards. A backup from a server installed with `bin/install` restores here, and the other way round. `deploy/runbooks/restoring.md` is the whole routine, with the drill.

## A public demonstration

Set `WORKSPACE_DEMO_EMAIL` and `WORKSPACE_DEMO_PASSWORD` (at least 12 characters) in `docker/.env.docker`, as Heratio's demo does with its own: the sign-in page shows them, and `dc exec workspace gosu www-data php artisan workspace:demo:account` makes that account an administrator. It cannot be changed, disabled or deleted, so no visitor can take it from the next. Take the baseline with `dc exec workspace gosu www-data bin/demo-snapshot`, and run `dc exec workspace bin/demo-reset` each night from the host's cron to put everything back to it. Never on an installation that holds real records: both refuse unless `WORKSPACE_DEMO_EMAIL` is set.

## Updating

    git fetch --tags && git checkout vX.Y.Z
    dc up -d --build

The new container migrates the database as it starts. Take a backup first.

## Commands

    dc exec workspace gosu www-data php artisan workspace:audit:verify
    dc exec workspace gosu www-data php artisan workspace:ocfl:verify
    dc exec workspace gosu www-data php artisan workspace:preflight
    dc exec db psql -U postgres -d openric_workspace

Run artisan as `www-data` (`gosu www-data`), never as root: a file root writes under `storage/` stops the web server writing there.

## Not in the container

- **https.** Put a proxy that gives TLS in front (nginx, Caddy, Traefik) and set `APP_URL` to the https address; secure cookies follow. Until then preflight waives https and secure cookies.
- **Mail.** Set `MAIL_MAILER=smtp` and `MAIL_*`; until then mail is logged and preflight waives it.
- **ClamAV.** Uploads are kept as not scanned. Run ClamAV beside it and point `WORKSPACE_VIRUS_SCANNER` at a client that reaches it, or use `bin/install` on a server.
- **The files worker without network.** On a server a systemd unit runs converters with no outside network (`INSTALL.md`, step 8). A container cannot cut one process off, so one worker runs both queues; give the container no route out if that matters.
- **LibreOffice** unless built with `WITH_OFFICE=true` (about 600 MB more); without it, office documents get no PDF copy.
- **Fuseki, the AI gateway, single sign-on**: as on a server (`INSTALL.md`, Optional parts).
