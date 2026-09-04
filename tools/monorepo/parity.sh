#!/usr/bin/env bash
#
# Prove that what one checkout builds is what ownCloud 11.0.0 shipped.
#
# Usage: tools/monorepo/parity.sh <variant> <reference-tree> [built-tree]
#   e.g. tools/monorepo/parity.sh standard work/ref/std/owncloud
#        tools/monorepo/parity.sh complete work/ref/cmpl/owncloud
#
# <reference-tree> is the extracted official tarball -- the diff target, never
# regenerated. [built-tree] defaults to build/dist/owncloud.
#
# The check is an allowlist, not a filter: every difference has to fall into one
# of the classes below, each of which is asserted narrowly enough that the same
# leak appearing somewhere new is a failure rather than a shrug. Anything else
# is a finding, and a file we ship that the reference does not have is always a
# finding.
#
#   BUILD_STAMP  version.php, and only its $OC_Build line. Upstream stamps the
#                build date and the core sha; ours is a monorepo sha by
#                construction.
#   ROOT_VERSION vendor/composer/installed.php, and only its 'reference' lines
#                -- the git sha composer records for the root package. Same
#                cause as BUILD_STAMP. The declared version itself is pinned
#                from version.php / info.xml, so it must match.
#   SIGNED       appinfo/signature.json (and core/signature.json). G2 signing
#                needs one CN == <appId> leaf certificate per app, which this
#                POC does not have. Asserted in one direction only, and every
#                file the signature covers must still hash equal.
#   STRIP        .gitkeep / .gitignore / no-php / l10n/.tx, which 5 apps still
#                shipped because their own release targets never swept them.
#                assemble-apps.sh applies core's sweep to every app.
#   RAW          the apps marked upstream_tree=raw in release-files-11.0.0.tsv,
#                whose `dist: source appstore` targets tar the whole working
#                tree -- so 11.0.0 ships their .git/, tests/ and build/. Our
#                tree is a deliberate strict subset. Allowed for those apps and
#                no others.
#   PRIVATE      complete variant only: the apps this POC leaves out of scope.
#                Asserted against the named list, so a 15th missing app fails.
#   SWEEP        dev files inside an app's dependency trees -- library test
#                suites, bower demos, a build-phar.sh. Core's dist rule already
#                sweeps its own lib/composer and core/vendor with this list, and
#                5 of the 10 apps that ship a vendor/ swept theirs too, in four
#                different spellings; assemble-apps.sh does it for all of them.
#                175 files in the complete variant. Scoped to the dependency
#                trees, so an app's own tests/ directory is not covered here.
#   DEV_DEPS     one app -- twofactor_totp -- shipped its *development*
#                dependencies in 11.0.0. Not inferred: the reference's own
#                vendor/composer/installed.json says "dev": true and names them
#                in dev-package-names. Its Makefile has an empty composer_deps,
#                so `make dist` never installed anything and the release simply
#                inherited whatever the CI's plain `composer install` had left in
#                the workspace. Asserted as a set, not a direction: our installed
#                packages must be EXACTLY the reference's minus its declared dev
#                packages, which is what makes the composer-generated files in
#                that app safe to accept as differing.
#   STALE_VENDOR one app -- migrate_to_ocis -- shipped a vendor/ that was not
#                built from its own tagged composer.lock. Composer derives the
#                ComposerAutoloaderInit<suffix> from the lock's content-hash, and
#                for 8 of the 9 apps that ship an autoloader the reference's
#                suffix equals the checked-in lock's hash exactly. For this one it
#                does not; its packages also record "installation-source":
#                "source" (so the release ships three full git clones, packfiles
#                included) and its platform_check.php still uses the pre-2.8
#                trigger_error form. An older composer, a different lock, a
#                vendor tree older than the release it shipped in. This is the
#                43-repo release model showing through: each app's vendor was
#                generated whenever that app last happened to be released, so the
#                tarball is a fossil record of several toolchain states. One repo
#                regenerates all of them with one toolchain.
#   STRAY        apps/customgroups/lib/composer, a zero-byte file. Its rules/deps.mk
#                sets composer_deps=lib/composer and ends the recipe with
#                `touch $@`, but composer's vendor-dir is the default vendor/, so
#                the touch only ever creates an empty file -- which then ships,
#                because lib/ is allowlisted. The app has no production
#                dependencies at all, so nothing reads it.
#   EXEC_BIT     apps/<app>/bin/<file> shipped 644 in the reference and 755 here.
#                Not a monorepo artefact: ocrelease grew that chmod in
#                0e571ca90b (2026-08-18), three weeks after 11.0.0 was cut
#                (2026-07-30), so the reference predates its own fix. We follow
#                ocrelease main. Only migrate_to_ocis/bin/rclone_linux_amd64 is
#                affected today, and it matters -- Trivy's gobinary analyzer
#                skips files without an exec bit, so shipped 644 it is never
#                scanned for CVEs.
#
# Modes are compared as well as contents, because the mode is what that last
# class is about and `diff -r` cannot see it.

