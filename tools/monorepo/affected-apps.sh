#!/usr/bin/env bash
#
# Print the JSON array of apps whose test suite a change set can affect.
#
# Usage: BASE_SHA=<sha> HEAD_SHA=<sha> tools/monorepo/affected-apps.sh
#
# Prints e.g. ["notes","oauth2"] on stdout, and appends apps=<json> and
# fanout=<true|false> to $GITHUB_OUTPUT when running under Actions.
#
# The rule has two halves:
#
#   * a change under apps/<id>/ where <id> is one of the 29 external apps
#     affects that app and nothing else;
#   * anything else -- lib/, core/, settings/, one of the 12 *bundled* apps,
#     composer.lock, this script -- affects every app, so the full set is
#     emitted.
#
# The second half is the honest cost of the merge and is deliberately not
# softened: a change to apps/files can break any app that consumes its API, so
# "bundled app" lands in the fan-out bucket exactly like lib/ does. What the
# monorepo buys is that a change touching one app costs one job, which is what
# 29 separate repos already gave us; what it costs is that a core change now
# costs 29 jobs in one PR instead of 29 PRs' worth of drift discovered later.
#
# Kept as a script rather than inline YAML so it can be run and argued with
# locally, and so the fan-out rule has one place to live.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MANIFEST="$ROOT/tools/monorepo/release-files-11.0.0.tsv"

BASE_SHA="${BASE_SHA:-}"
HEAD_SHA="${HEAD_SHA:-HEAD}"

# Paths that cannot break a test suite. Deliberately short: everything not
# listed here fans out, so the failure mode of forgetting an entry is a slow CI
# run, never a missed regression.
is_inert() {
  case "$1" in
    # *.md already covers PULL_REQUEST_TEMPLATE.md and SECURITY.md; the
    # ISSUE_TEMPLATE entry is here for the .yml forms.
    *.md | .github/ISSUE_TEMPLATE/* | .github/CODEOWNERS | changelog/*) return 0 ;;
    *) return 1 ;;
  esac
}

# The external apps, in manifest order. The 12 bundled apps are absent by
# construction: they are core's own code and core's own test suite covers them.
mapfile -t EXTERNAL < <(awk -F'\t' '!/^#/ && NF { print $1 }' "$MANIFEST")

all_apps_json() {
  printf '%s\n' "${EXTERNAL[@]}" | LC_ALL=C sort |
    awk 'BEGIN { printf "[" } { printf "%s\"%s\"", (NR > 1 ? "," : ""), $0 } END { print "]" }'
}

emit() {
  local apps_json="$1" fanout="$2"
  printf '%s\n' "$apps_json"
  if [ -n "${GITHUB_OUTPUT:-}" ]; then
    printf 'apps=%s\n' "$apps_json" >> "$GITHUB_OUTPUT"
    printf 'fanout=%s\n' "$fanout" >> "$GITHUB_OUTPUT"
  fi
}

main() {
  # No base to compare against (a fresh branch, a force-push whose old tip is
  # gone, a manual run): test everything rather than guess at nothing.
  if [ -z "$BASE_SHA" ] || ! git -C "$ROOT" cat-file -e "$BASE_SHA^{commit}" 2>/dev/null; then
    echo "no usable BASE_SHA ('${BASE_SHA}') - running the full app set" >&2
    emit "$(all_apps_json)" true
    return 0
  fi

  local changed affected=() fanout=false p id
  changed="$(git -C "$ROOT" diff --name-only "$BASE_SHA" "$HEAD_SHA")"

  while IFS= read -r p; do
    [ -n "$p" ] || continue
    is_inert "$p" && continue

    id=""
    case "$p" in apps/*/*) id="${p#apps/}"; id="${id%%/*}" ;; esac

    if [ -n "$id" ] && printf '%s\n' "${EXTERNAL[@]}" | grep -qxF "$id"; then
      affected+=("$id")
    else
      echo "fan-out triggered by: $p" >&2
      fanout=true
      break
    fi
  done <<< "$changed"

  if [ "$fanout" = true ]; then
    emit "$(all_apps_json)" true
    return 0
  fi

  if [ "${#affected[@]}" -eq 0 ]; then
    echo "no app-affecting changes" >&2
    emit '[]' false
    return 0
  fi

  emit "$(printf '%s\n' "${affected[@]}" | LC_ALL=C sort -u |
    awk 'BEGIN { printf "[" } { printf "%s\"%s\"", (NR > 1 ? "," : ""), $0 } END { print "]" }')" false
}

main "$@"
