# API_Contracts.md — monad/clarity

The public, method-level API surface of Clarity's services, middlewares, and console. This is
the layer application code and the skeleton write against directly. Distinct from
`CrossRepoContracts.md`, which governs repo-level and versioning boundaries (entry points,
table ownership, stability promises) rather than individual method signatures.

Anything listed here as "stable" changes only under semver-major. Internal/private
implementation details of each class are not covered and may change freely in minor releases.

## Services\Request — `Monad\Clarity\Services\Request`

```php
$request->method(): string
$request->path(): string
$request->query(string $key, mixed $default = null): mixed
$request->input(string $key, mixed $default = null): mixed
$request->json(?string $key = null, mixed $default = null): mixed   // dot-notation key
$request->header(string $name): ?string
$request->cookie(string $name): ?string
$request->file(string $name): ?UploadedFile
$request->ip(): string
$request->userAgent(): ?string
$request->all(): array
$request->toPsr7(): Psr\Http\Message\ServerRequestInterface
Request::fromPsr7(Psr\Http\Message\ServerRequestInterface $psrRequest): static
```

- `json()` behaviour (with/without Jsonify middleware) is defined in `CrossRepoContracts.md` §6.
- Input is parsed, not implicitly normalised — normalisation happens only where explicitly requested (§20.2).
- All database access performed on behalf of Request-derived data uses parameterised SQL (§20.4). Output escaping is context-aware (§20.5) — never a single blanket `htmlspecialchars` call regardless of context.

## Services\Response — `Monad\Clarity\Services\Response`

```php
Response::json(mixed $data, int $status = 200): Response
Response::htm(string $html, int $status = 200): Response      // note: htm(), not html()
Response::text(string $text, int $status = 200): Response
Response::download(string $path, ?string $name = null): Response
Response::redirect(string $to, int $status = 302): Response
Response::noContent(): Response
Response::stream(callable $callback): Response
$response->toPsr7(): Psr\Http\Message\ResponseInterface
```

- A controller returning a plain PHP array is valid; Route converts it to a JSON response predictably (§21.3) — this conversion rule is stable.

## Services\Route — `Monad\Clarity\Services\Route`

```php
Route::get(string $uri, callable|array $action): Route
Route::post(string $uri, callable|array $action): Route
Route::put/patch/delete/options(...): Route
Route::group(array $attributes, callable $callback): void
Route::name(string $name): Route
Route::middleware(string|array $middleware): Route
Route::fallback(callable|array $action): void
```

- Supports: named routes, groups, prefixes, middleware stacks, typed parameters, optional
  parameters, route constraints, fallback route, and a distinct 404 (no matching route) vs 405
  (matching route, wrong method) response (§22.2). Route model binding is an optional
  extension, not a required core behaviour.

## Services\View — `Monad\Clarity\Services\View`

```php
View::render(string $view, array $data = []): Response
View::share(string $key, mixed $value): void
View::composer(string $view, callable $callback): void
View::exists(string $view): bool
```

- Rendering pipeline is fixed and sequential (§24.3): resolve view → merge local + shared data
  → run explicitly registered composers/hooks → render content → apply layout → return
  response. No runtime magic, no implicit variable injection (§24.4) — every variable
  reaching a view arrived via an explicit `share()`, `render()` argument, or composer.

## Services\Session — `Monad\Clarity\Services\Session`

Backed by the `sessions` table (DDL in `DDL.sql`). `user_id` is nullable — Session must support
creating and reading sessions with no associated user (guest, pre-login, pre-auth CSRF).

## Services\Mediator — `Monad\Clarity\Services\Mediator`

Registers PHP error, exception, and shutdown handlers. Two renderers, selected by environment:
- **Development renderer** includes exception class, message, file/line, source excerpt,
  ordered stack frames, request ID, request summary, and the previous-exception chain.
- **Production renderer** hides internals, returns an appropriate HTTP status, records the
  full exception via the Logger middleware, and returns a request/incident ID to the caller.

## Services\Files — `Monad\Clarity\Services\Files`

Single and multiple upload, content-based MIME detection (not extension-trusting), extension
allowlist, max size, generated safe filenames, atomic move, pluggable storage adapters
(filesystem default at `/storage/userfiles`, S3 optional), deletion, public/private visibility.

## Services\Schema — `Monad\Clarity\Services\Schema`

PDO-based dialect abstraction. MySQL is the default; PostgreSQL and SQLite are built-in
supported dialects. Defaults to UUID primary keys with a configurable integer-PK option.

## Services\Migration — `Monad\Clarity\Services\Migration`

Create/drop database; create/alter/drop table; create/drop index; run raw SQL scripts; run
seed scripts; rollback; per-file migration status check; DDL import/export where exported
statements are idempotent (safe to re-run).

## Services\Cache — `Monad\Clarity\Services\Cache` (PSR-16)

Drivers: file (`/storage/cache`), database (`caches` table, DDL in `DDL.sql`), Redis. DB
driver must compare stored `cache_key` on read, never trust `key_hash` alone (see
`Architecture.md` §9).

## Services\Event — `Monad\Clarity\Services\Event`

