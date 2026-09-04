# Versioning and release in the monorepo

What changes when 30 repositories become one, what does not, and what has to be
built to replace the parts that were doing work.

## What does not change

**`appinfo/info.xml` stays the version source of truth.** Every app keeps its own
`<version>`, hand-bumped when it is released. Nothing derives an app version from
the repository, the server version, or a tag. This is deliberate: the version in
`info.xml` is what `OC\App\AppManager` compares against `appconfig` to decide
whether an app's upgrade migrations run, so a version that moves for reasons
unrelated to the app's code would run migrations for reasons unrelated to the
app's code.

**The app directory stays the unit of identity.** `apps/<id>` is what
`occ app:enable` takes, what `assemble-apps.sh` copies, and what the G2 signature
covers — and the signing rule is that the leaf certificate's `CN` equals the app
id. None of that is affected by the app's git history living next to core's.

**Per-app `composer.json` / `composer.lock` stay.** The app's `vendor/` ships
inside the signed app directory, so the dependencies have to be resolved per app.
Unifying them would change what gets signed.

## What changes

**One tag per server release.** `v11.0.1` on the monorepo, not 43 app tags plus
one core tag. The 2,635 app tags that existed before the merge were imported and
namespaced (`user_ldap/v0.20.3`, `drawio/v1.1.1`), so `git describe` and release
archaeology still work; one tag was dropped, and only one, because
`richdocuments` carried `V2.5.0RC1` and `v2.5.0RC1` as different commits and two
refs differing only in case cannot both be loose refs on a case-insensitive
filesystem. The dropped one was a label on a commit reachable from `master`; the
commit survives.

**The release-time version assertion loses its anchor, and gains a wider one.**
`reusable-workflows/release.yml` compared `${GITHUB_REF_NAME#v}` against
`info.xml`'s `<version>` and refused to publish a mismatch. With one tag per
server release there is no per-app tag to compare against.

It is worth being precise about how much that check was doing, because it is less
than it sounds: **4 of the 29 public apps had a tag-triggered release workflow at
all** — `external`, `firstrunwizard`, `twofactor_totp`, `user_ldap`. The other 25
had `main.yml` (CI), `dist.yml` (a build on push), `lint-pr-title.yml` and
`translation-sync.yml`, and no release path in their own repository. Their 11.0.0
release assets were produced some other way, and it shows: the 8 apps whose
shipped tarballs contain a `.git/` directory with packfiles are 8 of those 25,
and none of the 4 with a release workflow shipped a raw tree.

`tools/monorepo/check-app-versions.sh` replaces it on the pull request instead of
the release, and applies to all 29:

| Check | Scope | Why |
|---|---|---|
| `<id>` and `<version>` exist and parse | all 41 app dirs | was implicit in "one repo per app" |
| `<id>` equals the directory name | all 41 app dirs | newly possible to get wrong; the directory is the identity |
| a changed version moved forward | touched external apps | a downgrade is what makes migrations not run |
| a changed version has a `CHANGELOG.md` heading | touched external apps | see below |

An unchanged version is not a failure. Apps are bumped when they are released,
not on every pull request.

The changelog half is not busywork. 11.0.0 shipped **richdocuments 4.3.0**,
tagged `v4.3.0`, from a repository whose `CHANGELOG.md` stops at 4.2.2. It is the
only app of 29 where this is true, and it passed every gate that existed, because
the tag matched `info.xml` and nothing compared either against the changelog.

**Signing becomes one job's problem instead of 43 repositories' problem.** The
requirement itself is unchanged — one leaf certificate per app, `CN == <id>`, as
`release.yml` asserts by extracting the CN from the tarball's own
`appinfo/signature.json`. But a monorepo release signs 43 app directories in one
run, so it needs all 43 certificates and keys available to that run, where before
each app repository held one pair in its own environment. That is an
infrastructure decision (43 GitHub Environments the release job iterates, or one
bundle in a single environment), not a code one, and this POC does not make it:
it builds unsigned and `parity.sh` accounts for the missing `signature.json`
files explicitly rather than ignoring them.

## How `ocrelease` would consume this

Sketch only — `owncloud/server-release` is private and out of scope here.

