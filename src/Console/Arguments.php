<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

/**
 * Parsed argv tokens handed to every command's __invoke(). This is part of the frozen
 * registration API (CrossRepoContracts.md §3: "the registration API exposed to cli.php
 * is part of this contract") — app-registered commands in `app/routes/cli.php` receive
 * an Arguments instance, so its public method signatures are semver-locked even though
 * the Console\* namespace itself is free to reorganise.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Arguments
{
    /**
     * @param list<string> $positional
     * @param array<string, string|bool> $options
     * @param list<string> $raw
     */
    private function __construct(
        private readonly array $positional,
        private readonly array $options,
        private readonly array $raw,
    ) {
    }

    /**
     * @param list<string> $tokens Everything after the command name, e.g.
     *     ['UserController', '--force', '--file=seed.php'].
     */
    public static function parse(array $tokens): self
    {
        $positional = [];
        $options = [];

        foreach ($tokens as $token) {
            if (!str_starts_with($token, '--')) {
                $positional[] = $token;

                continue;
            }

            $body = substr($token, 2);

            if (str_contains($body, '=')) {
                [$key, $value] = explode('=', $body, 2);
                $options[$key] = $value;
            } else {
                $options[$body] = true;
            }
        }

        return new self($positional, $options, $tokens);
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->positional[$index] ?? $default;
    }

    /**
     * @return list<string>
     */
    public function allArguments(): array
    {
        return $this->positional;
    }

    public function option(string $name, string|bool|null $default = null): string|bool|null
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /**
     * The exact, unsplit tokens passed to parse() — for commands like `test` that pass
     * arbitrary flags through to a wrapped tool (e.g. `--filter=Foo`) rather than
     * consuming them as Console's own option syntax.
     *
     * @return list<string>
     */
    public function rawTokens(): array
    {
        return $this->raw;
    }
}
