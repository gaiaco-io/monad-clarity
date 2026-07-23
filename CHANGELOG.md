# Changelog

All notable changes to `gaia/monad-clarity` are documented in this file. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `LICENSE` (MIT).
- `README.md` with status, PHP floor, and testing instructions.
- `.gitattributes` `export-ignore` rules for `/resources`, `/CLAUDE.md`, and the dotfiles
  themselves, per `Architecture.md` §10.
- `.gitignore` (`/vendor/`, `composer.lock`, OS/PHPUnit cache artifacts).
- `phpunit.xml.dist` pointing the test suite at `resources/tests`.
- `composer.json` `scripts.test` (phpunit) and `scripts.lint` (PHP syntax check over `src/`).
- `.github/workflows/ci.yml`: validates `composer.json`, installs dependencies, lints, and runs
  PHPUnit on PHP 8.2 and 8.3, per `TestingStrategy.md` CI requirements.
- Initialised git repository; GitHub repo `gaiaco-io/monad-clarity` (public).

### Changed
- Restructured source tree under `src/` (`src/Services/`, `src/Utils/`) per `RepoMap.md` and
  `Architecture.md` §2. Previously classes lived at repo root (`Services/`, `Middlewares/`,
  `Utils/`), which does not match the documented PSR-4 root.
- `composer.json` PSR-4 autoload corrected from `"Gaia\\Clarity\\Services\\": "Services/"` to
  `"Gaia\\Clarity\\": "src/"` per `Architecture.md` §2 and `CrossRepoContracts.md` §1. The
  previous mapping did not autoload `Middlewares\*` or `Utils\*` at all, and pointed `Services\*`
  at a nonexistent root-level directory.
- Added `require`: `php: >=8.2`, `nesbot/carbon`, `ramsey/uuid` (the latter was already an
  undeclared implicit dependency of `Services\DB` and `Services\Files`).
- Added `require-dev`: `phpunit/phpunit ^11.0`, `fakerphp/faker` per `TestingStrategy.md`.
  PHPUnit pinned to `^11.0` rather than latest (`^13`) — 13.x requires PHP `>=8.4`, which
  would break CI against the documented `>=8.2` floor and the 8.2/8.3 matrix.
- Added `autoload-dev` PSR-4 mapping `Gaia\\Clarity\\Tests\\` to `resources/tests/`.

### Removed
- `Middlewares/Keylock.php` — declared `namespace Gaia\Kerberos`, did not match the package
  namespace, and was not part of any documented middleware in `RepoMap.md`.
- `Services/$MYVIMRC` — stray editor artifact, not source code.
