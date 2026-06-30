#!/usr/bin/env bash
# Create OC10 groups and memberships from fixtures/groups.csv (idempotent).
# Disabled members are added in OC10 but must be excluded from the oCIS group.
set -euo pipefail
ACCEPTANCE_DIR="${ACCEPTANCE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$ACCEPTANCE_DIR"
source lib/common.sh

# `occ group:list` prints "  - <name>" (no trailing colon, unlike user:list).
group_exists() { occ group:list 2>/dev/null | grep -qE "^[[:space:]]*-[[:space:]]*$1[[:space:]]*$"; }

# FD 3: inner `docker compose exec` calls would consume the CSV from stdin.
while IFS=, read -r group members <&3; do
  case "$group" in ''|\#*) continue ;; esac

  if group_exists "$group"; then
    log "group $group already exists"
  else
    log "creating group $group"
    occ group:add "$group" >/dev/null
  fi

  IFS=';' read -ra memarr <<< "$members"
  for m in "${memarr[@]}"; do
    [ -z "$m" ] && continue
    log "adding $m to group $group"
    # group:add-member is idempotent enough; tolerate "already a member".
    occ group:add-member --member "$m" "$group" >/dev/null 2>&1 || true
  done
done 3< fixtures/groups.csv

log "groups seeded"
occ group:list
