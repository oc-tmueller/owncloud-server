#!/usr/bin/env bash
# Drive the 7 migration occ commands, piping stdin answers past the interactive
# prompts. Each step is tee'd to artifacts/0N-*.log.
#
# A non-zero exit from a command is fatal. Per-user "skipped/error" lines (for
# the disabled / never-logged-in users) are BY DESIGN non-fatal and must not be
# treated as failures.
set -euo pipefail
ACCEPTANCE_DIR="${ACCEPTANCE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$ACCEPTANCE_DIR"
source lib/common.sh

A="$ARTIFACTS"

step() {                       # step <logfile> <stdin-or-empty> <occ args...>
  local logf="$1" stdin="$2"; shift 2
  log "occ $*"
  if [ -n "$stdin" ]; then
    printf '%b' "$stdin" | occ "$@" 2>&1 | tee "$A/$logf"
  else
    occ "$@" 2>&1 | tee "$A/$logf"
  fi
}

# 1. init (insecure: self-signed oCIS cert). -f resets so re-runs work.
step 01-init.log    "" migrate:to-ocis:init -k -f "$OCIS_HOST"
# 2. verify ownCloud Classic readiness (emails unique/valid).
step 02-verify.log  "" migrate:to-ocis:verify
# 3. migrate users (prompts: password).
step 03-users.log   "$OCIS_PW\n" migrate:to-ocis:migrate:users "$OCIS_ADMIN"
# 4. assign role (prompts: password, optional app choice, role choice).
#    oCIS returns the roles in a NON-DETERMINISTIC order, so we must NOT pick by
#    index: index 0 is sometimes "User Light", which has no personal drive and
#    makes the later file migration fail with 409 Conflict (see issue #42).
#    Symfony's ChoiceQuestion also accepts the choice *label*, so we pick the
#    standard "User" role by name -> stable regardless of the returned order.
#    The trailing '0' is harmless (selects the default) if an app choice is also
#    asked. Result is asserted independently in assert/30_roles.sh.
step 04-role.log    "$OCIS_PW\nUser\n0\n" migrate:to-ocis:assign-role "$OCIS_ADMIN"
# 5. migrate groups (prompts: password).
step 05-groups.log  "$OCIS_PW\n" migrate:to-ocis:migrate:groups "$OCIS_ADMIN"
# 6. migrate files via rclone (prompts: password).
step 06-files.log   "$OCIS_PW\n" migrate:to-ocis:migrate:files "$OCIS_ADMIN"
# 7. migrate shares (prompts: password).
step 07-shares.log  "$OCIS_PW\n" migrate:to-ocis:migrate:shares "$OCIS_ADMIN"

log "migration commands completed"
