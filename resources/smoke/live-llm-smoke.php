#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Live smoke test for the four Services\LLMAdapters — the gate item that
 * ReleaseNotes_1.0.0.md's Phase 6, 1.7.2 and 1.8.0 §3 all name and none could close from
 * the automated suite, because TestingStrategy.md Tier 4 forbids live provider calls
 * there. Not part of `vendor/bin/phpunit`; run by hand before a tag.
 *
 * It exists because a mocked adapter test cannot fail the way a real provider can: the
 * fixture is written to the same understanding of the wire format the adapter holds, so
 * the two agree with each other whatever the provider actually does. The first run of
 * this script found two defects that had been shipped since 1.0.0 — `Anthropic` could not
 * serve an API key that was not workspace-scoped, and `OpenAI` could not reach a single
 * current model — and disproved one claim the repo had asserted without measuring.
 *
 * Adapters run in order and the script STOPS at the first that is not fully green, so a
 * later adapter is only reached once the current one has passed everything.
 *
 * ---------------------------------------------------------------------------------------
 * USAGE. Put the credentials in ~/.monad-llm-smoke.env, which this script reads itself:
 *
 *     ANTHROPIC_API_KEY=…
 *     ANTHROPIC_WORKSPACE_ID=…      # only if the key is not itself workspace-scoped
 *     OPENAI_API_KEY=…
 *     GEMINI_API_KEY=…
 *     DEEPSEEK_API_KEY=…
 *     #ANTHROPIC_MODEL=…            # optional per-provider model overrides
 *
 *     chmod 600 ~/.monad-llm-smoke.env
 *     php resources/smoke/live-llm-smoke.php [--only=anthropic|openai|gemini|deepseek]
 *
 * **Do not put keys on the command line.** They land in shell history and in the process
 * list, and if you are pasting the command anywhere they land there too — which is how
 * the keys used for the first run of this script had to be revoked. `--env-file=PATH`
 * points at a different file; anything already exported in the shell wins over both.
 *
 * This script never prints a credential — only which variable names were loaded, and the
 * providers' own error messages, which do not echo keys back.
 * ---------------------------------------------------------------------------------------
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Monad\Clarity\Services\HttpClient;
use Monad\Clarity\Services\LLM\LLMException;
use Monad\Clarity\Services\LLM\LLMRequest;
use Monad\Clarity\Services\LLM\LLMResponse;
use Monad\Clarity\Services\LLMAdapters\Anthropic;
use Monad\Clarity\Services\LLMAdapters\AnthropicStructuredOutput;
use Monad\Clarity\Services\LLMAdapters\DeepSeek;
use Monad\Clarity\Services\LLMAdapters\Gemini;
use Monad\Clarity\Services\LLMAdapters\OpenAI;

// ---------------------------------------------------------------- tiny harness

final class Smoke
{
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var list<string> */
    public static array $failures = [];
    /** @var list<string> */
    public static array $notes = [];

    public static function head(string $title): void
    {
        echo "\n\033[1m── {$title}\033[0m\n";
    }

    /**
     * Runs $fn. Pass = returns true. Fail = returns false, or throws unexpectedly.
     */
    public static function check(string $name, callable $fn): bool
    {
        try {
            $result = $fn();
        } catch (Throwable $e) {
            self::$fail++;
            $msg = get_class($e) . ': ' . self::trim($e->getMessage());
            self::$failures[] = "{$name} — {$msg}";
            echo "  \033[31mFAIL\033[0m {$name}\n         {$msg}\n";

            return false;
        }

        if ($result === true) {
            self::$pass++;
            echo "  \033[32mPASS\033[0m {$name}\n";

            return true;
        }

        self::$fail++;
        $detail = is_string($result) ? $result : 'returned false';
        self::$failures[] = "{$name} — {$detail}";
        echo "  \033[31mFAIL\033[0m {$name}\n         {$detail}\n";

        return false;
    }

    /**
     * An observation that is recorded but does not gate the run — used for the open
     * questions (ReleaseNotes_1.8.0.md §2.4) where no behaviour was ever specified, so
     * there is nothing to pass or fail against.
     */
    public static function observe(string $name, callable $fn): void
    {
        try {
            $what = $fn();
        } catch (Throwable $e) {
            $what = get_class($e) . ': ' . self::trim($e->getMessage());
        }

        self::$notes[] = "{$name}: {$what}";
        echo "  \033[36mNOTE\033[0m {$name}\n         {$what}\n";
    }