set -euo pipefail

VARIANT="${1:?usage: parity.sh <variant> <reference-tree> [built-tree]}"
REF="${2:?usage: parity.sh <variant> <reference-tree> [built-tree]}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="${3:-$ROOT/build/dist/owncloud}"
MANIFEST="$ROOT/tools/monorepo/release-files-11.0.0.tsv"
APPS_TSV="$ROOT/tools/monorepo/apps-11.0.0.tsv"

[ -d "$REF" ] || { echo "reference tree not found: $REF" >&2; exit 2; }
[ -d "$OUT" ] || { echo "built tree not found: $OUT (run 'make dist-server' first)" >&2; exit 2; }

REF="$(cd "$REF" && pwd)"
OUT="$(cd "$OUT" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

bold() { printf '\033[1m%s\033[0m\n' "$*"; }

# ---------------------------------------------------------------------------
# Locally modified tracked files, if this is a git checkout and git is present.
# Not a check -- a diagnostic, printed up front because an unexplained CONTENT
# difference in a file the working tree has modified is almost never a bug in
# the build.
#
# Two things do this without being asked. `occ maintenance:install` appends its
# ErrorDocument lines to the checkout's own .htaccess, which the dist rule then
# copies -- so installing a server to run an app's tests changes a release
# input. And user_ldap's node dependency target runs `npm install`, not
# `npm ci`, which rewrites its committed package-lock.json from lockfileVersion
# 1 to 3. In separate repositories both were invisible: release builds ran in a
# fresh clone and the dirty tree was thrown away. Here the same checkout is the
# dev loop and the release input.
# ---------------------------------------------------------------------------
DIRTY=""
if command -v git > /dev/null && git -C "$ROOT" rev-parse --git-dir > /dev/null 2>&1; then
  DIRTY="$(git -C "$ROOT" diff --name-only 2>/dev/null || true)"
  if [ -n "$DIRTY" ]; then
    bold "warning: $(printf '%s\n' "$DIRTY" | wc -l | tr -d ' ') tracked file(s) modified in the working tree"
    printf '%s\n' "$DIRTY" | sed 's/^/  /'
    echo "  a CONTENT difference below in one of these is the working tree, not the build"
    echo
  fi
fi

# ---------------------------------------------------------------------------
# The two lists the classes are asserted against, both read from the manifests
# rather than restated here.
# ---------------------------------------------------------------------------
RAW_APPS=" $(awk -F'\t' '!/^#/ && $3 == "raw" { print $1 }' "$MANIFEST" | tr '\n' ' ')"
PRIVATE_APPS=" $(awk -F'\t' '!/^#/ && NF > 1 && $4 == "private" { print $1 }' "$APPS_TSV" | tr '\n' ' ')"

