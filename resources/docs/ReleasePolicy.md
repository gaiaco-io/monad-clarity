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

## Checkout namespace reservation — released in 1.2.0

`Monad\Clarity\Services\Checkout` and `Monad\Clarity\Services\CheckoutAdapters\*` were reserved
and barred from `main` and from any tagged release until Checkout was formally scheduled.

**Checkout was scheduled and shipped in 1.2.0** (see `ReleaseNotes_1.2.0.md`,
`Architecture.md` §8). The reservation no longer applies to `Services\Checkout`,
`Services\Checkout\*`, or to any adapter that has since been built —
`CheckoutAdapters\StripeCheckout` (1.2.0), `CheckoutAdapters\PaddleCheckout` (1.3.0), and
`CheckoutAdapters\PaddleSubscription` (1.4.0).

The reservation **does** still apply to every unbuilt adapter namespace. Each ships in its own
minor release when built end to end, and none may appear on `main` as a stub in the meantime.
An unbuilt adapter is an absent file.

Currently reserved and unbuilt: `StripeConnectExpress`, `Fiuu`, `iPay88`, `BillPlz`, `Adyen`,
`Airwallex`, `HitPay`, `Xendit`. That list is the present state of the roadmap, not a closed
set — `ReleaseNotes_1.0.0.md` §9 specifies an abstraction layer for "various payment gateways"
and enumerates adapter namespaces ending in "etc.", so a gateway it does not name may still be
built. `ReleaseNotes_1.3.0.md` §2.1 records that decision and the reasoning behind it.

## Packagist publication checklist (per tagged release)

1. `CHANGELOG.md` updated for the version being tagged.
2. `composer.json` version constraints reviewed (PHP floor, bundled deps).
3. Full PHPUnit suite green (see `TestingStrategy.md`).
4. `php mitosis health` green against a fresh `create-project` using the tagged version
   (not a path repository).
5. `.gitattributes` `export-ignore` list confirmed current: `/resources`, `/CLAUDE.md`, and
   the `.gitattributes`/`.gitignore` files themselves are excluded from the dist archive.
6. Tag pushed; Packagist auto-update webhook (or manual update) confirmed.
7. **Website documentation updated for anything user-facing this release adds or changes.**
   `monad.gaiaco.io`'s docs live in the separate `monad-www` repo as hand-authored content
   files plus a *hardcoded* nav (`app/views/Partials/DocsNavData.php` carries literal entry
   lists and counts). Nothing derives them from this repo, so a new service, middleware,
   utility, or `mitosis` command reaches the site only if someone writes the page. Checkout
   shipped in 1.2.0 and was absent from the docs until it was noticed by hand — this item
   exists so that is caught here instead. A release that adds nothing user-facing ticks this
   item and moves on; one that does needs the `monad-www` change merged before the tag.
8. `CrossRepoContracts.md` and `RepoMap.md` mirrors in the skeleton repo checked for drift
   against the canonical copies in this repo; sync if needed (per `CrossRepoContracts.md`
   §10 and the equivalent procedure `RepoMap.md` states for itself). Also worth a skim for
   staleness against the actual codebase while checking — a mirror can be byte-identical
   between repos and still describe a file that moved or a path that was never really
   updated to match its own PSR-4 casing.

9. `README.md`'s **Status** line bumped to the version being tagged, in both this repo and
   the skeleton — and, in the skeleton's, the version `monad/clarity ^1.0` now resolves to.
   This item exists because the line went unbumped through 1.2.0, 1.3.0 and 1.4.0: Clarity's
   README still claimed `1.1.0` and the skeleton's claimed a Clarity that resolved to
   `1.0.1`, three releases behind. It is the first thing a visitor reads and the last thing
   anyone remembers, so it is a checklist item rather than a habit.

## Repository authority

`monad/clarity` is canonical for: this document, `CrossRepoContracts.md`, `RepoMap.md`,
`Architecture.md`, and any document describing Clarity's own internals or the cross-repo
boundary. Where the skeleton repository carries a mirror of a Clarity-canonical document, the
Clarity copy wins on any discrepancy.
