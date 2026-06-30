#!/usr/bin/env bash
# Upload files/folders to OC10 via WebDAV for the active+logged-in users.
# Writes artifacts/files_manifest.txt (user<TAB>path<TAB>bytes) so file
# assertions verify against ground truth instead of hardcoded sizes.
#
# carol is never logged in -> she intentionally gets NO files (the migration
# would skip them anyway); her empty oCIS drive is the negative assertion.
set -euo pipefail
ACCEPTANCE_DIR="${ACCEPTANCE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$ACCEPTANCE_DIR"
source lib/common.sh

MANIFEST="$ARTIFACTS/files_manifest.txt"
: > "$MANIFEST"

# mkcol <user> <pw> <relpath>  -- create a collection (idempotent: 405 = exists)
mkcol() {
  local u="$1" pw="$2" path="$3"
  in_oc10 curl -sS -u "$u:$pw" -X MKCOL "$OC10_DAV/$u/$path" -o /dev/null \
    -w '%{http_code}' | grep -qE '^(201|405)$'
}

# putfile <user> <pw> <relpath> <local-fixture>
putfile() {
  local u="$1" pw="$2" path="$3" local="$4"
  in_oc10 curl -sS -u "$u:$pw" -T - "$OC10_DAV/$u/$path" < "$local" -o /dev/null
  local bytes
  bytes=$(wc -c < "$local" | tr -d ' ')
  printf '%s\t/%s\t%s\n' "$u" "$path" "$bytes" >> "$MANIFEST"
  log "uploaded $u:/$path ($bytes bytes)"
}

# --- alice: general structure + every share-target file --------------------
mkcol alice alice123 "folder"
mkcol alice alice123 "folder/nested"
mkcol alice alice123 "filedrop"
putfile alice alice123 "folder/hello.txt"        fixtures/files/hello.txt
putfile alice alice123 "folder/nested/deep.txt"  fixtures/files/nested/deep.txt
# Share-target files referenced by fixtures/shares.csv.
putfile alice alice123 "readonly.txt"            fixtures/files/hello.txt
putfile alice alice123 "readwrite.txt"           fixtures/files/hello.txt
putfile alice alice123 "groupshare.txt"          fixtures/files/hello.txt
putfile alice alice123 "link-nopass.txt"         fixtures/files/hello.txt
putfile alice alice123 "link-pass.txt"           fixtures/files/hello.txt
putfile alice alice123 "expiring.txt"            fixtures/files/hello.txt

# --- bob: a couple of files to prove multi-user file migration --------------
mkcol bob bob123 "docs"
putfile bob bob123 "docs/readme.txt"             fixtures/files/hello.txt
putfile bob bob123 "deep.txt"                    fixtures/files/nested/deep.txt

log "files seeded; manifest at $MANIFEST"