# The app directory a path belongs to, or empty for anything outside apps/.
app_of() { case "$1" in apps/*/*) local r="${1#apps/}"; printf '%s\n' "${r%%/*}" ;; esac; }

in_list() { case "$2" in *" $1 "*) return 0 ;; *) return 1 ;; esac; }

# ---------------------------------------------------------------------------
# The apps whose reference vendor/ was installed with development dependencies,
# read from the reference itself: composer records "dev": true and the package
# names in vendor/composer/installed.json. Whitespace is stripped first so the
# array is one contiguous string whether composer pretty-printed it or not; the
# `/` filter keeps package names and drops anything else quoted nearby.
# ---------------------------------------------------------------------------
DEV_APPS=" "
declare -A DEV_PKGS=()
for j in "$REF"/apps/*/vendor/composer/installed.json; do
  [ -f "$j" ] || continue
  flat="$(tr -d ' \n\t' < "$j")"
  case "$flat" in *'"dev":true'*) ;; *) continue ;; esac
  a="${j#"$REF"/apps/}"; a="${a%%/*}"
  names="$(printf '%s' "$flat" | grep -o '"dev-package-names":\[[^]]*\]' \
           | grep -o '"[^"]*/[^"]*"' | tr -d '"' | tr '\n' ' ')"
  [ -n "$names" ] || continue
  DEV_APPS="$DEV_APPS$a "
  DEV_PKGS[$a]=" $names"
done

# ---------------------------------------------------------------------------
# The apps whose reference vendor/ was NOT generated from the composer.lock at
# this tag. Composer derives the autoloader class suffix from the lock's
# content-hash (config.autoloader-suffix would override it; no app here sets
# one), so a suffix that differs from the checked-in lock's content-hash proves
# the shipped tree was generated from a different composer.json/lock. 8 of the 9
# apps that ship a composer autoloader match their lock exactly; migrate_to_ocis
# does not, and it is also the only one whose packages say
# "installation-source": "source" and whose platform_check.php still uses the
# pre-2.8 trigger_error form -- an older composer, a different lock, a vendor
# tree that predates the release it shipped in.
# ---------------------------------------------------------------------------
STALE_APPS=" "
for r in "$REF"/apps/*/vendor/composer/autoload_real.php; do
  [ -f "$r" ] || continue
  a="${r#"$REF"/apps/}"; a="${a%%/*}"
  [ -f "$ROOT/apps/$a/composer.lock" ] || continue
  h="$(grep -o '"content-hash": *"[0-9a-f]\{32\}"' "$ROOT/apps/$a/composer.lock" \
       | grep -o '[0-9a-f]\{32\}' | head -1)"
  s="$(grep -o 'ComposerAutoloaderInit[0-9a-f]\{32\}' "$r" | head -1)"
  [ -n "$h" ] && [ -n "$s" ] || continue
  [ "$h" = "${s#ComposerAutoloaderInit}" ] || STALE_APPS="$STALE_APPS$a "
done

# The packages the reference installed from source rather than from a dist zip,
# per its own installed.json. Those carry a full .git/ plus the upstream repo's
# own dev files, which is where the bulk of the difference lives.
declare -A SRC_PKGS=()
for j in "$REF"/apps/*/vendor/composer/installed.json; do
  [ -f "$j" ] || continue
  a="${j#"$REF"/apps/}"; a="${a%%/*}"
  names="$(awk '
    match($0, /"name": *"[a-z0-9._-]+\/[a-z0-9._-]+"/) {
      t = substr($0, RSTART, RLENGTH); gsub(/^"name": *"|"$/, "", t); n = t; next
    }
    /"installation-source": *"source"/ { if (n != "") { print n; n = "" } }
  ' "$j" | LC_ALL=C sort -u | tr '\n' ' ')"
  [ -n "$names" ] && SRC_PKGS[$a]=" $names"
done

