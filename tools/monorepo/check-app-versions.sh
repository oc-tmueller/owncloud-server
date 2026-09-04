#!/usr/bin/env bash
#
# The monorepo's replacement for the release-time tag/version assertion.
#
# Usage: BASE_SHA=<sha> HEAD_SHA=<sha> tools/monorepo/check-app-versions.sh
#
# Every app used to prove its version at release time: reusable-workflows'
# release.yml compared "${GITHUB_REF_NAME#v}" against appinfo/info.xml's
# <version> and refused to publish a mismatch. That check has no anchor here.
# There is one tag per server release, so there is no per-app tag to compare
# against, and if nothing replaces it the version becomes a field nobody reads
# until an upgrade silently does not run.
#
# What replaces it runs on the pull request instead of the release, and asserts
# the three things the tag comparison was standing in for:
#
#   1. Structure, for every app directory. <id> and <version> exist, <version>
#      parses, and <id> equals the directory name. The last one is new: the
#      release workflow got the app id from the repository it was running in, so
#      an id that disagreed with its directory was not expressible. Here the
#      directory *is* the identity -- assemble-apps.sh, app-build.sh, occ
#      app:enable and the G2 signature's CN all key off it -- so a copy-pasted
#      app directory is a real and newly possible mistake.
#
#   2. Direction, for a touched app whose version changed. The new version must
#      be strictly greater than the base's. A downgrade is what makes an app's
#      migrations not run.
#
#   3. A CHANGELOG entry, for a touched app whose version changed. Not busywork:
#      11.0.0 shipped richdocuments 4.3.0, tagged v4.3.0, with a CHANGELOG whose
#      newest entry is 4.2.2. The tag check passed because the tag matched
#      info.xml; nobody was comparing either against the changelog. It is the
#      one app of 29 where this is true, and this check is what would have
#      caught it.
#
# An unchanged version is not an error. Apps are bumped when they are released,
# not on every pull request, which is how they worked as separate repos and how
# they still work here.
#
# Only the 29 external apps are subject to rules 2 and 3. The 12 bundled apps
# are versioned by core's own release process and never had a release.yml gate to
# replace. Rule 1 covers all 41, because it costs nothing and the failure it
# catches is a merge hazard rather than a release one.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MANIFEST="$ROOT/tools/monorepo/release-files-11.0.0.tsv"
CI_TSV="$ROOT/tools/monorepo/app-ci.tsv"
BUNDLED_TXT="$ROOT/build/core-bundled-apps.txt"

BASE_SHA="${BASE_SHA:-}"
HEAD_SHA="${HEAD_SHA:-HEAD}"

for f in "$MANIFEST" "$CI_TSV" "$BUNDLED_TXT"; do
  [ -f "$f" ] || { echo "missing manifest: $f" >&2; exit 2; }
done

failures=0

# Under Actions the message goes to stdout as an annotation, which is where
# GitHub reads workflow commands from; locally it goes to stderr with everything
# else this script says.
fail() {
  if [ -n "${GITHUB_ACTIONS:-}" ]; then
    printf '::error::%s\n' "$1"
  else
    printf '%s\n' "$1" >&2
  fi
  failures=$((failures + 1))
}

# info.xml is read with sed rather than an XML parser for the same reason
# assemble-apps.sh does: <id> and <version> are single-line elements in all 41
# apps, and the alternative is a python3 dependency in every script that needs
# one field. A malformed value fails the pattern check below rather than parsing
# into something plausible.
field() { # <xml-text> <element>
  printf '%s' "$1" | sed -n "s:.*<$2>\([^<]*\)</$2>.*:\1:p" | head -1
}

# Semantic-version-ish: what the 41 apps actually use is 2 to 4 numeric
# components with an optional pre-release suffix (0.4.2, 2.8.1, 10.16.0.3,
# 2.5.0RC1). Anything else is rejected rather than guessed at.
version_ok() {
  printf '%s' "$1" | grep -qE '^[0-9]+(\.[0-9]+){1,3}([.-]?[0-9A-Za-z.]+)?$'
}

# True when $1 > $2, comparing numeric components left to right and treating a
# missing component as 0, so 2.9 < 2.9.1. A pre-release suffix is ignored: it
# cannot make a version smaller, and ordering RC1 against RC2 is not something
# this check needs to have an opinion about.
version_gt() {
  awk -v a="$1" -v b="$2" '
    function nums(v, out) {
      sub(/[^0-9.].*$/, "", v)
      return split(v, out, ".")
    }
    BEGIN {
      na = nums(a, x); nb = nums(b, y)
      n = (na > nb ? na : nb)
      for (i = 1; i <= n; i++) {
        ai = (i <= na ? x[i] + 0 : 0); bi = (i <= nb ? y[i] + 0 : 0)
        if (ai > bi) exit 0
        if (ai < bi) exit 1
      }
      exit 1
    }'
}

