# Monad Clarity

Core library of the Monad Framework — an MVC-based PHP framework for solo developers and small
teams. Clarity provides the middlewares, services, and console tooling that
[`monad/skeleton`](https://github.com/gaiaco-io/monad-skeleton) applications run on.

**Status:** `1.0.1`. The initial release, `1.0.0`, was planned under the milestone name
26.07; releases are now named by their semver version only.

## Requirements

- PHP `>=8.2`

## Installation

```bash
composer create-project monad/skeleton NewApp
```

Clarity itself is installed as a dependency of the skeleton and updated independently:

```bash
composer update monad/clarity
```

## Testing

```bash
vendor/bin/phpunit
```

## License

MIT. See [LICENSE](LICENSE).