# The installed packages of a vendor tree as "<name> <version>" pairs, read from
# composer's own record. Versions are included on purpose: they are what the
# subset assertions below are really about.
installed_nv() {
  [ -f "$1" ] || return 0
  awk '
    match($0, /"name": *"[a-z0-9._-]+\/[a-z0-9._-]+"/) {
      t = substr($0, RSTART, RLENGTH); gsub(/^"name": *"|"$/, "", t); n = t; next
    }
    n != "" && match($0, /"version": *"[^"]+"/) {
      t = substr($0, RSTART, RLENGTH); gsub(/^"version": *"|"$/, "", t)
      print n, t; n = ""
    }
  ' "$1" | LC_ALL=C sort
}

# The <vendor>/<package> a path inside apps/<app>/vendor/ belongs to, if any.
pkg_of_path() {
  local rest="${2#apps/"$1"/vendor/}"
  [ "$rest" = "$2" ] && return 1
  local sub="${rest#*/}"
  [ "$sub" = "$rest" ] && return 1
  printf '%s/%s\n' "${rest%%/*}" "${sub%%/*}"
}

# Is this one of the files composer itself generates, rather than a package file?
is_composer_generated() {
  case "$1" in
    apps/*/vendor/autoload.php | apps/*/vendor/composer/*) return 0 ;;
    *) return 1 ;;
  esac
}

# Does this path sit in a dependency tree, under something assemble-apps.sh
# sweeps? The three prefixes are the trees it sweeps; the components are the
# names it sweeps, matched case-insensitively for the same reason it is there.
in_dep_tree_sweep() {
  local p="$1" tail
  case "$p" in
    apps/*/js/vendor/*)    tail="${p#apps/*/js/vendor/}" ;;
    apps/*/lib/composer/*) tail="${p#apps/*/lib/composer/}" ;;
    apps/*/vendor/*)       tail="${p#apps/*/vendor/}" ;;
    *) return 1 ;;
  esac
  local rc=1
  shopt -s nocasematch
  if [[ "/$tail" =~ /(tests?|examples|demos?|doc|travis)/ ]] ||
     [[ "$tail" =~ \.(sh|exe)$ ]]; then rc=0; fi
  shopt -u nocasematch
  return $rc
}

# ---------------------------------------------------------------------------
# Compare the two trees as sets of paths, then as contents.
# ---------------------------------------------------------------------------
( cd "$REF" && find . \( -type f -o -type l \) -print | sed 's|^\./||' | LC_ALL=C sort ) > "$WORK/ref.lst"
( cd "$OUT" && find . \( -type f -o -type l \) -print | sed 's|^\./||' | LC_ALL=C sort ) > "$WORK/out.lst"

LC_ALL=C comm -23 "$WORK/ref.lst" "$WORK/out.lst" > "$WORK/only-ref.lst"
LC_ALL=C comm -13 "$WORK/ref.lst" "$WORK/out.lst" > "$WORK/only-out.lst"
LC_ALL=C comm -12 "$WORK/ref.lst" "$WORK/out.lst" > "$WORK/both.lst"

bold "parity: $VARIANT"
printf '  reference %s (%s files)\n' "$REF" "$(wc -l < "$WORK/ref.lst" | tr -d ' ')"
printf '  built     %s (%s files)\n' "$OUT" "$(wc -l < "$WORK/out.lst" | tr -d ' ')"
printf '  shared    %s files\n\n' "$(wc -l < "$WORK/both.lst" | tr -d ' ')"

while read -r p; do
  cmp -s "$REF/$p" "$OUT/$p" || printf '%s\n' "$p"
done < "$WORK/both.lst" > "$WORK/differ.lst"

