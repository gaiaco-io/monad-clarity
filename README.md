# Monad Clarity

Core library of the Monad Framework — an MVC-based PHP framework for solo developers and small
teams. Clarity provides the middlewares, services, and console tooling that
[`gaia/monad-skeleton`](https://github.com/gaiaco-io/monad-skeleton) applications run on.

**Status:** `1.0.0`, the initial 26.07 release.

## Requirements

- PHP `>=8.2`

## Installation

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
