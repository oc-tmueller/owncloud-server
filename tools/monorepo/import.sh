#!/usr/bin/env bash
#
# Build the ownCloud 11 monorepo from owncloud/core plus every PUBLIC app repo
# listed in apps-11.0.0.tsv, preserving full history.
#
# Each app repo's history is rewritten so its files live under the app's target
# directory (apps/<id>/, or core/skeleton/ for example-files), then merged into
# the core trunk with --allow-unrelated-histories. App tags are namespaced as
# <id>/<tag> because plain tags collide across repos (drawio and
# files_pdfviewer both carry v1.1.1).
#
# Not incremental. To re-run, delete $MONO and $SRC and start over.
#
# Usage: tools/monorepo/import.sh [app_id ...]
#   With no arguments, imports every public app in the manifest.

set -euo pipefail

POC_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MANIFEST="$POC_ROOT/tools/monorepo/apps-11.0.0.tsv"
MONO="$POC_ROOT/monorepo"
SRC="$POC_ROOT/work/src"
CORE_TAG="v11.0.0"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

command -v git-filter-repo >/dev/null || die "git-filter-repo not found (brew install git-filter-repo)"
[ -f "$MANIFEST" ] || die "manifest not found: $MANIFEST"

mkdir -p "$SRC"

# ---------------------------------------------------------------------------
# Manifest: app_id  repo  tag  visibility  variants  target_directory
# target_directory in the manifest is release-tree relative ("owncloud/apps/x");
# strip the leading "owncloud/" to get the repo-relative path.
# ---------------------------------------------------------------------------
public_apps() {
  awk -F'\t' '!/^#/ && NF>=6 && $4=="public" { print $1"\t"$2"\t"$3"\t"$6 }' "$MANIFEST"
}

# ---------------------------------------------------------------------------
# Step 1: seed the monorepo from owncloud/core.
# ---------------------------------------------------------------------------
seed_core() {
  if [ -d "$MONO/.git" ]; then
    log "core trunk already present, skipping seed"
    return
  fi
  log "cloning owncloud/core (full history, ~350MB)"
  git clone https://github.com/owncloud/core.git "$MONO"
  git -C "$MONO" checkout -B monorepo "$CORE_TAG"
  log "core trunk at $CORE_TAG: $(git -C "$MONO" rev-parse --short HEAD)"
}