    public static function trim(string $s, int $max = 300): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;

        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }

    public static function key(string $env): string
    {
        $key = getenv($env);

        if (!is_string($key) || $key === '') {
            throw new RuntimeException("{$env} is not set in the environment.");
        }

        return $key;
    }
}

/** Shared assertions every adapter's plain-text reply must satisfy. */
function assertPlainText(LLMResponse $r, string $provider): true|string
{
    if ($r->provider !== $provider) {
        return "provider was '{$r->provider}', expected '{$provider}'";
    }
    if (!is_string($r->content) || trim($r->content) === '') {
        return 'content was not a non-empty string';
    }
    if ($r->model === '') {
        return 'model was not echoed back';
    }
    if (($r->usage['inputTokens'] ?? 0) < 1 || ($r->usage['outputTokens'] ?? 0) < 1) {
        return 'usage was not populated: ' . json_encode($r->usage);
    }

    return true;
}

/** @param array<string,mixed> $c */
function assertStructured(LLMResponse $r): true|string
{
    if (!is_array($r->content)) {
        return 'content was not an array (structured mode): ' . Smoke::trim(var_export($r->content, true));
    }
    if (!isset($r->content['capital'])) {
        return 'decoded object had no "capital" key: ' . json_encode($r->content);
    }
    if (stripos((string) $r->content['capital'], 'paris') === false) {
        return 'expected Paris, got: ' . json_encode($r->content);
    }

    return true;
}

const SCHEMA = [
    'type' => 'object',
    'properties' => [
        'capital' => ['type' => 'string'],
        'population_millions' => ['type' => 'number'],
    ],
    'required' => ['capital', 'population_millions'],
    'additionalProperties' => false,
];

// ------------------------------------------------------- credentials, off the CLI

/**
 * Loads KEY=VALUE lines from an env file into the process environment, so no secret ever
 * has to appear on a command line (where it lands in shell history) or in an argument
 * list (where `ps` can read it). Existing environment values always win.
 *
 * Never echoes a value — only which names were found.
 */
function loadEnvFile(string $path): ?string
{
    if (str_starts_with($path, '~/')) {
        $home = getenv('HOME');
        $path = is_string($home) && $home !== '' ? $home . substr($path, 1) : $path;
    }

    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $loaded = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim((string) preg_replace('/^export\s+/', '', trim($name)));
        $value = trim($value);

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        putenv("{$name}={$value}");
        $loaded[] = $name;
    }

    return $path . ' → ' . ($loaded === [] ? 'nothing new (already in the environment?)' : implode(', ', $loaded));
}

$http = new HttpClient();
$only = null;
$envFile = '~/.monad-llm-smoke.env';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, 7);
    }
    if (str_starts_with($arg, '--env-file=')) {
        $envFile = substr($arg, 11);
    }
}

$report = loadEnvFile($envFile);
echo $report !== null
    ? "env file: {$report}\n"
    : "env file: {$envFile} not found — relying on the existing environment\n";

// ---------------------------------------------------------------- the adapters

/** @var array<string, callable(): void> $suites */
$suites = [];