# A heading anywhere in CHANGELOG.md that mentions the version. Loose on purpose:
# the 29 apps write that heading four different ways ("## [2.8.1] - 2026-07-22",
# "## [Unreleased] - XXXX-XX-XX", "# Changelog for [3.0.0] (2026-07-27)"), and
# the thing worth asserting is that the release was written down, not that it was
# written down in one house style.
changelog_mentions() { # <app> <version>
  local file="$ROOT/apps/$1/CHANGELOG.md"
  [ -f "$file" ] || return 1
  grep -qE "^#{1,4} .*${2//./\\.}" "$file"
}

mapfile -t EXTERNAL < <(awk -F'\t' '!/^#/ && NF { print $1 }' "$MANIFEST")
mapfile -t BUNDLED < <(sed -e 's/#.*//' -e 's/[[:space:]]//g' "$BUNDLED_TXT" | grep -v '^$')

is_external() { printf '%s\n' "${EXTERNAL[@]}" | grep -qxF "$1"; }
is_bundled() { printf '%s\n' "${BUNDLED[@]}" | grep -qxF "$1"; }
has_ci_row() { awk -F'\t' -v a="$1" '!/^#/ && $1 == a { found = 1 } END { exit !found }' "$CI_TSV"; }

check_structure() {
  local dir app xml id version n=0
  for dir in "$ROOT"/apps/*/; do
    app="$(basename "$dir")"
    if [ ! -f "$dir/appinfo/info.xml" ]; then
      fail "apps/$app has no appinfo/info.xml"
      continue
    fi
    xml="$(cat "$dir/appinfo/info.xml")"
    id="$(field "$xml" id)"
    version="$(field "$xml" version)"

    [ -n "$id" ] || fail "apps/$app: appinfo/info.xml has no <id>"
    [ -n "$version" ] || fail "apps/$app: appinfo/info.xml has no <version>"
    if [ -n "$id" ] && [ "$id" != "$app" ]; then
      fail "apps/$app: <id> is '$id' - the directory name is the app identity here (assemble, app:enable, the signature CN), so they have to agree"
    fi
    if [ -n "$version" ] && ! version_ok "$version"; then
      fail "apps/$app: <version> '$version' is not a version this can order"
    fi

    # Every app directory has to be one of the two kinds this repo knows about.
    # An app that is neither is not a harmless extra directory: it is invisible
    # to assemble-apps.sh (never shipped), to affected-apps.sh (never tested)
    # and to the dist rule (never copied), and it takes a release to notice. In
    # 30 repositories "the repository exists" was the registration; here the two
    # manifests are.
    if is_bundled "$app"; then
      :
    elif is_external "$app"; then
      has_ci_row "$app" ||
        fail "apps/$app: no row in tools/monorepo/app-ci.tsv - add one saying whether its php-unit suite passes, and why not if it does not"
    else
      fail "apps/$app: in neither build/core-bundled-apps.txt nor tools/monorepo/release-files-11.0.0.tsv - an app in neither is never built, never tested and never shipped"
    fi
    n=$((n + 1))
  done
  echo "structure: checked $n app directories" >&2
}

# The apps whose own files changed. Unlike affected-apps.sh this does not fan
# out: a change to lib/ cannot change an app's version, so there is nothing to
# check in an app the change set never touched.
touched_apps() {
  git -C "$ROOT" diff --name-only "$BASE_SHA" "$HEAD_SHA" -- 'apps/*' |
    awk -F/ 'NF > 2 { print $2 }' | LC_ALL=C sort -u
}

check_versions() {
  local app base_xml base head n=0
  while IFS= read -r app; do
    [ -n "$app" ] || continue
    is_external "$app" || continue

    head="$(field "$(cat "$ROOT/apps/$app/appinfo/info.xml")" version)"
    # A missing base file means the pull request adds the app. Nothing to
    # compare, but its first version still has to be written down.
    if base_xml="$(git -C "$ROOT" show "$BASE_SHA:apps/$app/appinfo/info.xml" 2>/dev/null)"; then
      base="$(field "$base_xml" version)"
    else
      base=""
      echo "apps/$app: new app at $head" >&2
    fi

    if [ -n "$base" ] && [ "$base" = "$head" ]; then
      continue
    fi
    local ok=true
    if [ -n "$base" ] && ! version_gt "$head" "$base"; then
      fail "apps/$app: version went from $base to $head - an app version has to move forward or its migrations do not run"
      ok=false
    fi
    if ! changelog_mentions "$app" "$head"; then
      fail "apps/$app: version $head has no heading in apps/$app/CHANGELOG.md"
      ok=false
    fi
    if [ "$ok" = true ]; then
      echo "apps/$app: ${base:-none} -> $head, in CHANGELOG.md" >&2
    fi
    n=$((n + 1))
  done < <(touched_apps)
  echo "versions: checked $n bumped app(s)" >&2
}

main() {
  check_structure

  if [ -z "$BASE_SHA" ] || ! git -C "$ROOT" cat-file -e "$BASE_SHA^{commit}" 2>/dev/null; then
    # Without a base there is no bump to inspect. Say so rather than reporting a
    # pass that checked nothing.
    echo "no usable BASE_SHA ('${BASE_SHA}') - structure only, no version comparison" >&2
  else
    check_versions
  fi

  if [ "$failures" -gt 0 ]; then
    echo "$failures problem(s)" >&2
    return 1
  fi
  echo "all app versions consistent" >&2
}

main "$@"
