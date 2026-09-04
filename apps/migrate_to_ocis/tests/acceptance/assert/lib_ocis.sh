#!/usr/bin/env bash
# Assertion helpers + oCIS Graph/WebDAV access. Source common.sh first.
#
# All oCIS calls run from inside the oc10 container (so `ocis` resolves) with -k
# (self-signed cert). Per-user calls need an impersonation token minted by the
# admin via /auth-app/tokens.

# --- mini assertion framework ----------------------------------------------
PASS_COUNT=0
FAIL_COUNT=0
ASSERT_LOG="${ARTIFACTS:-.}/assertions.log"

_record() { echo "$1" | tee -a "$ASSERT_LOG" >&2; }
pass() { PASS_COUNT=$((PASS_COUNT+1)); _record "PASS: $*"; }
fail() { FAIL_COUNT=$((FAIL_COUNT+1)); _record "FAIL: $*"; }

# ok <description> <command...>  -> pass if command succeeds
ok()      { if "$@" >/dev/null 2>&1; then pass "$DESC"; else fail "$DESC"; fi; }

# require_count <json> <jq-array-path> <min> <what>
# Hard-fail the whole run if a bulk Graph response is empty/short. Guards against
# a token/API outage silently producing zero assertions (which would otherwise
# leave the overall run reporting PASS). Runs under the orchestrator's `set -e`,
# so `exit 1` aborts assert.sh entirely.
require_count() {
  local json="$1" path="$2" min="$3" what="$4" n
  n=$(printf '%s' "$json" | jq -r "($path) | length" 2>/dev/null || echo 0)
  if [ "${n:-0}" -lt "$min" ]; then
    err "sanity check: expected >= $min $what from oCIS Graph, got ${n:-0} (API/token outage?)"
    exit 1
  fi
}
# assert_true <bool-expr-result> : pass/fail based on $1 == "true"
assert_true()  { [ "$1" = "true" ]  && pass "$2" || fail "$2 (got: $1)"; }
assert_false() { [ "$1" = "false" ] && pass "$2" || fail "$2 (got: $1)"; }
assert_eq()    { [ "$1" = "$2" ]    && pass "$3" || fail "$3 (want '$2' got '$1')"; }

# --- oCIS Graph (admin) -----------------------------------------------------
# graph <path> [extra curl args]   GET as admin, prints body
graph() {
  local path="$1"; shift || true
  ocis_curl "$OCIS_ADMIN" "$OCIS_PW" "$@" "$OCIS_BASE/graph/$path"
}

# mint_token <userName>  -> impersonation token for that oCIS user
mint_token() {
  ocis_curl "$OCIS_ADMIN" "$OCIS_PW" -X POST \
    "$OCIS_BASE/auth-app/tokens?userName=$1&expiry=1h" | jq -r '.token // empty'
}

# --- per-user drive access --------------------------------------------------
# user_drive_webdav <user> <token>  -> the personal drive's webDavUrl
user_drive_webdav() {
  local user="$1" token="$2"
  ocis_curl "$user" "$token" "$OCIS_BASE/graph/v1.0/me/drives" \
    | jq -r '[.value[] | select(.driveType=="personal")][0].root.webDavUrl // empty'
}

# user_drive_id <user> <token>  -> the personal drive id
user_drive_id() {
  local user="$1" token="$2"
  ocis_curl "$user" "$token" "$OCIS_BASE/graph/v1.0/me/drives" \
    | jq -r '[.value[] | select(.driveType=="personal")][0].id // empty'
}

# dav_propfind <user> <token> <absolute-webdav-url> <depth>  -> raw XML
dav_propfind() {
  local user="$1" token="$2" url="$3" depth="${4:-1}"
  ocis_curl "$user" "$token" -X PROPFIND -H "Depth: $depth" \
    -H 'Content-Type: application/xml' \
    --data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><d:getcontentlength/><oc:fileid/></d:prop></d:propfind>' \
    "$url"
}

# ocis_user_id <username>  -> oCIS user id (admin lookup by onPremisesSamAccountName)
ocis_user_id() {
  graph "v1.0/users?\$search=%22$1%22" \
    | jq -r --arg u "$1" '.value[]? | select(.onPremisesSamAccountName==$u) | .id' | head -n1
}

# ocis_group_id <displayName>  -> oCIS group id
ocis_group_id() {
  graph "v1.0/groups?\$search=%22$1%22" \
    | jq -r --arg g "$1" '.value[]? | select(.displayName==$g) | .id' | head -n1
}

# Cache the share role definitions (id -> displayName) once.
_ROLE_DEFS=""
role_name() {                  # role_name <roleId> -> lowercased displayName
  if [ -z "$_ROLE_DEFS" ]; then
    _ROLE_DEFS=$(graph "v1beta1/roleManagement/permissions/roleDefinitions")
  fi
  echo "$_ROLE_DEFS" | jq -r --arg id "$1" \
    '.[]? | select(.id==$id) | .displayName' | head -n1 | tr '[:upper:]' '[:lower:]'
}