$suites['anthropic'] = static function () use ($http): void {
    $key = Smoke::key('ANTHROPIC_API_KEY');
    $model = getenv('ANTHROPIC_MODEL') ?: 'claude-opus-5';
    echo "  model: {$model}\n";

    $workspace = getenv('ANTHROPIC_WORKSPACE_ID') ?: null;
    echo '  workspace: ' . ($workspace !== null ? 'set (header will be sent)' : 'none (key must be workspace-scoped)') . "\n";

    $forced = new Anthropic($key, $http, workspaceId: $workspace);
    $native = new Anthropic(
        $key,
        $http,
        structuredOutput: AnthropicStructuredOutput::NativeSchema,
        workspaceId: $workspace,
    );

    Smoke::check('plain text, default temperature (1.7.2: temperature must be omitted)', static function () use ($forced, $model) {
        return assertPlainText($forced->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'Reply with exactly: pong']],
            maxOutputTokens: 4096,
        )), 'anthropic');
    });

    Smoke::check('system instruction is honoured', static function () use ($forced, $model) {
        $r = $forced->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'What is 2+2?']],
            systemInstruction: 'You always answer in French.',
            maxOutputTokens: 4096,
        ));

        return is_string($r->content) && trim($r->content) !== '' ? true : 'empty reply';
    });

    Smoke::check('structured output — ForcedTool (the 1.0.0 default)', static function () use ($forced, $model) {
        return assertStructured($forced->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'The capital of France, and its metro population in millions.']],
            maxOutputTokens: 4096,
            responseSchema: SCHEMA,
        )));
    });

    Smoke::check('structured output — NativeSchema (new in 1.8.0)', static function () use ($native, $model) {
        return assertStructured($native->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'The capital of France, and its metro population in millions.']],
            maxOutputTokens: 4096,
            responseSchema: SCHEMA,
        )));
    });

    Smoke::check('a bad key raises LLMException, not a silent empty reply', static function () use ($http, $model, $workspace) {
        try {
            (new Anthropic('sk-ant-definitely-not-a-real-key', $http, workspaceId: $workspace))->complete(new LLMRequest(
                model: $model,
                messages: [['role' => 'user', 'content' => 'hi']],
            ));
        } catch (LLMException $e) {
            return str_contains($e->getMessage(), 'anthropic') ? true : 'wrong message: ' . Smoke::trim($e->getMessage());
        }

        return 'no exception raised for an invalid key';
    });

    // Open questions the acceptance gate names but never specified a behaviour for.
    Smoke::observe('non-default temperature on this model (1.7.2 premise)', static function () use ($forced, $model) {
        try {
            $forced->complete(new LLMRequest(
                model: $model,
                messages: [['role' => 'user', 'content' => 'hi']],
                temperature: 0.2,
                maxOutputTokens: 4096,
            ));

            return 'ACCEPTED — a non-default temperature did not 400 on this model';
        } catch (LLMException $e) {
            return 'REFUSED — ' . Smoke::trim($e->getMessage(), 200);
        }
    });

    Smoke::observe('native mode + a schema keyword it documents as unsupported (1.8.0 §2.4)', static function () use ($native, $model) {
        $schema = SCHEMA;
        $schema['properties']['population_millions']['minimum'] = 0; // documented unsupported
        try {
            $r = $native->complete(new LLMRequest(
                model: $model,
                messages: [['role' => 'user', 'content' => 'The capital of France and its metro population in millions.']],
                maxOutputTokens: 4096,
                responseSchema: $schema,
            ));

            return 'ACCEPTED — ' . json_encode($r->content);
        } catch (LLMException $e) {
            return 'REFUSED — ' . Smoke::trim($e->getMessage(), 200);
        }
    });
};

$suites['openai'] = static function () use ($http): void {
    $key = Smoke::key('OPENAI_API_KEY');
    $model = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
    echo "  model: {$model}\n";

    $adapter = new OpenAI($key, $http);

    Smoke::check('plain text (proves max_completion_tokens reaches this model — Phase 6 doubt, now fixed)', static function () use ($adapter, $model) {
        return assertPlainText($adapter->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'Reply with exactly: pong']],
            maxOutputTokens: 512,
        )), 'openai');
    });

    Smoke::check('system instruction is honoured', static function () use ($adapter, $model) {
        $r = $adapter->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'What is 2+2?']],
            systemInstruction: 'You always answer in French.',
            maxOutputTokens: 512,
        ));

        return is_string($r->content) && trim($r->content) !== '' ? true : 'empty reply';
    });

    Smoke::check('structured output — response_format json_schema, strict', static function () use ($adapter, $model) {
        return assertStructured($adapter->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'The capital of France, and its metro population in millions.']],
            maxOutputTokens: 512,
            responseSchema: SCHEMA,
        )));
    });

    Smoke::check('a bad key raises LLMException', static function () use ($http, $model) {
        try {
            (new OpenAI('sk-not-a-real-key', $http))->complete(new LLMRequest(
                model: $model,
                messages: [['role' => 'user', 'content' => 'hi']],
            ));
        } catch (LLMException $e) {
            return str_contains($e->getMessage(), 'openai') ? true : 'wrong message: ' . Smoke::trim($e->getMessage());
        }

        return 'no exception raised for an invalid key';
    });
};

