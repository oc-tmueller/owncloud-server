#!/usr/bin/env bash
# Roles: each migrated non-admin user has an app role assignment.
# (assign-role applies the chosen role to all migrated users except admin.)

# FD 3: inner oCIS calls use `docker compose exec` and would eat the CSV stdin.
while IFS=, read -r uid pw email enabled login <&3; do
  case "$uid" in ''|\#*) continue ;; esac
  [ "$enabled" = "no" ] && continue          # disabled users were not migrated
  [ "$uid" = "$OCIS_ADMIN" ] && continue     # admin keeps its own role

  uoid=$(ocis_user_id "$uid")
  DESC="user '$uid' resolvable in oCIS for role check"
  if [ -z "$uoid" ]; then fail "$DESC"; continue; else pass "$DESC"; fi

  assignments=$(graph "v1.0/users/$uoid/appRoleAssignments")
  count=$(echo "$assignments" | jq '[.value[]?]|length' 2>/dev/null || echo 0)
  DESC="user '$uid' has an app role assigned (count=$count)"
  if [ "${count:-0}" -ge 1 ]; then pass "$DESC"; else fail "$DESC"; fi
done 3< fixtures/users.csv
