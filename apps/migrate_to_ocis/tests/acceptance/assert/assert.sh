#!/usr/bin/env bash
# Assertion orchestrator. Runs each module against the live oCIS instance,
# aggregates pass/fail, and exits non-zero if anything failed.
set -euo pipefail
ACCEPTANCE_DIR="${ACCEPTANCE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$ACCEPTANCE_DIR"
source lib/common.sh
source assert/lib_ocis.sh

: > "$ASSERT_LOG"

for mod in assert/10_users.sh assert/20_groups.sh assert/30_roles.sh \
           assert/40_files.sh assert/50_shares.sh; do
  log "running $(basename "$mod")"
  # shellcheck source=/dev/null
  source "$mod"
done

echo
log "================ ASSERTION SUMMARY ================"
log "PASSED: $PASS_COUNT   FAILED: $FAIL_COUNT"
if [ "$FAIL_COUNT" -gt 0 ]; then
  err "acceptance assertions FAILED"
  grep '^FAIL' "$ASSERT_LOG" >&2 || true
  exit 1
fi
log "all assertions passed"