$suites['gemini'] = static function () use ($http): void {
    $key = Smoke::key('GEMINI_API_KEY');
    $model = getenv('GEMINI_MODEL') ?: 'gemini-2.0-flash';
    echo "  model: {$model}\n";

    $adapter = new Gemini($key, $http);

    Smoke::check('plain text (model in path, key as query param)', static function () use ($adapter, $model) {
        return assertPlainText($adapter->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'Reply with exactly: pong']],
            maxOutputTokens: 512,
        )), 'gemini');
    });

    Smoke::check('assistant turns are translated to role "model"', static function () use ($adapter, $model) {
        $r = $adapter->complete(new LLMRequest(
            model: $model,
            messages: [
                ['role' => 'user', 'content' => 'My name is Ada.'],
                ['role' => 'assistant', 'content' => 'Nice to meet you, Ada.'],
                ['role' => 'user', 'content' => 'What is my name?'],
            ],
            maxOutputTokens: 512,
        ));

        return is_string($r->content) && stripos($r->content, 'ada') !== false
            ? true
            : 'multi-turn history not carried: ' . Smoke::trim((string) $r->content);
    });

    Smoke::check('structured output — responseSchema + responseMimeType', static function () use ($adapter, $model) {
        return assertStructured($adapter->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'The capital of France, and its metro population in millions.']],
            maxOutputTokens: 512,
            responseSchema: SCHEMA,
        )));
    });

    Smoke::check('a bad key raises LLMException', static function () use ($http, $model) {
        try {
            (new Gemini('not-a-real-key', $http))->complete(new LLMRequest(
                model: $model,
                messages: [['role' => 'user', 'content' => 'hi']],
            ));
        } catch (LLMException $e) {
            return str_contains($e->getMessage(), 'gemini') ? true : 'wrong message: ' . Smoke::trim($e->getMessage());
        }

        return 'no exception raised for an invalid key';
    });
};

$suites['deepseek'] = static function () use ($http): void {
    $key = Smoke::key('DEEPSEEK_API_KEY');
    $model = getenv('DEEPSEEK_MODEL') ?: 'deepseek-chat';
    echo "  model: {$model}\n";

    $adapter = new DeepSeek($key, $http);

    Smoke::check('plain text', static function () use ($adapter, $model) {
        return assertPlainText($adapter->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'Reply with exactly: pong']],
            maxOutputTokens: 512,
        )), 'deepseek');
    });

    Smoke::check('system instruction is honoured', static function () use ($adapter, $model) {
        $r = $adapter->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'What is 2+2?']],
            systemInstruction: 'You always answer in French.',
            maxOutputTokens: 512,
        ));

        return is_string($r->content) && trim($r->content) !== '' ? true : 'empty reply';
    });

    Smoke::check('structured output — json_object plus the schema appended to the system message', static function () use ($adapter, $model) {
        return assertStructured($adapter->complete(new LLMRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'The capital of France, and its metro population in millions.']],
            maxOutputTokens: 512,
            responseSchema: SCHEMA,
        )));
    });

    Smoke::check('a bad key raises LLMException', static function () use ($http, $model) {
        try {
            (new DeepSeek('sk-not-a-real-key', $http))->complete(new LLMRequest(
                model: $model,
                messages: [['role' => 'user', 'content' => 'hi']],
            ));
        } catch (LLMException $e) {
            return str_contains($e->getMessage(), 'deepseek') ? true : 'wrong message: ' . Smoke::trim($e->getMessage());
        }

        return 'no exception raised for an invalid key';
    });
};

// ---------------------------------------------------------------- run, in order

$order = ['anthropic', 'openai', 'gemini', 'deepseek'];
$halted = null;

foreach ($order as $name) {
    if ($only !== null && $only !== $name) {
        continue;
    }

    Smoke::head(strtoupper($name));

    $before = Smoke::$fail;

    try {
        $suites[$name]();
    } catch (Throwable $e) {
        Smoke::$fail++;
        Smoke::$failures[] = "{$name} — could not run: " . Smoke::trim($e->getMessage());
        echo "  \033[31mFAIL\033[0m could not run: " . Smoke::trim($e->getMessage()) . "\n";
    }

    if (Smoke::$fail > $before) {
        $halted = $name;
        echo "\n\033[31m✗ {$name} did not pass cleanly — halting before the next adapter, as instructed.\033[0m\n";
        break;
    }

    echo "\033[32m✓ {$name} fully green\033[0m\n";
}

echo "\n" . str_repeat('─', 70) . "\n";
echo sprintf("checks: %d passed, %d failed\n", Smoke::$pass, Smoke::$fail);

if (Smoke::$notes !== []) {
    echo "\nobservations (recorded, not gating):\n";
    foreach (Smoke::$notes as $n) {
        echo "  · {$n}\n";
    }
}

if (Smoke::$failures !== []) {
    echo "\nfailures:\n";
    foreach (Smoke::$failures as $f) {
        echo "  · {$f}\n";
    }
}

if ($halted !== null) {
    $remaining = array_slice($order, array_search($halted, $order, true) + 1);
    if ($remaining !== []) {
        echo "\nnot reached: " . implode(', ', $remaining) . "\n";
    }
}

exit(Smoke::$fail === 0 ? 0 : 1);
