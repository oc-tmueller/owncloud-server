#!/usr/bin/env bash
# Groups: each group migrated; active members present; disabled member excluded.

# FD 3: inner oCIS calls use `docker compose exec` and would eat the CSV stdin.
while IFS=, read -r group members <&3; do
  case "$group" in ''|\#*) continue ;; esac

  gid=$(ocis_group_id "$group")
  DESC="group '$group' migrated to oCIS"
  if [ -n "$gid" ]; then pass "$DESC"; else fail "$DESC"; continue; fi

  members_json=$(graph "v1.0/groups/$gid/members")
  is_member() { echo "$members_json" | jq -e --arg u "$1" '.[]? | select(.onPremisesSamAccountName==$u)' >/dev/null 2>&1; }

  IFS=';' read -ra memarr <<< "$members"
  for m in "${memarr[@]}"; do
    [ -z "$m" ] && continue
    # Is this member a disabled user? (look it up in users.csv)
    enabled=$(awk -F, -v u="$m" '$1==u{print $4}' fixtures/users.csv)
    if [ "$enabled" = "no" ]; then
      DESC="disabled member '$m' excluded from group '$group'"
      if is_member "$m"; then fail "$DESC"; else pass "$DESC"; fi
    else
      DESC="member '$m' present in group '$group'"
      if is_member "$m"; then pass "$DESC"; else fail "$DESC"; fi
    fi
  done
done 3< fixtures/groups.csv
