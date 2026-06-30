#!/usr/bin/env bash
# Create OC10 shares from fixtures/shares.csv via the OCS Share API (idempotent).
# Covers: user share read-only, user share read-write, group share, public link
# (passwordless), public link (password-protected), upload-only file-drop link,
# and a share with an expiration date.
set -euo pipefail
ACCEPTANCE_DIR="${ACCEPTANCE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$ACCEPTANCE_DIR"
source lib/common.sh

# Does a share already exist for this owner+path+type(+recipient)? Uses the OCS
# list endpoint (JSON) so re-runs don't pile up duplicate shares.
share_exists() {
  local owner="$1" pw="$2" path="$3" stype="$4" recipient="$5"
  local json
  json=$(in_oc10 curl -sS -u "$owner:$pw" -H 'OCS-APIRequest: true' \
    "$OC10_OCS?format=json&path=$path&reshares=true" 2>/dev/null || true)
  [ -z "$json" ] && return 1
  echo "$json" | jq -e \
    --argjson st "$stype" --arg rc "$recipient" \
    '.ocs.data[]? | select((.share_type|tonumber)==$st)
       | select(($rc=="") or (.share_with==$rc))' >/dev/null 2>&1
}

# FD 3: inner `docker compose exec` calls would consume the CSV from stdin.
while IFS=, read -r owner pw path stype recipient perms expire password <&3; do
  case "$owner" in ''|\#*) continue ;; esac

  if share_exists "$owner" "$pw" "$path" "$stype" "$recipient"; then
    log "share already exists: $owner $path type=$stype recipient=${recipient:-<link>}"
    continue
  fi

  log "creating share: $owner $path type=$stype recipient=${recipient:-<link>} perms=$perms"
  args=(-H 'OCS-APIRequest: true'
        --data-urlencode "path=$path"
        --data-urlencode "shareType=$stype"
        --data-urlencode "permissions=$perms")
  [ -n "$recipient" ] && args+=(--data-urlencode "shareWith=$recipient")
  [ -n "$expire" ]    && args+=(--data-urlencode "expireDate=$expire")
  [ -n "$password" ]  && args+=(--data-urlencode "password=$password")

  resp=$(in_oc10 curl -sS -u "$owner:$pw" "${args[@]}" "$OC10_OCS?format=json")
  status=$(echo "$resp" | jq -r '.ocs.meta.statuscode // empty')
  if [ "$status" != "100" ] && [ "$status" != "200" ]; then
    err "share creation failed ($owner $path): $(echo "$resp" | jq -r '.ocs.meta.message // .')"
    exit 1
  fi
done 3< fixtures/shares.csv

log "shares seeded"