Today `specs/11.0.0-complete.yaml` is one `core` entry plus 43 app entries plus
`example-files`, each with `type: github-release`, `repo`, `tag` and
`targetDirectory`. `resolve/github-release.ts` downloads and unpacks each asset;
`assemble/` lays them out and fixes permissions.

With the monorepo, all 44 non-core entries collapse into the core entry, because
the tree they were being assembled into is the tree the monorepo already is:

```yaml
core:
  type: git
  ref: v11.0.1
  build: true          # make dist-server VARIANT=complete RELEASE_CHANNEL=stable
```

`GithubReleaseSource` becomes unused for apps. The variant lists move from the
spec into `build/variants/{standard,complete}.txt`, where they are versioned with
the code they select. `assemble/index.ts` steps 4–5 (permission normalisation,
`chmod 755 occ`, restoring the exec bit on `apps/*/bin/*`) move into
`assemble-apps.sh`, which is where this POC already implements them — including
the detail that a 644 bundled Go binary is silently skipped by Trivy's gobinary
analyzer, so the exec bit is a scanning concern and not only an execution one.

What is left for `server-release` is what it is actually for: signing, packaging,
publishing. This needs a change in that private repository; nothing here can
land it.

## Releasing one app between server releases

The case that separate repositories served well and the monorepo has to answer:
an app needs a fix shipped without a server release.

The mechanism survives, because each app kept its own `Makefile` and its own
`dist` target — the same one that produced its `<app>.tar.gz` release asset
before the merge. `cd apps/<id> && make dist` still builds that tarball from the
monorepo checkout. What changes is the tag it is built from: `<id>/vX.Y.Z` on the
monorepo rather than `vX.Y.Z` on the app's own repository, which is the namespace
the imported history already uses.

Two honest costs:

- The tag is on a tree that contains everything, so `<id>/v2.8.2` pins the whole
  monorepo at that moment, not just the app. For reproducing that app's tarball
  this makes no difference; for reading the tag as "what shipped", it means the
  tag says more than it used to.
- 8 apps' `dist` targets tar their working tree rather than an allowlist, which
  is how `.git/` got into 11.0.0. In the monorepo that working tree is much
  bigger. Anyone releasing one of those 8 apps this way should use the manifest
  path (`assemble-apps.sh`) rather than the app's own `dist` target; the manifest
  is in `tools/monorepo/release-files-11.0.0.tsv` and its `upstream_tree` column
  names those 8 apps.

## The trap this POC actually hit

The checkout is now both the dev loop and a release input, and three things
change what ships without being asked:

- `occ maintenance:install` appends its `ErrorDocument` lines to the checkout's
  own `.htaccess`. Installing a server to run an app's unit tests therefore
  changes a file `make dist` copies into the release. This cost one full parity
  run: the build was correct and the working tree was not.
- `user_ldap`'s node target runs `npm install`, not `npm ci`, which rewrites its
  committed `package-lock.json` from `lockfileVersion` 1 to 3. It is the only app
  of 29 that rewrites a tracked file during its build.
- Running an app's unit tests installs its **dev** dependencies into
  `apps/<id>/vendor/` — the apps' `vendor/bin/phpunit` target is a plain
  `composer install`, with no `--no-dev`. `vendor` is a *directory* target, so the
  release build's `make vendor` then sees a directory that exists, does nothing,
  and ships it. The first complete-variant parity run failed on exactly this: 38
  files of `bamarni/composer-bin-plugin` plus a dev autoloader in
  `apps/openidconnect` and `apps/migrate_to_ocis`. It is the same defect the
  reference already has in `twofactor_totp`, reached by the same route.

None is caused by the merge, and none was visible before it: release builds ran in
a fresh clone and threw the dirty tree away. Two of the three are now structural
rather than remembered — `assemble-apps.sh` removes a composer `vendor/` before
the release build so the app's own `composer install --no-dev` rebuilds it from
its committed lock, and then asserts on the shipped tree that
`vendor/composer/installed.json` records no dev packages. That assertion is the
one gate in this POC that upstream has no equivalent of anywhere; had it existed,
`twofactor_totp` 11.0.0 would not have shipped. For the other two, `parity.sh`
reports locally modified tracked files up front, so the next person loses a minute
rather than a build.
