# ReleasePolicy.md — monad/clarity

## Versioning

Strict [semver](https://semver.org/): `MAJOR.MINOR.PATCH`.

- **Patch** — bug fixes, no API change. E.g. correcting a Csrf token-rotation edge case.
- **Minor** — additive, backward-compatible. E.g. adding a fifth LLM adapter, adding a new
  `mitosis` command, adding a new Cache driver.
- **Major** — breaking change to anything listed as a compatibility promise in
  `CrossRepoContracts.md`: the three entry-point signatures, the `Services\Console::run()`
  signature, removal/renaming of a built-in `mitosis` command, narrowing a middleware's
  extension surface, or altering a setup-owned table's DDL (`sessions`, `caches`) without a
  shipped migration path.

## Release naming

A release has exactly one name: its semver version. There is no parallel milestone,
codename, or date-based identifier.

This supersedes the CalVer milestone convention used through the first release, under which
a release was named for the year and month it was scheduled to ship. **Milestone `26.07`
shipped as `1.0.0` on 2026-07-24** — the two names denote the same release, and `26.07` is
retired. Documents written under the old convention keep their historical wording; a
provenance note at the top of each records the equivalence.

Versioned documents are named for the release they describe, at full `MAJOR.MINOR.PATCH`
precision: `ReleaseNotes_1.0.0.md`, `GapAnalysis_BuildPlan_1.0.0.md`. Only releases that
warrant a frozen specification get such a document — in practice majors and minors, since a
patch release ships fixes against an existing spec rather than a new one. Unversioned
documents (`Architecture.md`, `API_Contracts.md`, `CrossRepoContracts.md` and the rest) are
living and carry no version in their filename.

## What is NOT a compatibility promise

Anything not listed in `CrossRepoContracts.md` — internal implementation of a service, private
methods, the internal organisation of `src/Console/` command classes, private helper classes —
may change in any release, including patch releases, without a major version bump.

## Tagging order

`monad/clarity` is tagged `1.0.0` **first**. The skeleton's `composer.json` pins
`"monad/clarity": "^1.0"` and is tagged **after**, once verified against the tagged
Clarity release (not a local path repository). Same order for every subsequent coordinated
release: Clarity ships, skeleton follows.

## CHANGELOG discipline

Every change — patch, minor, or major — gets a `CHANGELOG.md` entry before merge, categorised
as Added / Changed / Fixed / Deprecated / Removed / Security (Keep a Changelog format).
No change lands on `main` without a corresponding entry.

## Deprecation policy

A breaking change is announced (deprecation notice in CHANGELOG + triggered deprecation
warning in code, where feasible) in a minor release at least one minor version before removal
in a major release. Exception: security fixes may break compatibility immediately, documented
under a `Security` CHANGELOG entry, with the trade-off explained.

## Checkout namespace reservation

`Monad\Clarity\Services\Checkout` and `Monad\Clarity\Services\CheckoutAdapters\*` are reserved
and MUST NOT appear on `main` or in any tagged release until Checkout is formally scheduled
(see `PRD.md`, `Architecture.md` §8). Any reference implementation stays on a feature branch.

## Packagist publication checklist (per tagged release)

1. `CHANGELOG.md` updated for the version being tagged.
2. `composer.json` version constraints reviewed (PHP floor, bundled deps).
3. Full PHPUnit suite green (see `TestingStrategy.md`).
4. `php mitosis health` green against a fresh `create-project` using the tagged version
   (not a path repository).
5. `.gitattributes` `export-ignore` list confirmed current: `/resources`, `/CLAUDE.md`, and
   the `.gitattributes`/`.gitignore` files themselves are excluded from the dist archive.
6. Tag pushed; Packagist auto-update webhook (or manual update) confirmed.
7. `CrossRepoContracts.md` mirror in the skeleton repo checked for drift against the canonical
   copy in this repo; sync if needed (per `CrossRepoContracts.md` §10).

## Repository authority

`monad/clarity` is canonical for: this document, `CrossRepoContracts.md`, `Architecture.md`,
and any document describing Clarity's own internals or the cross-repo boundary. Where the
skeleton repository carries a mirror of a Clarity-canonical document, the Clarity copy wins on
any discrepancy.
