#!/usr/bin/env bash
#
# Generate an app's untracked build output, using the app's own make targets.
#
# Usage: tools/monorepo/app-build.sh <app_id>
#
# Which targets those are comes from the build_targets column of
# tools/monorepo/release-files-11.0.0.tsv; what they mean is the app's business.
# 16 of the 29 apps need nothing, and for them this is a no-op.
#
# Both the release assembly (assemble-apps.sh) and CI go through here, so an app
# is prepared exactly one way. Upstream the two diverged: the release ran the
# app's `dist`/`ci` prerequisites, while reusable-workflows/php-unit.yml ran
# `make vendor || true` -- which is wrong twice over, since 19 of the 29 apps have
# no `vendor` target at all and `|| true` hides the failure when one does and it
# breaks.
set -euo pipefail

APP="${1:?usage: app-build.sh <app_id>}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MANIFEST="$ROOT/tools/monorepo/release-files-11.0.0.tsv"

die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

[ -d "$ROOT/apps/$APP" ] || die "no such app: apps/$APP"
[ -f "$MANIFEST" ]       || die "manifest not found: $MANIFEST"

row="$(awk -F'\t' -v a="$APP" '!/^#/ && $1 == a' "$MANIFEST")"
[ -n "$row" ] || die "[$APP] no row in $(basename "$MANIFEST")"
IFS=$'\t' read -r _ _ _ build_targets _ <<< "$row"

[ "$build_targets" = "-" ] && exit 0

# The version the app declares for itself. info.xml stays the single source of
# truth for app versions in this layout, so read it rather than a git tag.
version="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' \
             "$ROOT/apps/$APP/appinfo/info.xml" | head -1)"
[ -n "$version" ] || die "[$APP] no <version> in appinfo/info.xml"

printf '\033[1;34m==>\033[0m [%s] make %s\n' "$APP" "$build_targets"

# `cd` rather than `make -C`: 25 of the 29 app Makefiles build paths from
# $(PWD) -- e.g. PHPUNIT=php "$(PWD)/../../lib/composer/bin/phpunit" -- and make
# neither sets nor updates PWD, it inherits it from the environment. Under
# `make -C apps/activity` PWD is still the caller's directory, so those paths
# resolve one level short and silently point outside the tree. Upstream never hit
# this because reusable-workflows/php-unit.yml also does `cd apps/<app> && make`.
#
# Unquoted $build_targets on purpose: several apps need two targets, in the order
# given.
#
# COMPOSER_ROOT_VERSION is exported for every app, not just the composer ones:
# composer records the root package's own version in vendor/composer/installed.php,
# guessing it from the checked-out git tag. In a separate repo built at v0.10.1
# that guess was right; here the only tag on this commit is the *server* release
# tag, and the app's own tags are namespaced (market/v0.10.1), so the guess can
# never land on the app version. Feed it from the place that is authoritative
# anyway. Harmless for the non-composer targets.
# shellcheck disable=SC2086
cd "$ROOT/apps/$APP" && COMPOSER_ROOT_VERSION="v$version" make $build_targets
