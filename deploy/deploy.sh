#!/usr/bin/env bash
# OpenRiC Workspace - deploy a tagged release to the live instance.
# Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Usage:  sudo deploy/deploy.sh vX.Y.Z
#
# Run as root: git, composer and pg_dump then run as the owner of the live
# directory, and artisan as www-data, so no file in the live tree is owned by root.
# Run as that owner instead, it uses sudo -u www-data for artisan.
#
# The live instance runs only tagged releases, never a working copy. A tag is
# deployed only when CI passed on its commit. The database is backed up before
# migrations run. If a step fails, the site is left in maintenance mode and the
# rollback command is printed.
set -euo pipefail

TAG=${1:-}
LIVE=${WORKSPACE_LIVE:-/usr/share/nginx/openric-workspace-live}
URL=${WORKSPACE_URL:-https://workspace.openric.org}
REPO=${WORKSPACE_REPO:-openric/workspace}
CI_CHECK="Tests on PostgreSQL 16"
KEEP_BACKUPS=10

fail() { echo "deploy: $*" >&2; exit 1; }
[[ $TAG =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "usage: deploy.sh vX.Y.Z"
cd "$LIVE" || fail "no live instance at $LIVE"
OWNER=$(stat -c %U "$LIVE")
if [[ $EUID -eq 0 ]]; then
  OWNER_HOME=$(getent passwd "$OWNER" | cut -d: -f6)
  owner() { runuser -u "$OWNER" -- env HOME="$OWNER_HOME" "$@"; }
  artisan() { runuser -u www-data -- php artisan "$@"; }
else
  [[ $(id -un) == "$OWNER" ]] || fail "run as root or as $OWNER, the owner of $LIVE"
  owner() { "$@"; }
  artisan() { sudo -u www-data php artisan "$@"; }
fi

echo "== 1. Release $TAG"
owner git fetch --quiet --tags origin
SHA=$(owner git rev-parse --verify --quiet "refs/tags/$TAG^{commit}") || fail "tag $TAG not found on origin"
PREVIOUS=$(owner git describe --tags --exact-match HEAD 2>/dev/null || owner git rev-parse --short HEAD)
echo "commit ${SHA:0:12}; live now runs $PREVIOUS"

echo "== 2. CI on that commit"
# The public GitHub API, so no token is needed: gh keeps its token in the desktop
# keyring, which a root session switching user cannot reach.
CI=$(curl -fsS --max-time 30 "https://api.github.com/repos/$REPO/commits/$SHA/check-runs" \
  | jq -r --arg name "$CI_CHECK" '[.check_runs[] | select(.name == $name) | .conclusion] | first // "none"') \
  || fail "could not read CI status from GitHub"
[[ $CI == success ]] || fail "CI \"$CI_CHECK\" on ${SHA:0:12} is $CI, not success"
echo "passed"

echo "== 3. Database backup"
BACKUPS="$LIVE/storage/app/backups"
owner mkdir -p "$BACKUPS"
DUMP="$BACKUPS/before-$TAG-$(date -u +%Y%m%dT%H%M%SZ).dump"
env_value() { grep -E "^$1=" "$LIVE/.env" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
# The password goes in the environment, never on a command line other users can see.
PGPASSWORD=$(env_value DB_PASSWORD) owner pg_dump --format=custom --no-owner \
  --host="$(env_value DB_HOST)" --port="$(env_value DB_PORT)" \
  --username="$(env_value DB_USERNAME)" --dbname="$(env_value DB_DATABASE)" --file="$DUMP"
owner chmod 640 "$DUMP"
ls -1t "$BACKUPS"/before-*.dump | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm --
echo "$DUMP"

on_error() {
  echo >&2
  echo "deploy: FAILED. The site is in maintenance mode." >&2
  echo "  Roll back:   deploy/deploy.sh $PREVIOUS   (if that was a tag), or restore $DUMP" >&2
  echo "  Bring up as it is:   cd $LIVE && sudo -u www-data php artisan up" >&2
}
trap on_error ERR

echo "== 4. Maintenance mode, code, dependencies, migrations, caches"
# A fresh clone has no dependencies yet, so there is no application to take down.
if [[ -d vendor ]]; then artisan down --retry=30; fi
owner git -c advice.detachedHead=false checkout --quiet --detach "$TAG"
owner composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
artisan migrate --force
artisan optimize
artisan up
trap - ERR

echo "== 5. Smoke check"
for path in /sign-in /vocab/permission/; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$URL$path")
  [[ $code == 200 ]] || fail "$path answered $code after deploying $TAG; roll back with deploy/deploy.sh $PREVIOUS"
  echo "$path 200"
done
echo "Deployed $TAG (was $PREVIOUS)."
