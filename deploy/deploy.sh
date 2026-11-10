#!/usr/bin/env bash
# OpenRiC Workspace - deploy a tagged release to the live instance.
# Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Usage:  sudo deploy/deploy.sh vX.Y.Z
#
# Run as root: git, composer and pg_dump then run as the owner of the live
# directory, and artisan as www-data, so no file in the live tree is owned by root.
# Run as that owner instead, it uses sudo -u www-data for artisan, by its full path, so a
# narrow sudoers rule can allow exactly that without a password (deploy/README.md).
#
# The live instance runs only tagged releases, never a working copy. A tag is
# deployed only when it is signed by a key in deploy/allowed_signers (the copy in the
# release already live) and verified by GitHub, is the same tag object here and there,
# and every CI job passed on its exact commit with none failing after. What was checked
# is kept as the release's evidence in storage/app/releases/{tag}.md. The database is backed up before
# migrations run. If a step fails, the site is left in maintenance mode and the
# rollback command is printed.
#
# Two stages. Stage 1 (this copy, whatever release is live) checks the tag, backs
# up, enters maintenance mode and checks the tag out. Stage 2 then runs from the
# release being deployed, so a release's own deploy steps (a new migration, a key it
# needs) always run with it, not one release later.
set -euo pipefail

TAG=${1:-}
LIVE=${WORKSPACE_LIVE:-/usr/share/nginx/openric-workspace-live}
URL=${WORKSPACE_URL:-https://workspace.openric.org}
REPO=${WORKSPACE_REPO:-openric/workspace}
# Every job of .github/workflows/ci.yml: a release passes only when each passed on its commit.
REQUIRED_CHECKS=("Tests on PostgreSQL 16" "Accessibility (WCAG 2.2 AA)" "Docker install builds, starts and backs up" "Dependencies, secrets and SBOM" "The tag is signed and its commit passed on main")
KEEP_BACKUPS=10

fail() { echo "deploy: $*" >&2; exit 1; }
[[ $TAG =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "usage: deploy.sh vX.Y.Z"
cd "$LIVE" || fail "no live instance at $LIVE"
OWNER=$(stat -c %U "$LIVE")
if [[ $EUID -eq 0 ]]; then
  OWNER_HOME=$(getent passwd "$OWNER" | cut -d: -f6)
  owner() { runuser -u "$OWNER" -- env HOME="$OWNER_HOME" "$@"; }
  artisan() { runuser -u www-data -- /usr/bin/php "$LIVE/artisan" "$@"; }
else
  [[ $(id -un) == "$OWNER" ]] || fail "run as root or as $OWNER, the owner of $LIVE"
  owner() { "$@"; }
  artisan() { sudo -u www-data /usr/bin/php "$LIVE/artisan" "$@"; }
fi

on_error() {
  echo >&2
  echo "deploy: FAILED. The site is in maintenance mode." >&2
  echo "  Roll back:   deploy/deploy.sh $PREVIOUS   (if that was a tag), or restore $DUMP" >&2
  echo "  Bring up as it is:   cd $LIVE && sudo -u www-data php artisan up" >&2
}

if [[ ${WORKSPACE_DEPLOY_STAGE:-} != 2 ]]; then
  echo "== 1. Release $TAG"
  owner git fetch --quiet --tags origin
  SHA=$(owner git rev-parse --verify --quiet "refs/tags/$TAG^{commit}") || fail "tag $TAG not found on origin"
  PREVIOUS=$(owner git describe --tags --exact-match HEAD 2>/dev/null || owner git rev-parse --short HEAD)
  echo "commit ${SHA:0:12}; live now runs $PREVIOUS"

  EVIDENCE_DIR="$LIVE/storage/app/releases"
  owner mkdir -p "$EVIDENCE_DIR"
  EVIDENCE="$EVIDENCE_DIR/$TAG.md"
  evidence() { printf '%s\n' "$@" | owner tee -a "$EVIDENCE" > /dev/null; }
  owner rm -f "$EVIDENCE"
  evidence "# Release evidence: $TAG" "" "- Deployed to: $URL" "- Commit: $SHA" "- Previously live: $PREVIOUS" "- Started: $(date -u +%Y-%m-%dT%H:%M:%SZ)" ""

  echo "== 2. Signature, identity and CI of that tag"
  # The public GitHub API, so no token is needed: gh keeps its token in the desktop
  # keyring, which a root session switching user cannot reach.
  api() { curl -fsS --max-time 30 "https://api.github.com/repos/$REPO/$1" || fail "could not read $1 from GitHub"; }
  TAG_OBJECT=$(owner git rev-parse "refs/tags/$TAG")
  [[ $(owner git cat-file -t "$TAG_OBJECT") == tag ]] || fail "$TAG is not an annotated tag"
  [[ $(api "git/ref/tags/$TAG" | jq -r .object.sha) == "$TAG_OBJECT" ]] || fail "$TAG here is not the tag GitHub holds"
  GITHUB_TAG=$(api "git/tags/$TAG_OBJECT")
  [[ $(jq -r .verification.verified <<< "$GITHUB_TAG") == true ]] || fail "GitHub does not verify the signature of $TAG"
  [[ $(jq -r .object.sha <<< "$GITHUB_TAG") == "$SHA" ]] || fail "$TAG names another commit on GitHub"
  # The signers of the release already live, never the new release's own list.
  if [[ -f "$LIVE/deploy/allowed_signers" ]]; then
    owner git -c gpg.ssh.allowedSignersFile="$LIVE/deploy/allowed_signers" verify-tag "$TAG" 2>&1 | tail -1 \
      || fail "the signature of $TAG is not from a key in deploy/allowed_signers"
    SIGNED="verified here against deploy/allowed_signers of $PREVIOUS, and by GitHub"
  else
    SIGNED="verified by GitHub only: $PREVIOUS has no deploy/allowed_signers yet"
  fi
  echo "signed: $SIGNED"
  evidence "## Tag" "" "- Tag object: $TAG_OBJECT, the same on GitHub" "- Signature: $SIGNED" "" "## CI on $SHA" ""
  # Each job's latest run that was not skipped must have succeeded: a release tag's own run skips the
  # tests (its commit passed them on main), and a failure after a success is not hidden by it.
  RUNS=$(api "commits/$SHA/check-runs?per_page=100")
  for check in "${REQUIRED_CHECKS[@]}"; do
    LAST=$(jq -r --arg name "$check" '[.check_runs[] | select(.name == $name and .conclusion != "skipped")]
      | sort_by(.completed_at // "9999") | last // {} | "\(.conclusion // (if .status then "still running" else "none" end))\t\(.html_url // "")"' <<< "$RUNS")
    [[ ${LAST%%$'\t'*} == success ]] || fail "CI \"$check\" on ${SHA:0:12} is ${LAST%%$'\t'*}, not success"
    echo "$check: success"
    evidence "- $check: success, ${LAST#*$'\t'}"
  done
  evidence ""

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
  evidence "## Deploy" "" "- Database backed up before migrating: $(basename "$DUMP")"

  trap on_error ERR
  echo "== 4. Maintenance mode and code"
  # A fresh clone has no dependencies yet, so there is no application to take down.
  if [[ -d vendor ]]; then artisan down --retry=30; fi
  owner git -c advice.detachedHead=false checkout --quiet --detach "$TAG"
  trap - ERR
  export WORKSPACE_DEPLOY_STAGE=2 PREVIOUS DUMP EVIDENCE
  exec "$LIVE/deploy/deploy.sh" "$TAG"
fi

# Stage 2: from the release being deployed.
evidence() { if [[ -n ${EVIDENCE:-} ]]; then printf '%s\n' "$@" | owner tee -a "$EVIDENCE" > /dev/null; fi; }
trap on_error ERR
echo "== 5. Dependencies, migrations, signing key, caches"
owner composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
artisan migrate --force
# The Workspace's own vocabularies may gain concepts in a release; importing is idempotent.
artisan workspace:vocabulary:import --bundled
# Every audit row is signed (ADR 0004): without the key no change can be recorded.
artisan workspace:audit:create-key --if-missing
artisan optimize
# An unsafe production configuration stops the release here, before the site comes back (roadmap phase 23).
artisan workspace:preflight
evidence "- Migrations, vocabularies, caches and the preflight check passed"
# A worker that outlives a release (the files worker, deploy/systemd) takes the new code once its job is done.
artisan queue:restart
artisan up
trap - ERR

echo "== 6. Checks"
for path in /sign-in /vocab/permission/; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$URL$path")
  [[ $code == 200 ]] || fail "$path answered $code after deploying $TAG; roll back with deploy/deploy.sh $PREVIOUS"
  echo "$path 200"
done
# Files that must never be served.
for path in /.env /composer.json /composer.lock /storage/logs/laravel.log /vendor/autoload.php /artisan; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$URL$path")
  [[ $code == 403 || $code == 404 ]] || fail "$path answered $code after deploying $TAG; it must not be served"
done
echo "private files refused"
evidence "- /sign-in and /vocab/permission/ answer 200; private files are refused"
AUDIT=$(artisan workspace:audit:verify)
echo "$AUDIT"
evidence "- $AUDIT" "- Finished: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "Deployed $TAG (was $PREVIOUS). Evidence: ${EVIDENCE:-none}"
