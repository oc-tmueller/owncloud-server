# ownCloud 11 monorepo: go / no-go

A POC that merged `owncloud/core` and the 29 public oc11 apps into one repository,
built the 11.0.0 release from it, and compared the result against the published
tarballs file by file.

## Recommendation

**Go**, for the public set, with four conditions named at the end. The
load-bearing evidence is that the merged repository reproduces the released
11.0.0 tree, so this is not a question of whether it can work.

The argument that changed my mind while doing it is not the one the plan started
with. The plan's case was "44 repositories are expensive to maintain". That is
true, and the savings are real and countable below. But the stronger case is that
**the merge found seven latent defects in the release, none of which any existing
gate was watching**, and it found them in a week, by the mechanical act of
building the same artifact from one tree and diffing. Those defects were not
caused by having many repositories in any deep sense — they were caused by having
43 places for a rule to not be applied.

## What was proven

| Claim | Result |
|---|---|
| Full history imports, blame survives | 76,563 commits, 30 merges, 2,634 namespaced app tags + 529 core tags. `git log --follow` reaches pre-import commits. |
| One repository builds the release | `make dist-server VARIANT=standard` → 11,362 files; `VARIANT=complete` → 19,935. |
| The build matches what shipped | `parity.sh standard` **passes**: 11,362 files, every difference in an asserted class, 0 unclassified. |
| Complete variant matches, minus the private apps | `parity.sh complete` **passes**: 19,935 files, all of them present in the reference, 0 unclassified, and 11,136 reference-only files accounted for as exactly the 14 named private apps. |
| Any app's tests run from one checkout | 21 of 29 app suites pass, 2,277 tests, no core checkout and no `core-ref`. |
| CI scopes work to what changed | A diff under `apps/notes/` → `["notes"]`. A diff under `lib/private/` → all 29. |
| The result runs | `owncloud-docker/server`'s `v24.04/Dockerfile.multiarch`, unmodified, built with `TARBALL_URL` pointed at the monorepo tarball: `/status.php` reports `versionstring 11.0.0`, and the authenticated OCS API lists **21 enabled apps** — 9 of them monorepo-built external ones, with `notifications` contributing its own capability block. |
| An app can still be released alone | `cd apps/notes && make dist` produces its `notes.tar.gz` from the monorepo, and finds `occ` at `../../occ` because that path is now real. |

Sizes: `.git` is 561 MB, 14,261 tracked files, of which 9,400 are under `apps/`.

## What got cheaper, counted

- **109 workflow files and 29 dependabot configs deleted** — the Phase 2 cleanup
  commit removes 203 files. Per app that was `main.yml` (27), `lint-pr-title.yml`
  (27), `translation-sync.yml` (24), `dist.yml` (24), plus one-offs. They are
  replaced by the workflows core already had, plus one new `app-unit.yml`.
- **The `core-ref` dance is gone.** `reusable-workflows/php-unit.yml` takes
  `core-ref` *and* `core-ref-php74`, checks out `owncloud/core`, then checks out
  the app into `apps/<name>` — two checkouts and a cross-repo version guess, per
  app, on every pull request. In the monorepo both checkouts and both inputs
  disappear, because `../../lib/composer/bin/phpunit` is a real path.
- **29 copies of a workaround are gone.** Every app's `main.yml` opened with a
  `get-vars` job whose entire purpose was to turn two `env` values into job
  outputs, because `env` is not usable in a reusable workflow's `with:`.
- **34 dependency manifests fold into two dependabot entries**
  (`directories: ["/apps/*"]` for composer and npm), replacing 27 + 7 per-repo
  declarations.
- **One tag per release.** The `ocrelease` spec collapses from 1 core entry + 43
  app entries + `example-files` to a single core entry with `build: true`;
  `GithubReleaseSource` stops being used for apps.
- **Release assembly stops being a download.** 43 release assets fetched and
  unpacked over a core checkout becomes a copy inside one tree, driven by one
  reviewed manifest.
- **A cross-cutting change is one pull request.** Not measured here — no such
  change was made — but it is the mechanical consequence of the above.

## What got more expensive, counted

- **A core change costs 29 app jobs.** Measured, not estimated: a diff touching
  `lib/private/` or the root `Makefile` fans out to all 29 apps. This is the
  honest price and `affected-apps.sh` does not soften it — a change to
  `apps/files` can break anything that consumes its API, so the 12 bundled apps
  are in the fan-out bucket too.
- **The full nightly matrix is 116 jobs** (29 apps × 4 databases), which is why
  the pull-request gate runs sqlite only and the wide matrix moved to `nightly.yml`
  rather than staying on every PR as it was per-repo.
- **2,634 extra tags and a 561 MB `.git`.** A fresh clone is meaningfully slower
  than cloning core alone.
- **One dependabot pull-request budget, shared.** 28 composer manifests behind
  one `open-pull-requests-limit`; raised to 10 here, still a queue.
- **Signing needs 43 certificates in one job.** The rule is unchanged — one leaf
  per app with `CN == <id>` — but it used to live one-per-repository. This POC
  builds unsigned and accounts for the missing `signature.json` files explicitly.
