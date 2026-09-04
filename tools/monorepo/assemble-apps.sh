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
# What each app has to *generate* first also comes from that manifest, as the
# build_targets column, but the work is still done by the app's own Makefile: we
# say *which* targets, the app says what they mean. That step lives in
# app-build.sh because CI needs it too, and an app prepared two different ways
# for release and for test is a bug waiting for a release.
#
# The post-copy cleanup is the one thing this script does that the manifest does
# not describe, because it is uniform: core's own dist rule already sweeps dev
# files out of its dependency trees, and applying the same sweep to every app
# beats 29 apps each remembering to do it (several did not).

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
# Make the app's composer vendor/ a release artefact again.
#
# `vendor` is a *directory* target, so `make vendor` on an existing vendor/ does
# nothing at all -- whatever is in it ships. That was safe while releases built
# in a fresh clone; in one tree the dev loop gets there first. Running an app's
# unit tests installs its dev dependencies into the same directory (the apps'
# `vendor/bin/phpunit` target is a plain `composer install`, no --no-dev), and a
# release built afterwards shipped bamarni/composer-bin-plugin and a dev
# autoloader. Measured, not hypothetical: it is what the first complete-variant
# parity run found in openidconnect and migrate_to_ocis, and it is the same
# defect the reference already has in twofactor_totp, reached the same way.
#
# So remove it and let the app's own `composer install --no-dev` rebuild it from
# its committed lock. Only the exact target `vendor` is treated this way -- the
# 9 composer apps. user_ldap's `vendor/ui-multiselect` is a downloaded js
# dependency with no dev/prod distinction, and the js targets (build, js-deps,
# js-templates) compile tracked sources with a pinned toolchain, so neither can
# carry dev state across from a test run.
# ---------------------------------------------------------------------------
clean_composer_vendor() {
  local app="$1" build_targets="$2" t
  for t in $build_targets; do
    [ "$t" = vendor ] || continue
    log "[$app] removing vendor/ so composer rebuilds it without dev dependencies"
    rm -rf "$ROOT/apps/$app/vendor"
  done
}

# ---------------------------------------------------------------------------
# ... and then prove it, on the tree that actually ships.
#
# The cleanup above fixes the one way we know dev dependencies got in. This
# catches every other way, including an app whose build target is not `vendor`
# at all: composer records what it installed in vendor/composer/installed.json,
# so the shipped tree can be asked directly instead of trusted. Cheap -- one php
# invocation per app that ships a vendor/ -- and it fails the build rather than
# waiting for a parity run against a reference that may itself be wrong.
# ---------------------------------------------------------------------------
assert_no_dev_deps() {
  local app="$1" target="$2"
  local json="$target/vendor/composer/installed.json"

  [ -f "$json" ] || return 0
  # The $j and $argv in here are PHP variables, so the single quotes are the
  # point: the shell must not touch them.
  # shellcheck disable=SC2016
  php -r '
    $j = json_decode(file_get_contents($argv[1]), true);
    if ($j === null) {
      fwrite(STDERR, "unreadable: {$argv[1]}\n");
      exit(1);
    }
    $dev = $j["dev-package-names"] ?? [];
    if (!empty($j["dev"]) || $dev !== []) {
      fwrite(STDERR, sprintf("  installed with dev dependencies: %s\n",
        $dev === [] ? "\"dev\": true" : implode(", ", $dev)));
      exit(1);
    }' "$json" \
    || die "[$app] refusing to ship vendor/: see above, and check whether a test run installed into it"
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

  # Clear the destination first, as ocrelease's resolve step does before unpacking
  # each app tarball. Without it a rebuild layers the new tree over the old one and
  # anything the previous run shipped survives forever -- which is exactly the kind
  # of difference the parity check exists to catch, appearing only on rebuilds.
  # None of the 12 bundled apps is in the manifest, so this cannot eat core's work.
  rm -rf "$target"
  mkdir -p "$target"
  # shellcheck disable=SC2086
  tar -C "$ROOT/apps/$app" -cf - $paths | tar -C "$target" -xf -

  # The same dev-file strip core's dist rule applies to its own tree. Upstream
  # each app's release process did this individually, which is why 5 apps still
  # shipped an l10n/.tx/config and richdocuments shipped .gitkeep and no-php.
  find "$target" \( -name .gitkeep -o -name .gitignore -o -name no-php \) -delete
  find "$target" -type d -name .tx -prune -exec rm -rf {} +

  # Handlebars sources are build *inputs*: the js-templates target compiles them
  # into one templates.js, and shipping the .handlebars alongside it ships the
  # source of a compiled artefact. customgroups deletes them in its own dist rule
  # after copying, for exactly that reason; only customgroups has any today, but
  # the reason is not app-specific so neither is the rule.
  find "$target" -name '*.handlebars' -delete

  sweep_dep_trees "$app" "$target"
  assert_no_dev_deps "$app" "$target"
}

# ---------------------------------------------------------------------------
# Dev files inside the dependency trees an app ships.
#
# Core's dist rule already sweeps its own lib/composer and core/vendor with this
# list; the apps were each meant to do the same and 5 of the 10 that ship a
# vendor/ actually did (`find $@/vendor -type d -iname Test?`, in four slightly
# different spellings). Doing it here once covers all of them.
#
# Scoped to the dependency trees rather than the whole app directory, and with
# core's `-name bin` dropped, because an app's own files are allowlisted content:
# migrate_to_ocis ships bin/rclone_linux_amd64 deliberately, and the reference
# keeps vendor/bin/* too -- which is why ocrelease's chmod is scoped to depth 3.
# ---------------------------------------------------------------------------
sweep_dep_trees() {
  local app="$1" target="$2" d
  for d in vendor lib/composer js/vendor; do
    [ -d "$target/$d" ] || continue
    # -iname for test/tests: core spells them lowercase, the app rules use
    # `-iname Test?`, and phpseclib et al. ship a capitalised `Tests`.
    find "$target/$d" \( \
      -iname test -o \
      -iname tests -o \
      -name examples -o \
      -name demo -o \
      -name demos -o \
      -name doc -o \
      -name travis -o \
      -iname '*.sh' -o \
      -iname '*.exe' \
      \) -print0 | xargs -0 rm -rf
  done
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
  local apps=() app row paths build_targets n=0

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
    # What to build is app-build.sh's business -- CI calls that too -- but the
    # target names are needed here to know whether a composer vendor/ is in play.
    IFS=$'\t' read -r _ _ _ build_targets paths <<< "$row"

    clean_composer_vendor "$app" "$build_targets"
    "$ROOT/tools/monorepo/app-build.sh" "$app"
    copy_app "$app" "$paths"
    n=$((n + 1))
  done

  set_permissions
  log "assembled $n entries; $(find "$DEST" -type f | wc -l | tr -d ' ') files in tree"
}

main "$@"
