# Rotating keys and secrets

The Workspace holds six kinds of secret. Each is rotated differently, and one, the audit signing key, is never replaced. Rotate a secret when someone who knew it leaves, when it may have been seen, and otherwise on the institution's own schedule (once a year is common for the application key and the database password).

Every artisan command below runs as `www-data` from the live directory:

    sudo -n -u www-data /usr/bin/php /usr/share/nginx/openric-workspace-live/artisan <command>

## The application key (`APP_KEY`)

It encrypts sessions, cookies and these fields: researchers' and walk-in visitors' telephone and identity numbers, ORCID tokens, the ORCID client secret and DataCite password, the PayFast merchant key and passphrase, and vendors' bank details. Laravel reads values encrypted with an earlier key when that key is listed in `APP_PREVIOUS_KEYS`.

1. Make a new key without writing it anywhere: `php artisan key:generate --show`.
2. In `.env`, move the current `APP_KEY` into `APP_PREVIOUS_KEYS` (comma-separated if there are earlier ones) and set `APP_KEY` to the new key.
3. Cache the configuration again: `php artisan optimize`.
4. Encrypt every encrypted field again with the new key: `php artisan workspace:keys:reencrypt`. It reports how many values it encrypted again and any value neither key opens; it exits with failure if there is one.
5. When it reports nothing it could not open, remove the old key from `APP_PREVIOUS_KEYS` and run `php artisan optimize` again.

Everyone is signed out when the key changes, as their sessions were sealed with the old one.

## The audit signing key (`storage/keys/audit-signing.sk`)

Never rotated. Every audit row is signed with it and chained to the one before (ADR 0004); a new key would break the chain's verification of everything before it. Keep it backed up apart from the database dumps. If it is lost, restore it from that backup: the Workspace refuses every change until it can sign. If it may have been seen by someone it should not have been, the chain still shows any row changed afterwards; record what happened in the institution's incident log, and raise it as a decision for the project, since a second key needs a recorded break in the chain.

## The database password

1. Set a new password on the role: `ALTER ROLE openric_workspace PASSWORD '...';` as a PostgreSQL superuser.
2. Put it in `.env` as `DB_PASSWORD`, then `php artisan optimize`.
3. Check that the site answers and that Admin, Operations shows the database up.

The deploy script's `pg_dump` reads the same `.env`, so nothing else changes.

## API keys

Only a fingerprint of each key is stored, so a key is never recovered, only revoked and replaced. The account that owns a key does this under Your profile, API keys; an administrator does it on the account's page. Tell whoever uses the key before revoking it.

## Webhook secrets

Under Admin, Webhooks, open the webhook, tick "make a new secret" and save. The new secret is shown once; give it to the receiving system, which must check the `X-OpenRiC-Signature` header with it from then on. Deliveries already queued are signed with the new secret when they are sent.

## Other secrets in `.env`

The mail password (`MAIL_PASSWORD`), the bounce webhook secret (`WORKSPACE_EMAIL_BOUNCE_SECRET`) and the metrics token (`WORKSPACE_METRICS_TOKEN`) are changed in `.env` and take effect after `php artisan optimize`. Change the bounce secret at the mail provider at the same moment, and the metrics token in the monitor's scrape configuration.