- **The checkout is now both the dev loop and a release input.** This is the one
  genuinely new failure mode, and it bit three times:
  - `occ maintenance:install` appends `ErrorDocument` lines to the repo's own
    `.htaccess`, which `make dist` then copies. Cost one full parity run.
  - `user_ldap`'s node target runs `npm install` rather than `npm ci`, rewriting
    its committed `package-lock.json`.
  - Worst of the three: running an app's unit tests installs its **dev**
    dependencies into `apps/<id>/vendor/`, and because `vendor` is a *directory*
    target, `make vendor` then considers it up to date and ships it. The first
    complete-variant run failed on exactly this — 38 files of
    `bamarni/composer-bin-plugin` and a dev autoloader in `openidconnect` and
    `migrate_to_ocis`. It is the same defect the reference has in
    `twofactor_totp`, reached the same way, and in separate repos it was
    invisible because the release built in a fresh clone.

  All three are now handled rather than remembered: `parity.sh` reports a dirty
  tree up front, `assemble-apps.sh` removes a composer `vendor/` before the
  release build so the app's own `composer install --no-dev` rebuilds it, and it
  then asserts against the shipped tree that
  `vendor/composer/installed.json` records no dev packages — a gate no app
  repository had.

## The seven defects the merge found

None of these were introduced by the merge. All were shipped in 11.0.0, and each
is asserted narrowly in `parity.sh` or `app-ci.tsv` rather than waved away.

1. **8 apps ship `.git/` with packfiles in their release tarballs**
   (`announcementcenter`, `brute_force_protection`, `files_antivirus`,
   `files_external_ftp`, `files_pdfviewer`, `files_primary_s3`,
   `files_texteditor`, `notifications`) — their `dist: source appstore` targets
   tar the working tree. It correlates exactly with the next finding: **all 8 are
   among the 25 apps that had no release workflow**, and none of the 4 apps that
   did shipped a raw tree.
2. **Only 4 of 29 apps had a tag-triggered release workflow at all** —
   `external`, `firstrunwizard`, `twofactor_totp`, `user_ldap`. The
   tag ↔ `info.xml` assertion everyone assumes covers the app set covered 14% of
   it.
3. **`twofactor_totp` shipped its development dependencies.** Not inferred: the
   reference's own `vendor/composer/installed.json` says `"dev": true` and names
   them. Its `Makefile` has an empty `composer_deps`, so `make dist` installed
   nothing and the release inherited whatever CI had left in the workspace.
4. **`migrate_to_ocis` shipped a `vendor/` not built from its tagged
   `composer.lock`** — its autoloader suffix does not match the lock's
   content-hash, where 8 of the 9 other apps with an autoloader match exactly.
5. **`richdocuments` 4.3.0 shipped with no changelog entry for 4.3.0** (its
   `CHANGELOG.md` stops at 4.2.2). One app of 29, and the only gate that existed
   compared the tag to `info.xml`, not either to the changelog.
6. **6 of 29 apps had no php-unit job in their own repository**, and `drawio`'s
   `test-php-unit` target asks phpunit for a testsuite named
   `openidconnect-unit` while the app ships no `phpunit.xml` — it has never been
   able to run. The monorepo did not break these suites; it is what noticed.
7. **175 files of dependency-tree dev cruft and 5 apps' `.gitkeep` /
   `.gitignore` / `no-php` files** ship in `complete`. Core sweeps its own
   `lib/composer` and `core/vendor` for exactly these; 5 of the 10 apps with a
   `vendor/` swept theirs too, in four different spellings, and the rest did not.

Read together these say one thing: the 11.0.0 tarball is a fossil record of
several different toolchain states, because 43 repositories each decided
separately what "build" and "release" meant. The monorepo's value is not that it
has fewer files. It is that `release-files-11.0.0.tsv` and `assemble-apps.sh` are
one place where that decision is made once — and an allowlist, so a new app file
has to be added to ship rather than a new dev file having to be remembered.

## Conditions

1. **The 14 private apps need a decision before this is a release path.** They
   were out of scope here by instruction, and `complete` parity is therefore
   proven for 30 of 44 entries with the other 14 asserted absent. Either they
   join the monorepo (which makes it private, or splits it), or `ocrelease` keeps
   a `github-release` path for exactly them — which keeps `GithubReleaseSource`
   alive and keeps two assembly mechanisms in play.
2. **Signing key provisioning has to be designed**, not inherited: 43 leaf
   certificates reachable from one release run.
3. **`owncloud/server-release` needs a change** — one spec entry with
   `build: true` instead of 44 resolved sources. Nothing in this POC can land
   that; it is private.
4. **The 8 raw-tree apps need their own `dist` targets fixed or retired.** The
   monorepo route already produces clean trees, so the bug only survives for
   anyone releasing one of those apps the old way (see
   `docs/monorepo/versioning-and-release.md`).

## What this POC does not prove

- **oc10.x.** Untouched, out of scope.
- **Marketplace publishing.** Untouched.
- **That the fan-out cost is acceptable in practice.** 29 jobs on a core change
  is measured; whether that is a tolerable price on a busy day is a judgement
  call for whoever waits on the queue.
- **Signed artifacts.** Everything here is built unsigned, and parity asserts the
  `signature.json` absence in one direction only.
- **The 8 app suites that do not pass the gate.** 5 need services the PR gate
  does not start (clamav, FTP, S3, samba, Dropbox credentials), 2 have no PHP
  tests, 1 has never been runnable. `app-ci.tsv` records each with its reason and
  `app-unit.yml` reports them as skipped in the run summary rather than reporting
  green.
