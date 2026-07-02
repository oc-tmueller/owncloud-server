# AI Agent Guidelines for migrate_to_ocis

This file provides context for AI coding agents (Claude Code, GitHub Copilot, Cursor, etc.) working in this repository.

## Repository Overview
- **Product family:** ownCloud Classic — migration tooling
- **Purpose:** ownCloud app that migrates users, groups, files and shares from ownCloud Classic to ownCloud Infinite Scale (oCIS).
- **Primary language:** PHP (>= 7.4)
- **Build system:** Composer, Make
- **Test framework:** PHPUnit (unit), Docker-based end-to-end acceptance tests
- **CI system:** GitHub Actions (plus Drone)

## Architecture & Key Paths
- `appinfo/` - ownCloud app metadata (`info.xml`, `app.php`, `install.php`)
- `lib/` - PHP source code
  - `lib/Command/` - `occ` CLI command handlers (the migration steps)
  - `lib/MigrationState/` - migration state machine
  - `lib/OCIS/` - oCIS REST API client
  - `lib/Helper/` - utility classes (users, groups, storage, permissions)
  - `lib/ConflictLog/` - conflict logging
- `bin/rclone_linux_amd64` - bundled rclone binary used for file migration
- `tests/unit/` - PHPUnit unit tests
- `tests/acceptance/` - Docker-based end-to-end tests (ownCloud Classic + oCIS)
- `composer.json` - PHP dependencies and app metadata
- `phpstan.neon` - PHPStan configuration
- `.php-cs-fixer.dist.php` - php-cs-fixer (ownCloud code style) configuration
- `Makefile` - build and test automation

## Development Conventions
- **Branching:** master
- **Commit messages:** Must follow [Conventional Commits](https://www.conventionalcommits.org/) format (PR titles are checked by CI). Commits must be DCO signed-off — see the Git Workflow section below.
- **Code style:** php-cs-fixer with the ownCloud coding standard.
- **PR process:** Open a PR against master. All CI checks must pass.

## Build & Test Commands
```bash
# Build (install all dependencies)
make

# Test (PHP unit)
make test-php-unit

# Lint (PHP code style, dry-run)
make test-php-style

# Fix code style
make test-php-style-fix

# Static analysis
make test-php-phpstan
make test-php-phan

# End-to-end acceptance tests (Docker: ownCloud Classic + oCIS)
make test-acceptance
```

## Important Constraints
- This project is licensed under the **Apache License 2.0**. All code
  contributions must be compatible with Apache-2.0.
- Do not introduce dependencies under licenses **incompatible with Apache-2.0**
  (Apache "Category X": GPL, AGPL, LGPL, etc.) without explicit discussion in an
  issue first. See the [ASF third-party license policy](https://www.apache.org/legal/resolved.html).
- Do not introduce new dependencies without discussion in an issue first.
- Conventional Commits are enforced by CI.

## OSPO Policy Constraints

### GitHub Actions
- **Only** use actions owned by `owncloud`, created by GitHub (`actions/*`), verified on the GitHub Marketplace, or verified by the ownCloud Maintainers.
- Pin third-party actions to their full commit SHA (not tags): `uses: actions/checkout@<SHA> # vX.Y.Z`. ownCloud's own reusable workflows (`owncloud/reusable-workflows`) may track `@main`.
- Never introduce actions from unverified third parties.

### Dependency Management
- Dependabot is configured for automated dependency updates (`.github/dependabot.yml`).
- Review and merge Dependabot PRs as part of regular maintenance.

### Git Workflow
- **Rebase policy**: Always rebase; never create merge commits. Use `git pull --rebase` and `git rebase` before pushing.
- **Signed commits**: All commits **must** be signed, using either GPG or SSH (`git commit -S -s`).
- **DCO sign-off**: Every commit needs a `Signed-off-by` line (`git commit -s`). This is enforced by the DCO check on pull requests — PRs cannot merge until all commits are signed off. See [`CONTRIBUTING.md`](CONTRIBUTING.md).
- **Conventional Commits**: Use the [Conventional Commits](https://www.conventionalcommits.org/) format. A reusable GitHub Actions workflow enforces this on commit messages and PR titles.

## Context for AI Agents
- Match existing code style.
- Do not refactor unrelated code in the same PR.
- Write tests for new functionality.
- Keep PRs focused and atomic.
- Use Conventional Commits format for all commit messages.