# Modes, for the shared files only. GNU and BSD stat disagree on the flag, and
# both are plausible here (the build runs in a Linux container, the check may not).
if stat -c '%a' . >/dev/null 2>&1; then stat_fmt=(-c '%a %n'); else stat_fmt=(-f '%Lp %N'); fi
list_modes() { ( cd "$1" && find . -type f -exec stat "${stat_fmt[@]}" {} + ) | sed 's|^\([0-7]*\) \./|\1 |'; }
list_modes "$REF" | LC_ALL=C sort > "$WORK/ref.modes"
list_modes "$OUT" | LC_ALL=C sort > "$WORK/out.modes"

# Split on the first space only: paths in core/vendor can contain spaces.
awk 'NR==FNR { m[substr($0, index($0, " ") + 1)] = $1; next }
     { p = substr($0, index($0, " ") + 1)
       if (p in m && m[p] != $1) print m[p], $1, p }' \
    "$WORK/ref.modes" "$WORK/out.modes" > "$WORK/modes-differ.lst"

# ---------------------------------------------------------------------------
# Classify. Every path lands in exactly one bucket; unexplained.lst is the verdict.
# ---------------------------------------------------------------------------
: > "$WORK/unexplained.lst"
declare -A n=()
note() { n[$1]=$(( ${n[$1]:-0} + 1 )); }
unexplained() { printf '%-12s %s\n' "$1" "$2" >> "$WORK/unexplained.lst"; }

# Files we ship that the reference does not have. There is no benign reason for
# one, so this class has no allowlist at all.
while read -r p; do
  [ -n "$p" ] || continue
  note MONOREPO_ONLY
  unexplained MONOREPO-ONLY "$p"
done < "$WORK/only-out.lst"

while read -r p; do
  [ -n "$p" ] || continue
  base="${p##*/}"
  app="$(app_of "$p")"
  # Reset per path: an elif that never runs must not see the previous path's package.
  pkg=""
  [ -n "$app" ] && pkg="$(pkg_of_path "$app" "$p" || true)"

  if [ "$base" = "signature.json" ]; then
    note SIGNED
  elif [ -n "$app" ] && in_list "$app" "$PRIVATE_APPS"; then
    if [ "$VARIANT" = complete ]; then
      note PRIVATE
    else
      # The standard variant has no private apps in it, so a missing file
      # belonging to one is not explained by "we do not have that app".
      unexplained PRIVATE-IN-STD "$p"
    fi
  elif [ -n "$app" ] && in_list "$app" "$RAW_APPS"; then
    note RAW
  elif [ -n "$pkg" ] && in_list "$pkg" "${DEV_PKGS[$app]:-}"; then
    # Checked before SWEEP: a whole absent package is better explained by the
    # package being a dev dependency than by its tests/ directory being swept.
    note DEV_DEPS
  elif [ -n "$pkg" ] && in_list "$app" "$STALE_APPS" &&
       in_list "$pkg" "${SRC_PKGS[$app]:-}"; then
    # A source install's own repo files: .git/ with packfiles, .github/,
    # .editorconfig, phpunit.xml. A dist install has none of them.
    note STALE_VENDOR
  elif is_composer_generated "$p" && in_list "$app" "$DEV_APPS"; then
    # e.g. autoload_files.php, which exists only because a dev package declared
    # an autoload.files entry.
    note DEV_DEPS
  elif is_composer_generated "$p" && in_list "$app" "$STALE_APPS"; then
    note STALE_VENDOR
  elif in_dep_tree_sweep "$p"; then
    note SWEEP
  elif [ "$p" = "apps/customgroups/lib/composer" ] && [ ! -s "$REF/$p" ]; then
    note STRAY
  else
    case "$p" in
      *"/l10n/.tx/"* | */.gitkeep | */.gitignore | */no-php) note STRIP ;;
      *) note REF_ONLY_OTHER; unexplained REF-ONLY "$p" ;;
    esac
  fi
done < "$WORK/only-ref.lst"

