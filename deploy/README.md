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

## When a deploy fails

The site stays in maintenance mode and the script prints two commands: one to deploy the previous release again, and one to bring the site up as it is. A failed migration inside PostgreSQL rolls back by itself; to undo a migration that completed, restore the backup the script named:

    pg_restore --clean --if-exists --no-owner -h 127.0.0.1 -U openric_workspace -d openric_workspace <backup.dump>

## The live instance's own settings

`.env` (mode 640, readable by `www-data`) sets `APP_ENV=production`, `APP_DEBUG=false` and the database role `openric_workspace`; its password is only in that file. php-fpm may write only `storage/` and `bootstrap/cache/` (the systemd drop-in `openric-workspace-storage.conf`). nginx serves `public/` with HSTS and the usual security headers; the certificate is renewed by certbot.
