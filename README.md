# Monad Clarity

Core library of the Monad Framework — an MVC-based PHP framework for solo developers and small
teams. Clarity provides the middlewares, services, and console tooling that
[`gaia/monad-skeleton`](https://github.com/gaiaco-io/monad-skeleton) applications run on.

**Status:** pre-release, under active development toward the 26.07 / `1.0.0` build. Not yet
published to Packagist — see `resources/docs/GapAnalysis_BuildPlan_26.07.md` for the current
build phase.

## Requirements

- PHP `>=8.2`

## Installation

Not yet published. Once tagged `1.0.0`:

```bash
composer create-project gaia/monad-skeleton NewApp
```

Clarity itself is installed as a dependency of the skeleton and updated independently:

```bash
composer update gaia/monad-clarity
```

## Testing

```bash
vendor/bin/phpunit
```

## License

MIT. See [LICENSE](LICENSE).
