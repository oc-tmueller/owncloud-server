#!/usr/bin/env bash
# Idempotent seeding orchestrator. Safe to re-run against a live stack.
set -euo pipefail

SEED_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ACCEPTANCE_DIR="${ACCEPTANCE_DIR:-$(cd "$SEED_DIR/.." && pwd)}"
export ACCEPTANCE_DIR
cd "$ACCEPTANCE_DIR"
source lib/common.sh

bash seed/01_users.sh
bash seed/02_groups.sh
bash seed/03_files.sh
bash seed/04_shares.sh

log "seeding complete"
