#!/bin/bash
# OpenRiC Workspace - first boot inside the container, run at every start and idempotent: bin/install's stages 5 to 8
# where the container does not already provide them (the database is its own container; nginx and the workers run
# under supervisord).
#
#   .env           made once from .env.example with a new application key, and kept in the state volume, so a
#                  rebuilt container keeps the key that encrypts the stored secrets
#   schema         migrations, at every start (a new image brings its own)
#   audit key      made once, in storage/keys (the storage volume)
#   institution    made once, unless the database already has one (a restore); then the marker in the state volume
#   caches         config, routes and views; workspace:preflight
#
# Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
cd /var/www/openric-workspace

STATE=/var/lib/openric-workspace
MARKER="$STATE/installed"
web() { gosu www-data "$@"; }
artisan() { web php artisan "$@"; }
say() { echo "[init] $*"; }

# A value from the public example is not a secret: refuse to start on one.
for name in DB_PASSWORD WORKSPACE_INSTALL_PASSWORD PG_SUPERPASSWORD; do
    case "${!name:-}" in
        ""|CHANGEME_*) say "FATAL: $name is empty or still the example's CHANGEME_ value; set it in docker/.env.docker"; exit 1 ;;
    esac
done

# The settings, kept in the state volume.
mkdir -p "$STATE"
if [ ! -f "$STATE/.env" ]; then
    cp .env.example "$STATE/.env"
    say "made .env in the state volume"
fi
ln -sf "$STATE/.env" .env
chown root:www-data "$STATE/.env" && chmod 640 "$STATE/.env"
if ! grep -qE '^APP_KEY=base64:' "$STATE/.env"; then
    php artisan key:generate --force --no-interaction >/dev/null
    say "made the application key"
fi
# What the container's environment does not set, .env does; the rest comes from docker-compose.yml.
set_env() { grep -qE "^$1=" "$STATE/.env" && sed -i -E "s|^$1=.*|$1=$2|" "$STATE/.env" || echo "$1=$2" >> "$STATE/.env"; }
# Production, whatever .env.example's development defaults say; docker-compose.yml may set APP_ENV otherwise.
set_env APP_ENV "${APP_ENV:-production}"
set_env APP_DEBUG false
set_env LOG_LEVEL "${LOG_LEVEL:-info}"
set_env DB_CONNECTION pgsql
set_env QUEUE_CONNECTION database
set_env WORKSPACE_FILES_WORKER false
case "${APP_URL:-}" in https://*) set_env SESSION_SECURE_COOKIE true ;; *) set_env SESSION_SECURE_COOKIE false ;; esac

# The storage volume, for the web server.
mkdir -p storage/app/private storage/keys storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views
chown -R www-data:www-data storage bootstrap/cache

say "waiting for the database at ${DB_HOST}:${DB_PORT}"
for _ in $(seq 1 60); do pg_isready -q -h "$DB_HOST" -p "$DB_PORT" && break; sleep 1; done
pg_isready -q -h "$DB_HOST" -p "$DB_PORT" || { say "FATAL: the database did not answer"; exit 1; }

artisan config:clear >/dev/null
artisan migrate --force --no-interaction
artisan workspace:audit:create-key --if-missing

if [ ! -f "$MARKER" ]; then
    if PGPASSWORD="$DB_PASSWORD" psql -tA -h "$DB_HOST" -U "$DB_USERNAME" -d "$DB_DATABASE" -c 'SELECT 1 FROM institutions LIMIT 1' | grep -q 1; then
        say "an institution exists already (a restore, or an earlier start): not installing over it"
    else
        artisan workspace:install --no-interaction --institution="$WORKSPACE_INSTITUTION" --base-iri="$WORKSPACE_BASE_IRI" \
            --admin-name="$WORKSPACE_ADMIN_NAME" --admin-email="$WORKSPACE_ADMIN_EMAIL" --language="${WORKSPACE_LANGUAGE:-en}"
        say "installed: $WORKSPACE_ADMIN_EMAIL signs in with WORKSPACE_ADMIN_PASSWORD"
        if [ "${WORKSPACE_DEMO:-false}" = true ]; then artisan workspace:demo && say "demonstration dataset loaded"; fi
    fi
    date -u +%Y-%m-%dT%H:%M:%SZ > "$MARKER"
fi

# Preflight waives, by name, what a container without its own TLS or mail cannot have; they go in .env before the
# configuration is cached, since preflight reads them from it.
WAIVE=""
case "${APP_URL:-}" in https://*) ;; *) WAIVE="https,cookies" ;; esac
[ "${MAIL_MAILER:-log}" = smtp ] || WAIVE="${WAIVE:+$WAIVE,}mail"
set_env WORKSPACE_PREFLIGHT_WAIVE "$WAIVE"
artisan config:cache >/dev/null && artisan route:cache >/dev/null && artisan view:cache >/dev/null
artisan workspace:preflight || say "preflight refused the configuration above: put it right before the Workspace holds anything"
say "ready at ${APP_URL}"
