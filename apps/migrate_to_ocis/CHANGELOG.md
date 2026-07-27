# Table of Contents

* [Changelog for 2.0.1](#changelog-for-201-2026-07-24)
* [Changelog for 2.0.0](#changelog-for-200-2026-07-02)

# Changelog for [2.0.1] (2026-07-24)

The following sections list the changes for 2.0.1.

[2.0.1]: https://github.com/owncloud/migrate_to_ocis/compare/v2.0.0...v2.0.1

## Summary

* Bugfix - Select the migration role by name in the acceptance test: [#42](https://github.com/owncloud/migrate_to_ocis/issues/42)
* Change - Ship a properly signed release tarball: [#48](https://github.com/owncloud/migrate_to_ocis/pull/48)

## Details

* Bugfix - Select the migration role by name in the acceptance test: [#42](https://github.com/owncloud/migrate_to_ocis/issues/42)

   We fixed a flaky acceptance test that intermittently failed during file
   migration with "409 Conflict: intermediate collection does not exist". oCIS
   returns the available roles in a non-deterministic order, and the test picked
   the role by index (0), which sometimes resolved to the "User Light" role. That
   role has no personal drive, so migrated users had no home folder and the file
   migration failed.

   The migration driver now answers the role prompt with the role label "User"
   instead of an index, which deterministically selects the standard role
   regardless of the order oCIS returns the roles in.

   https://github.com/owncloud/migrate_to_ocis/issues/42

* Change - Ship a properly signed release tarball: [#48](https://github.com/owncloud/migrate_to_ocis/pull/48)

   The 2.0.0 release tarball was not signed with the ownCloud code-signing
   certificate, so it could not be verified by ownCloud Classic's integrity check.
   The 2.0.1 release distribution is signed with the G1 code-signing certificate,
   allowing `occ integrity:check-app migrate_to_ocis` to validate the app.

   https://github.com/owncloud/migrate_to_ocis/pull/48

# Changelog for [2.0.0] (2026-07-02)

The following sections list the changes for 2.0.0.

## Summary

* Change - Relicense from GPLv2 to Apache-2.0: [#27](https://github.com/owncloud/migrate_to_ocis/pull/27)

## Details

* Change - Relicense from GPLv2 to Apache-2.0: [#27](https://github.com/owncloud/migrate_to_ocis/pull/27)

   We relicensed the app from GPLv2 to the Apache License 2.0. The LICENSE file now
   carries the full Apache 2.0 text, the `licence` tag in `appinfo/info.xml` is set
   to `APL2`, and every PHP source file carries an `SPDX-License-Identifier:
   Apache-2.0` header.

   https://github.com/owncloud/migrate_to_ocis/issues/26
   https://github.com/owncloud/migrate_to_ocis/pull/27