# For differing files the allowed delta is line-level, so each class states
# exactly which lines it tolerates and anything else fails.
only_changed_lines_match() {
  ! diff "$REF/$1" "$OUT/$1" | grep -E '^[<>]' | grep -qvE "$2"
}

# The git sha composer records for the root package, and nothing else on the line.
root_ref_re="^[<>] +'reference' => '[0-9a-f]{40}',\$"

while read -r p; do
  [ -n "$p" ] || continue
  app="$(app_of "$p")"
  case "$p" in
    version.php)
      # The pattern is a regex for diff output, not a string to expand.
      # shellcheck disable=SC2016
      if only_changed_lines_match "$p" '^[<>] \$OC_Build = '; then
        note BUILD_STAMP
      else
        note DIFF_OTHER; unexplained VERSION-PHP "$p (differs beyond \$OC_Build)"
      fi
      ;;
    # Composer's own generated files in an app whose reference shipped dev
    # dependencies: their contents enumerate the installed packages, so they
    # cannot match while the package sets differ. What makes accepting them safe
    # is the set assertion below, not this branch.
    apps/*/vendor/autoload.php | apps/*/vendor/composer/*)
      if [ -n "$app" ] && in_list "$app" "$DEV_APPS"; then
        note DEV_DEPS
      elif [ -n "$app" ] && in_list "$app" "$STALE_APPS"; then
        note STALE_VENDOR
      elif [ "${p##*/}" = "installed.php" ] && only_changed_lines_match "$p" "$root_ref_re"; then
        note ROOT_VERSION
      else
        note DIFF_OTHER; unexplained CONTENT "$p"
      fi
      ;;
    */composer/installed.php)
      if only_changed_lines_match "$p" "$root_ref_re"; then
        note ROOT_VERSION
      else
        note DIFF_OTHER; unexplained INSTALLED-PHP "$p (differs beyond 'reference')"
      fi
      ;;
    *)
      note DIFF_OTHER; unexplained CONTENT "$p"
      ;;
  esac
done < "$WORK/differ.lst"

# ---------------------------------------------------------------------------
# What makes DEV_DEPS and STALE_VENDOR safe: both classes wave through composer's
# generated files, so the package set those files describe has to be checked
# directly. For every affected app, the packages we install must be EXACTLY the
# ones the reference installed, at the same versions, minus the dev packages the
# reference itself declares. A production package we failed to install, or a
# version that drifted, would otherwise hide inside those classes.
# ---------------------------------------------------------------------------
for app in $DEV_APPS $STALE_APPS; do
  [ -d "$OUT/apps/$app" ] || continue
  installed_nv "$REF/apps/$app/vendor/composer/installed.json" > "$WORK/ref.pkgs"
  installed_nv "$OUT/apps/$app/vendor/composer/installed.json" > "$WORK/out.pkgs"
  # Match on the name only: the dev list carries no versions.
  # shellcheck disable=SC2086
  printf '%s\n' ${DEV_PKGS[$app]:-} | LC_ALL=C sort > "$WORK/dev.pkgs"
  awk 'NR==FNR { dev[$1]; next } !($1 in dev)' "$WORK/dev.pkgs" "$WORK/ref.pkgs" \
    | LC_ALL=C sort > "$WORK/expect.pkgs"
  while read -r name version; do
    [ -n "$name" ] || continue
    unexplained VENDOR-SET "apps/$app: $name $version is in the reference but not in our vendor"
  done < <(LC_ALL=C comm -23 "$WORK/expect.pkgs" "$WORK/out.pkgs")
  while read -r name version; do
    [ -n "$name" ] || continue
    unexplained VENDOR-SET "apps/$app: we install $name $version, which the reference does not have"
  done < <(LC_ALL=C comm -13 "$WORK/expect.pkgs" "$WORK/out.pkgs")
done

