#!/usr/bin/env bash
# Files: every seeded file exists on the user's oCIS drive under /ownCloud/
# with the correct byte size. Negative: never-logged-in user has no files.
#
# Migrated files live at <personal-drive-webDavUrl>/ownCloud/<relpath>. We
# PROPFIND each file directly (depth 0); oCIS rejects depth:infinity.

MANIFEST="$ARTIFACTS/files_manifest.txt"

# Cache one impersonation token + webdav base per user.
declare -A F_TOKEN F_WEBDAV
user_ctx() {
  local user="$1"
  if [ -z "${F_TOKEN[$user]:-}" ]; then
    F_TOKEN[$user]="$(mint_token "$user")"
    F_WEBDAV[$user]="$(user_drive_webdav "$user" "${F_TOKEN[$user]}")"
  fi
}

# content_length <user> <token> <url> -> bytes (empty if not found)
content_length() {
  dav_propfind "$1" "$2" "$3" 0 \
    | grep -o '<d:getcontentlength>[0-9]*</d:getcontentlength>' \
    | grep -o '[0-9]*' | head -n1
}

# Positive: each manifest file present with the right size.
while IFS=$'\t' read -r user path bytes; do
  [ -z "$user" ] && continue
  user_ctx "$user"
  wd="${F_WEBDAV[$user]}"
  DESC="file '$user:$path' migrated with size $bytes"
  if [ -z "$wd" ]; then fail "$DESC (no drive)"; continue; fi
  got=$(content_length "$user" "${F_TOKEN[$user]}" "$wd/ownCloud${path}")
  assert_eq "${got:-MISSING}" "$bytes" "$DESC"
done < "$MANIFEST"

# Negative: never-logged-in users (login=no) must have NO migrated files.
# FD 3: inner oCIS calls use `docker compose exec` and would eat the CSV stdin.
while IFS=, read -r uid pw email enabled login <&3; do
  case "$uid" in ''|\#*) continue ;; esac
  [ "$enabled" = "no" ] && continue
  [ "$login" = "no" ] || continue
  user_ctx "$uid"
  wd="${F_WEBDAV[$uid]}"
  DESC="never-logged-in user '$uid' has no migrated /ownCloud (files skipped)"
  if [ -z "$wd" ]; then pass "$DESC (no personal drive resolved)"; continue; fi
  code=$(ocis_curl "$uid" "${F_TOKEN[$uid]}" -o /dev/null -w '%{http_code}' \
    -X PROPFIND -H 'Depth: 0' "$wd/ownCloud")
  # 404 => the ownCloud folder was never created (no files migrated) => good.
  if [ "$code" = "404" ]; then pass "$DESC"; else fail "$DESC (ownCloud folder exists, http $code)"; fi
done 3< fixtures/users.csv
