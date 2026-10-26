# Deploying the OpenRiC Workspace

The live instance at https://workspace.openric.org runs from `/usr/share/nginx/openric-workspace-live`, a clone that only ever holds a tagged release. Development and tests happen in a separate checkout; nothing there is live.

## Release and deploy

1. Merge to `main`, and wait for CI ("Tests on PostgreSQL 16") to pass on that commit.
2. Tag it, signed: `git tag -s vX.Y.Z -m "what changed" && git push origin vX.Y.Z`.
3. Deploy: `cd /usr/share/nginx/openric-workspace-live && sudo deploy/deploy.sh vX.Y.Z`. As root, the script runs git, composer and `pg_dump` as the owner of the live directory and artisan as `www-data`, so nothing it writes belongs to root.

`deploy.sh` refuses a tag whose commit did not pass CI. It backs up the database with `pg_dump` to `storage/app/backups/` (the last ten are kept), puts the site in maintenance mode, checks out the tag, installs dependencies without development packages, runs the migrations, caches configuration, routes and views, and brings the site back. It then checks that `/sign-in` and `/vocab/permission/` answer 200.

## Releasing without a password prompt

The deploying user may run the live instance's artisan as `www-data` without a password with this one sudoers rule, in `/etc/sudoers.d/openric-workspace-deploy` (edit with `visudo -f`):

    johanpiet ALL=(www-data) NOPASSWD: /usr/bin/php /usr/share/nginx/openric-workspace-live/artisan *

It grants nothing new: the owner of the live directory can already change the code php-fpm runs as `www-data`. With it, `deploy/deploy.sh vX.Y.Z` runs as that user from start to finish without `sudo`.

## The audit signing key

Every audit row is signed (ADR 0004). The key is `storage/keys/audit-signing.sk` in the live directory, created by the first deploy that needs it (`workspace:audit:create-key --if-missing`) and never replaced. Back it up separately from the database dumps, which do not contain it: if it is lost, the Workspace refuses every change until it is restored. `php artisan workspace:audit:verify` checks the whole chain.

## Files outside the database

Back these up with the database dumps, which do not contain them: the audit signing key (`storage/keys/`), the institution logo (`storage/app/private/branding/`), and the files of digital instantiations (`storage/app/objects/`, or `WORKSPACE_OBJECT_STORE` where set). A database restored without the files has instantiations whose files fail their fixity check; a restore is complete only when `php artisan workspace:fixity` reports no failure.

## Fixity

`php artisan workspace:fixity` rehashes every kept file and compares it with the SHA-256 recorded when it came in. A failure is recorded on the file, audited, shown on its instantiation's page, and makes the command exit with failure. Run it daily from cron as the owner of the live tree, for example in `/etc/cron.d/openric-workspace`:

    15 2 * * * www-data /usr/bin/php /usr/share/nginx/openric-workspace-live/artisan workspace:fixity >> /usr/share/nginx/openric-workspace-live/storage/logs/fixity.log 2>&1

## Uploads

A file may be as large as `WORKSPACE_MAX_UPLOAD_MB` (60 MB unless set). nginx's `client_max_body_size` and PHP's `upload_max_filesize` and `post_max_size` must allow at least that. Every upload is scanned with `clamdscan`, which needs the ClamAV daemon running; set `WORKSPACE_VIRUS_SCANNER=` to keep files as not scanned where there is no scanner. The viewing copies are made by ImageMagick's `convert` command and a PDF's first page by `pdftoppm` (packages `imagemagick` and `poppler-utils`), each in its own process, so a file they cannot read is kept without copies.

## API keys

Accounts make their own keys under Your profile, API keys; an administrator makes them for another account on its page (ADR 0007). Only a fingerprint of each key is stored, so a lost key is revoked and replaced, never recovered. A key allows `WORKSPACE_API_KEY_HOURLY_LIMIT` requests an hour (1,000 unless set) and, if its maker set one, a daily limit; past either the API answers 429 with Retry-After. Reads without a key stay open and are limited per address. `api_key_usage` keeps one row per key and hour; nothing prunes it yet.

## Moving from AtoM

AtoM's CLI exports what the import reads: `csv:repository-export`, `csv:authority-export` and `csv:export --single-slug=<fonds>`. Import them in that order with one source, so descriptions find their holders and creators. For the files, copy AtoM's `uploads` folder (the one holding `r/`) to the Workspace host, readable by `www-data`, and set `WORKSPACE_IMPORT_FILES` to it: an import of descriptions takes the file each `digitalObjectURI` names from there and keeps it only when its SHA-256 matches the one AtoM recorded. A file is not fetched over the network; AtoM serves masters only to those who may read them.

## When a deploy fails

The site stays in maintenance mode and the script prints two commands: one to deploy the previous release again, and one to bring the site up as it is. A failed migration inside PostgreSQL rolls back by itself; to undo a migration that completed, restore the backup the script named:

    pg_restore --clean --if-exists --no-owner -h 127.0.0.1 -U openric_workspace -d openric_workspace <backup.dump>

## The live instance's own settings

`.env` (mode 640, readable by `www-data`) sets `APP_ENV=production`, `APP_DEBUG=false` and the database role `openric_workspace`; its password is only in that file. php-fpm may write only `storage/` and `bootstrap/cache/` (the systemd drop-in `openric-workspace-storage.conf`). nginx serves `public/` with HSTS and the usual security headers; the certificate is renewed by certbot.