# ---------------------------------------------------------------------------
# Two tags whose names differ only in case cannot both exist as loose refs on a
# case-insensitive filesystem, so on macOS filter-repo dies writing the second
# one. Across all 2635 tags in the 30 public repos there is exactly one such
# pair: richdocuments has V2.5.0RC1 and v2.5.0RC1, pointing at DIFFERENT
# commits. One of them has to go.
#
# Rule: never drop the tag that is a commit's only reference. A tag whose commit
# is an ancestor of the default branch is just a label -- the commit survives
# without it -- whereas an unreachable commit disappears with its tag. So keep
# the unreachable one and drop the reachable one, which for richdocuments keeps
# v2.5.0RC1 (also the casing its other 37 v-tags use) and drops V2.5.0RC1,
# whose commit stays reachable from master regardless.
#
# Every deletion is logged with its sha. The upstream repo remains the
# historical record either way.
# ---------------------------------------------------------------------------
resolve_tag_case_collisions() {
  local id="$1" dir="$2" default_branch="$3"
  local group keep t sha

  while read -r group; do
    [ -n "$group" ] || continue

    keep=""
    for t in $group; do
      if ! git -C "$dir" merge-base --is-ancestor \
             "$t^{commit}" "refs/remotes/origin/$default_branch" 2>/dev/null; then
        keep="$t"
        break
      fi
    done
    if [ -z "$keep" ]; then
      # All reachable: no history is at stake, so pick deterministically.
      # $group is a space-separated tag list and is meant to split here, the
      # same way the `for t in $group` loop above splits it.
      # shellcheck disable=SC2086
      keep="$(printf '%s\n' $group | sort | head -1)"
    fi

    for t in $group; do
      [ "$t" = "$keep" ] || {
        sha="$(git -C "$dir" rev-parse --short "$t^{commit}")"
        log "[$id] dropping tag '$t' ($sha): case-collides with kept tag '$keep'"
        git -C "$dir" tag -d "$t" >/dev/null
      }
    done
  done < <(git -C "$dir" tag | awk '
    { k = tolower($0); g[k] = g[k] " " $0; n[k]++ }
    END { for (k in n) if (n[k] > 1) print substr(g[k], 2) }')
}

# ---------------------------------------------------------------------------
# Step 2: rewrite one app repo's history into its target subdirectory.
# ---------------------------------------------------------------------------
rewrite_app() {
  local id="$1" repo="$2" tag="$3" target="$4"
  local dir="$SRC/$id"

  if [ -f "$dir/.git/filter-repo-done" ]; then
    log "[$id] already rewritten, skipping"
    return
  fi

  rm -rf "$dir"
  log "[$id] cloning $repo"
  git clone --quiet "https://github.com/$repo.git" "$dir"

  # The tag must exist, otherwise we would silently import a different tree
  # than the release shipped.
  git -C "$dir" rev-parse --verify --quiet "refs/tags/$tag" >/dev/null \
    || die "[$id] tag $tag not found in $repo"

  # Drop every remote-tracking branch except the default one, keeping ALL tags.
  #
  # Two reasons. (1) Correctness on macOS: filter-repo turns remote-tracking
  # refs into local branches, and files_pdfviewer has both origin/0.X and
  # origin/0.x -- distinct refs that collide as the same path on a
  # case-insensitive filesystem, which fails with a stale-looking
  # "cannot lock ref" error. (2) Hygiene: we do not want 20+ abandoned feature
  # branches per app grafted into the monorepo's ref space. The default branch
  # plus all tags is what "full history" means in practice -- blame on every
  # shipped file still traces back through the mainline.
  #
  # Filtering happens inside the loop rather than through `grep -v`: an app with
  # no extra branches (drawio) gives grep nothing to match, and its exit 1 kills
  # the script under `set -o pipefail`.
  local default_branch ref
  default_branch="$(git -C "$dir" symbolic-ref --short refs/remotes/origin/HEAD 2>/dev/null || echo origin/master)"
  default_branch="${default_branch#origin/}"
  while read -r ref; do
    case "$ref" in
      refs/remotes/origin/HEAD | "refs/remotes/origin/$default_branch") continue ;;
    esac
    git -C "$dir" update-ref -d "$ref"
  done < <(git -C "$dir" for-each-ref --format='%(refname)' refs/remotes/origin)
  log "[$id] kept default branch '$default_branch' + $(git -C "$dir" tag | wc -l | tr -d ' ') tags"

  resolve_tag_case_collisions "$id" "$dir" "$default_branch"

  log "[$id] rewriting history into $target/ and namespacing tags as $id/*"
  git -C "$dir" filter-repo \
    --force \
    --to-subdirectory-filter "$target" \
    --tag-rename ":$id/"

  touch "$dir/.git/filter-repo-done"
}

# ---------------------------------------------------------------------------
# Step 3: merge a rewritten app into the trunk at its release tag.
# ---------------------------------------------------------------------------
merge_app() {
  local id="$1" tag="$2"
  local dir="$SRC/$id"

  # The import merge commit's subject is the only durable record that this app
  # landed -- the remote is removed below, so refs/remotes/<id>/ is gone.
  if [ -n "$(git -C "$MONO" log --format=%H --grep="^chore: import $id at " HEAD)" ]; then
    log "[$id] already merged, skipping"
    return
  fi

  git -C "$MONO" remote remove "$id" 2>/dev/null || true
  git -C "$MONO" remote add "$id" "$dir"
  git -C "$MONO" fetch --quiet --tags "$id"

  # Merge the app's tagged release commit, not its default-branch tip: the
  # monorepo must reproduce the 11.0.0 release tree exactly. The tag was
  # namespaced by filter-repo above.
  local ref="refs/tags/$id/$tag"
  git -C "$MONO" rev-parse --verify --quiet "$ref" >/dev/null \
    || die "[$id] namespaced tag $id/$tag missing after fetch"

  log "[$id] merging $id/$tag into trunk"
  git -C "$MONO" merge --allow-unrelated-histories --no-ff --quiet \
    -m "chore: import $id at $tag

Imported from https://github.com/$id with full history, rewritten into its
release target directory by tools/monorepo/import.sh. Original tags are
preserved under the $id/ namespace." \
    "$ref"

  git -C "$MONO" remote remove "$id"
}

# ---------------------------------------------------------------------------
main() {
  seed_core

  local selected=("$@")
  local n=0
  while IFS=$'\t' read -r id repo tag target_rel; do
    local target="${target_rel#owncloud/}"
    if [ ${#selected[@]} -gt 0 ] && ! printf '%s\n' "${selected[@]}" | grep -qx "$id"; then
      continue
    fi
    rewrite_app "$id" "$repo" "$tag" "$target"
    merge_app "$id" "$tag"
    n=$((n + 1))
  done < <(public_apps)

  log "imported $n app(s)"
  log "trunk: $(git -C "$MONO" rev-parse --short HEAD)  commits: $(git -C "$MONO" rev-list --count HEAD)"
}

main "$@"
