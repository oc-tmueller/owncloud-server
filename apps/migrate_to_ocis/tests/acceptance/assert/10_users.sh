#!/usr/bin/env bash
# Users: active users migrated with correct mail; disabled user absent.
# (sourced by assert.sh; helpers/state come from common.sh + lib_ocis.sh)

users_json=$(graph "v1.0/users")
# Sanity floor: an empty/short response means the Graph call failed (token blip,
# outage). Without this the per-row loop below would emit zero assertions and the
# run would still report PASS. At minimum admin + the migrated users must exist.
require_count "$users_json" '.value // []' 2 "users"

user_present() { echo "$users_json" | jq -e --arg u "$1" '.value[]? | select(.onPremisesSamAccountName==$u)' >/dev/null 2>&1; }
user_mail() { echo "$users_json" | jq -r --arg u "$1" '.value[]? | select(.onPremisesSamAccountName==$u) | .mail' | head -n1; }

# Positive: active users present with correct email.
# FD 3: inner oCIS calls use `docker compose exec` and would eat the CSV stdin.
while IFS=, read -r uid pw email enabled login <&3; do
  case "$uid" in ''|\#*) continue ;; esac
  if [ "$enabled" = "no" ]; then
    # Negative: disabled user must NOT be migrated.
    DESC="disabled user '$uid' is absent in oCIS"
    if user_present "$uid"; then fail "$DESC"; else pass "$DESC"; fi
  else
    DESC="user '$uid' migrated to oCIS"
    if user_present "$uid"; then pass "$DESC"; else fail "$DESC"; fi
    assert_eq "$(user_mail "$uid")" "$email" "user '$uid' has correct email"
  fi
done 3< fixtures/users.csv