while read -r refmode outmode p; do
  [ -n "$p" ] || continue
  # apps/<app>/bin/<file> and nothing deeper: vendor/bin/* scripts stay 644,
  # exactly as ocrelease's -mindepth/-maxdepth 3 scoping intends.
  if [ "$refmode $outmode" = "644 755" ] && [[ "$p" =~ ^apps/[^/]+/bin/[^/]+$ ]]; then
    note EXEC_BIT
  else
    note MODE_OTHER
    unexplained MODE "$p ($refmode -> $outmode)"
  fi
done < "$WORK/modes-differ.lst"

# ---------------------------------------------------------------------------
# SIGNED is the one class asserted in both directions: the reference has a
# signature for something, so we must ship that something, and we must never
# invent a signature of our own.
# ---------------------------------------------------------------------------
while read -r p; do
  case "$p" in */signature.json | signature.json)
    unexplained SIGNATURE "$p (built tree has a signature the reference lacks)" ;;
  esac
done < "$WORK/only-out.lst"

while read -r p; do
  case "$p" in
    core/signature.json) continue ;;
    apps/*/appinfo/signature.json)
      app="$(app_of "$p")"
      if [ ! -d "$OUT/apps/$app" ] && ! in_list "$app" "$PRIVATE_APPS"; then
        unexplained SIGNATURE "$p (reference signs apps/$app, which we do not ship)"
      fi
      ;;
  esac
done < "$WORK/only-ref.lst"

# ---------------------------------------------------------------------------
# PRIVATE is asserted as a set, not a count: exactly the named apps, no more and
# no fewer.
# ---------------------------------------------------------------------------
if [ "$VARIANT" = complete ]; then
  for app in $PRIVATE_APPS; do
    [ -d "$OUT/apps/$app" ] && unexplained PRIVATE "apps/$app is present but declared out of scope"
    [ -d "$REF/apps/$app" ] || unexplained PRIVATE "apps/$app declared private but absent from the reference too"
  done
  while read -r p; do
    app="$(app_of "$p")"
    [ -n "$app" ] || continue
    [ -d "$OUT/apps/$app" ] && continue
    in_list "$app" "$PRIVATE_APPS" && continue
    # Deliberately not exempting RAW apps: that class explains individual
    # reference-only *files* inside an app we ship, never a whole missing app.
    unexplained MISSING-APP "apps/$app is missing and is not on the private list"
  done < "$WORK/only-ref.lst"
fi

# ---------------------------------------------------------------------------
# Report.
# ---------------------------------------------------------------------------
bold "difference classes"
for c in BUILD_STAMP ROOT_VERSION SIGNED STRIP SWEEP DEV_DEPS STALE_VENDOR STRAY \
         RAW PRIVATE EXEC_BIT; do
  printf '  %-14s %s\n' "$c" "${n[$c]:-0}"
done
unclassified=$(( ${n[REF_ONLY_OTHER]:-0} + ${n[DIFF_OTHER]:-0} + ${n[MONOREPO_ONLY]:-0} + ${n[MODE_OTHER]:-0} ))
if [ "$unclassified" -gt 0 ]; then printf '  %-14s %s\n' "unclassified" "$unclassified"; fi
echo

if [ -s "$WORK/unexplained.lst" ]; then
  # Sort into a file rather than piping into head: head closing the pipe early
  # makes sort die of SIGPIPE, and under pipefail that would end the script with
  # 141 before it could report anything.
  sort -u "$WORK/unexplained.lst" -o "$WORK/report.lst"
  bold "FAIL: $(wc -l < "$WORK/report.lst" | tr -d ' ') unexplained difference(s)"
  head -40 "$WORK/report.lst"
  if [ -n "${PARITY_REPORT:-}" ]; then
    cp "$WORK/report.lst" "$PARITY_REPORT"
    printf '\nfull report: %s\n' "$PARITY_REPORT"
  else
    printf '\n(truncated; set PARITY_REPORT=<path> for the full list)\n'
  fi
  exit 1
fi

bold "PASS: every difference falls into an asserted class"
