#!/usr/bin/env bash
# Single entrypoint for the acceptance suite. Used identically by
# `make test-acceptance` and the GitHub Actions workflow (CI/local parity).
#
# Phases are individually toggleable for the dev loop:
#   RUN_UP, RUN_SEED, RUN_MIGRATE, RUN_ASSERT  (default 1)
#   KEEP_UP=1   leave containers running after the run (debugging)
set -euo pipefail

ACCEPTANCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export ACCEPTANCE_DIR
cd "$ACCEPTANCE_DIR"

export COMPOSE_FILE="docker/docker-compose.yml"
# Load .env if present (image tags, creds, toggles).
[ -f .env ] && set -a && . ./.env && set +a

source lib/common.sh
source lib/wait_for.sh

: "${RUN_UP:=1}"
: "${RUN_SEED:=1}"
: "${RUN_MIGRATE:=1}"
: "${RUN_ASSERT:=1}"
: "${KEEP_UP:=0}"

cleanup() {
  local code=$?
  log "capturing compose logs -> artifacts/compose.log"
  dc logs --no-color >"$ARTIFACTS/compose.log" 2>&1 || true
  if [ "$KEEP_UP" = "1" ]; then
    warn "KEEP_UP=1: leaving containers running (down with: docker compose -f $COMPOSE_FILE down -v)"
  else
    log "tearing down containers"
    dc down -v --remove-orphans >/dev/null 2>&1 || true
  fi
  exit "$code"
}
trap cleanup EXIT

mkdir -p "$ARTIFACTS"

if [ "$RUN_UP" = "1" ]; then
  # The app autoloads from its own vendor/; the mount is read-only, so build deps
  # on the host first. Skip if vendor/ already populated (faster dev loop).
  if [ ! -d "../../vendor" ]; then
    log "installing app composer dependencies (--no-dev)"
    ( cd ../.. && composer install --no-dev --no-interaction --no-progress )
  fi

  log "starting containers"
  dc up -d --wait

  wait_oc10
  wait_ocis
  wait_ocis_authapp

  # Copy the app from the read-only mount into the apps dir (avoids the
  # entrypoint's recursive chown failing on a read-only mount), fix ownership,
  # then enable it.
  log "installing migrate_to_ocis app into OC10 apps dir"
  docker compose exec -T -u root oc10 bash -c '
    set -e
    rm -rf /var/www/owncloud/apps/migrate_to_ocis
    cp -a /mnt/migrate_to_ocis /var/www/owncloud/apps/migrate_to_ocis
    chown -R www-data:www-data /var/www/owncloud/apps/migrate_to_ocis
    chmod +x /var/www/owncloud/apps/migrate_to_ocis/bin/rclone_linux_amd64
  '

  log "enabling migrate_to_ocis app in OC10"
  occ app:enable migrate_to_ocis
fi

if [ "$RUN_SEED" = "1" ]; then
  log "seeding OC10 with users, groups, files and shares"
  bash seed/seed.sh 2>&1 | tee "$ARTIFACTS/seed.log"
fi

if [ "$RUN_MIGRATE" = "1" ]; then
  log "running migration (7 occ commands)"
  bash migrate/migrate.sh
fi

if [ "$RUN_ASSERT" = "1" ]; then
  log "asserting migration results against oCIS"
  bash assert/assert.sh
fi

log "acceptance run complete"