Tiny synchronous dispatcher. Built-in event names (stable identifiers, extend freely):
`login.success`, `login.failed`, `payment.completed` *(reserved — fires only once Checkout ships)*,
`user.registered`, `file.uploaded`, `migration.completed`.

## Services\HttpClient — `Monad\Clarity\Services\HttpClient` (PSR-18)

cURL-backed abstract HTTP client. Used internally by LLM adapters and Authentication's Google
SSO; also available to application code.

## Services\LLM — `Monad\Clarity\Services\LLM`

Facade contract fields: provider, model, system instruction, messages, temperature, maximum
output tokens, timeout, structured JSON response flag, usage information, provider request ID.
Adapters: `Monad\Clarity\Services\LLMAdapters\{OpenAI,Anthropic,DeepSeek,Gemini}`, each
translating the facade contract to its provider's wire format over HttpClient. No agents, tool
orchestration, vector databases, memory, prompt pipelines, or cross-provider automatic retries.

## Middlewares\Csrf — `Monad\Clarity\Middlewares\Csrf`

Session-token storage (DB-backed via `sessions`), rotation, form token, request-header token,
origin/same-site checks, constant-time comparison (via `Utils\ConstantTime`), configurable
exclusions for webhooks/stateless routes, HMAC-hashed token for session-less forms (via
`Utils\HMAC`).

## Middlewares\Logger — `Monad\Clarity\Middlewares\Logger` (PSR-3)

Log levels, context arrays, request/correlation ID, user ID where applicable, channel,
timezone-aware timestamps, rotation, redaction (via `Utils\Redactor`), optional JSON-line
output. Writes to `/storage/logs/error/app.log`, `/storage/logs/error/db.log`, and
`/storage/logs/event/timeline.log` per source.

## Middlewares\Authentication — `Monad\Clarity\Middlewares\Authentication`

Credential and Google authenticators, remember-token service, password service (hashing via
PHP's password APIs, verification + rehash detection), login throttling, session
regeneration, remember-me token rotation, email verification hooks, password reset tokens,
account lock/disable state, authentication events (via Event), pluggable user resolver,
Google SSO.

## Middlewares\RBAC — `Monad\Clarity\Middlewares\RBAC`

user→role→permission, user→direct-permission, role→permission checks; route guards;
service-level checks.

## Middlewares\RateLimiter — `Monad\Clarity\Middlewares\RateLimiter`

Required call sites: login, password reset, public API, LLM operations, webhook abuse
protection. (Checkout creation is a reserved future call site — deferred.)

## Middlewares\CORS — `Monad\Clarity\Middlewares\CORS`

Configurable allowed origins/methods/request headers/exposed headers, credentials support,
preflight handling with configurable cache duration, route-level override, environment-specific
config, rejection of unauthorised origins, correct `Vary` response headers.

## Middlewares\Jsonify — `Monad\Clarity\Middlewares\Jsonify`

Parses any valid JSON body on any body-carrying method (POST/PUT/PATCH/DELETE), media-type
detection, raw body caching, body-size limit, `json_decode` with exceptions
(`JSON_THROW_ON_ERROR`), associative-array decoding, large integers as strings
(`JSON_BIGINT_AS_STRING`), configurable max depth, structured 400 on parse failure, 415 on
JSON-required routes without a JSON body, separate JSON data bag. Full contract with
`Services\Request::json()` is in `CrossRepoContracts.md` §6.

## Middlewares\MetaTag — `Monad\Clarity\Middlewares\MetaTag`

Generates meta title/description, canonical link, robots directives, Open Graph tags,
Twitter/X card tags, JSON-LD structured data, alternate-language links.

Unlike every other Clarity service, MetaTag has no `configure()` call — it reads two
ambient sources directly, owned by the consuming app, not Clarity:
- A global `APP` constant with `name`/`base_url` keys (`define('APP', ['name' => ...,
  'base_url' => ...])`, defined once at boot — e.g. in the skeleton's
  `config/bootstrap.php`). Used as the title/Open-Graph-URL/JSON-LD fallback when a
  controller doesn't set `title`/`og_url` explicitly via `MetaTag::set()`.
- `SEO_DEFAULT_DESCRIPTION`, `SEO_DEFAULT_IMAGE`, `SEO_TWITTER_SITE`, `SEO_LOCALE`,
  `SEO_ORG_NAME`, `SEO_ORG_LOGO` environment variables, read via `getenv()` — fallback
  defaults for the corresponding `MetaTag::set()` fields.

Both must be present before `MetaTag::render()` is called from a layout, or fields that
should have a sensible default (page title, canonical URL, JSON-LD organisation name)
silently fall back to `'Application'`/empty string instead.

## Utils — `Monad\Clarity\Utils\*`

`CryptographicToken` (secure random tokens), `Encryption` (at-rest encryption),
`SignedURL`, `HMAC`, `Hash` (password hashing wrapper), `Redactor` (secret redaction for
logs), `ConstantTime` (constant-time comparison). Pure, dependency-free helpers — no service
in this list depends on application state.

## Services\Console — `Monad\Clarity\Services\Console`

`Console::run(array $argv): int` — see `CrossRepoContracts.md` §2–3 for the full stability
contract and the list of 15 stable command names.
