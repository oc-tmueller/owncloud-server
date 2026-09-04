#!/usr/bin/env bash
#
# Layer the variant apps onto an already-built core dist tree, reproducing what
# ocrelease's assemble step does when it unpacks 43 per-app release tarballs.
#
# Usage: tools/monorepo/assemble-apps.sh <dist-tree> <variant>
#   e.g. tools/monorepo/assemble-apps.sh build/dist/owncloud standard
#
# Order matters and matches ocrelease: core's own dist rule runs first (it ships
# the 12 bundled apps and applies its own denylist sweep to them), then this
# script adds the variant apps on top, then permissions are set over the whole
# tree. Nothing here touches the 12 bundled apps.
#
# What ships for each app comes from tools/monorepo/release-files-11.0.0.tsv --
# one reviewed allowlist -- rather than from 29 divergent per-app `dist` targets.
# What each app has to *generate* first still comes from the app itself: `make
# vendor` for composer deps, plus an asset_target for the four apps whose JS is
# built rather than tracked.

set -euo pipefail

DEST="${1:?usage: assemble-apps.sh <dist-tree> <variant>}"
VARIANT="${2:?usage: assemble-apps.sh <dist-tree> <variant>}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MANIFEST="$ROOT/tools/monorepo/release-files-11.0.0.tsv"
VARIANT_FILE="$ROOT/build/variants/$VARIANT.txt"
SKELETON="$ROOT/build/skeleton"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

[ -d "$DEST" ]           || die "dist tree not found: $DEST (run 'make dist-dir' first)"
[ -f "$MANIFEST" ]       || die "manifest not found: $MANIFEST"
[ -f "$VARIANT_FILE" ]   || die "unknown variant '$VARIANT' (no $VARIANT_FILE)"

# ---------------------------------------------------------------------------
# The skeleton shipped to users is example-files' payload, NOT core's
# core/skeleton -- which holds a single welcome.txt that core's own acceptance
# tests expect to find in a fresh user home. Upstream those two never met,
# because ocrelease rm -rf's the destination and unpacks the example-files
# release over it. In one repo they would collide, so the release payload lives
# in build/skeleton/ and gets installed here, at release time, exactly as
# ocrelease does it.
# ---------------------------------------------------------------------------
install_skeleton() {
  [ -d "$SKELETON" ] || die "release skeleton not found: $SKELETON"
  log "installing release skeleton over core/skeleton"
  rm -rf "$DEST/core/skeleton"
  mkdir -p "$DEST/core/skeleton"
  cp -R "$SKELETON/." "$DEST/core/skeleton/"
}

# ---------------------------------------------------------------------------
# Generate what is not in git, using the app's own machinery.
# ---------------------------------------------------------------------------
build_app() {
  local app="$1" paths="$2" asset_target="$3"

  # `make vendor` is defined identically by 28 of the 29 apps. Only run it when
  # the app actually ships a vendor/ directory -- otherwise we would build
  # dependencies just to throw them away.
  case " $paths " in
    *" vendor "*)
      log "[$app] make vendor (composer release deps)"
      make -C "$ROOT/apps/$app" vendor
      ;;
  esac

  if [ "$asset_target" != "-" ]; then
    log "[$app] make $asset_target (generated JS assets)"
    # Unquoted on purpose: files_mediaviewer needs two targets in order.
    # shellcheck disable=SC2086
    make -C "$ROOT/apps/$app" $asset_target
  fi
}

# ---------------------------------------------------------------------------
# Copy exactly the allowlisted paths. tar rather than cp because some paths are
# nested (notes ships js/public and js/vendor, not all of js/) and tar recreates
# the intermediate directories without cp --parents' GNU-only flag.
# ---------------------------------------------------------------------------
copy_app() {
  local app="$1" paths="$2"
  local target="$DEST/apps/$app" missing=0 p

  for p in $paths; do
    [ -e "$ROOT/apps/$app/$p" ] || { printf '  missing path: %s\n' "$p" >&2; missing=1; }
  done
  [ "$missing" -eq 0 ] || die "[$app] allowlisted paths missing from the tree (see above)"

  mkdir -p "$target"
  # shellcheck disable=SC2086
  tar -C "$ROOT/apps/$app" -cf - $paths | tar -C "$target" -xf -

  # The same dev-file strip core's dist rule applies to its own tree. Upstream
  # each app's release process did this individually, which is why 5 apps still
  # shipped an l10n/.tx/config and richdocuments shipped .gitkeep and no-php.
  find "$target" \( -name .gitkeep -o -name .gitignore -o -name no-php \) -delete
  find "$target" -type d -name .tx -prune -exec rm -rf {} +
}

# ---------------------------------------------------------------------------
# ocrelease assemble steps 4-5, applied once over the finished tree.
# ---------------------------------------------------------------------------
set_permissions() {
  log "normalising permissions"
  find "$DEST" -type d -exec chmod 755 {} +
  find "$DEST" -type f -exec chmod 644 {} +
  [ -f "$DEST/occ" ] && chmod 755 "$DEST/occ"

  # Restore the exec bit on bundled app binaries. This is not only about being
  # able to run them: Trivy's gobinary analyzer only inspects files that are
  # executable, so a 644 Go binary is silently never scanned for CVEs.
  find "$DEST/apps" -mindepth 3 -maxdepth 3 -path '*/bin/*' -type f -exec chmod 755 {} +
}

main() {
  local apps=() app row kind tree asset_target paths n=0

  while read -r app; do
    case "$app" in '#'* | '') continue ;; esac
    apps+=("$app")
  done < "$VARIANT_FILE"

  log "assembling variant '$VARIANT' (${#apps[@]} entries) into $DEST"

  for app in "${apps[@]}"; do
    if [ "$app" = "example-files" ]; then
      install_skeleton
      n=$((n + 1))
      continue
    fi

    row="$(awk -F'\t' -v a="$app" '!/^#/ && $1 == a' "$MANIFEST")"
    [ -n "$row" ] || die "[$app] no row in $(basename "$MANIFEST")"
    IFS=$'\t' read -r _ kind tree asset_target paths <<< "$row"

    build_app "$app" "$paths" "$asset_target"
    copy_app "$app" "$paths"
    n=$((n + 1))
  done

  set_permissions
  log "assembled $n entries; $(find "$DEST" -type f | wc -l | tr -d ' ') files in tree"
}

main "$@"
