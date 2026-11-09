# Updating the OpenRiC Workspace

This is the routine for taking the live instance at https://workspace.openric.org to a new release. The deploy script does the work; this runbook is what to check before, during and after, and what to do when something goes wrong. `deploy/README.md` describes the script itself.

## Before

1. Read the release's entry in `ROADMAP.md` and its ADR. Look for anything an operator must do by hand: a new `.env` setting, a package to install, a cron line, a one-off artisan command. Releases that need one say so in their commit message.
2. Open Admin, Operations, and check that nothing needs attention. A failing scheduler, a full disk or open fixity failures should be dealt with first, so that they are not confused with what the update changes.
3. Check that all four CI jobs passed on the release commit and that the tag's own job passed. `deploy.sh` refuses otherwise, and refuses a tag not signed by a key in `deploy/allowed_signers`, so this only saves a wasted attempt. Afterwards the release's evidence is in `storage/app/releases/vX.Y.Z.md`.

## During

From the live directory, as the owner of the live tree:

    cd /usr/share/nginx/openric-workspace-live
    ./deploy/deploy.sh vX.Y.Z

The script backs up the database first (the last ten dumps are kept in `storage/app/backups/`), puts the site in maintenance mode, checks out the tag, installs dependencies, runs the migrations, caches configuration, routes and views, runs `workspace:preflight`, brings the site back and checks that it answers. It ends with "Deployed vX.Y.Z (was vA.B.C)" and the audit chain's state.

Migrations that load vocabularies or grant new permissions to the default roles run inside the deploy; nothing else is needed for them.

## After

1. Sign in and open a record, an authority record and the public catalogue.
2. Open Admin, Operations. The scheduler heartbeat should be under a minute or two old, no background job should have waited long, and nothing should need attention.
3. Run anything the release asked for by hand, as `www-data`:

        sudo -n -u www-data /usr/bin/php /usr/share/nginx/openric-workspace-live/artisan <command>

4. Publish the release note to KM, as every release does.

## When it fails

The site stays in maintenance mode and the script prints two commands: one that deploys the previous release again, and one that brings the site up as it is. A migration that failed inside PostgreSQL has already rolled back. To undo a migration that completed, restore the dump the script named:

    pg_restore --clean --if-exists --no-owner -h 127.0.0.1 -U openric_workspace -d openric_workspace <backup.dump>

and deploy the previous tag. Files are not touched by a deploy, so the object store needs no restore.

## Never

- Never edit code in the live directory; it only ever holds a tag.
- Never run `php artisan` there as root: it leaves files under `storage/` that the web server cannot write.
- Never bring the site up with `artisan up` after a failed deploy without knowing why it failed; deploying the previous release is the safe way back.
