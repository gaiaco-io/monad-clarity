# Monad 26.07 Release Notes

**Status:** FROZEN — canonical specification for the 26.07 release.
This document is the source of truth for WHAT ships. Build sequence lives in
`GapAnalysis_BuildPlan_26.07.md`; cross-repo boundaries live in `CrossRepoContracts.md`.

1. `monad/skeleton` under MIT license available on Packagist
2. `monad/clarity` under MIT license available on Packagist
3. Install Monad Framework with `composer create-project monad/skeleton NewApp`
4. `composer.json` contains `monad/clarity` as dependency
5. Included Composer packages:
    1. Carbon (bundled dependency)
    2. ramsey/uuid (bundled dependency)
    3. PHPUnit (development dependency)
    4. FakerPHP (development dependency)
6. Included Node packages:
    1. TailwindCSS 4
    2. jQuery
7. Update Clarity with `composer update monad/clarity`
8. CLI tooling `mitosis`. Examples:
    1. `php mitosis make:controller UserController`
    2. `php mitosis make:model User`
    3. `php mitosis make:migration`
    4. `php mitosis make:service`
    5. `php mitosis migrate`
    6. `php mitosis migrate:status`
    7. `php mitosis migrate:rollback`
    8. `php mitosis db:seed --file=seed.php`
    9. `php mitosis db:execute database/migrations/20260711_fix_user_table.sql`
    10. `php mitosis test`
    11. `php mitosis health`: Health checks for configuration, DB connectivity, writable storage, migration status, required PHP extensions
    12. `php mitosis serve`: Runs PHP built-in server and binding to port 8000
    13. `php mitosis cache:clear`
    14. `php mitosis logs:clear`
9. DEFERRED — NOT IN 26.07 Clarity Checkout Service:
    1. Namespace: `Monad\Clarity\Services\Checkout`
    2. Abstraction layer and headless application for various payment gateways to simplify common operations between the merchant's site and the payment gateway
    3. Supports Fiuu, iPay88, BillPlz, Adyen, Airwallex, HitPay, Xendit as a wrapper to payment gateway's existing SDK / API
    4. Each wrapper shall be its own class object and namespace; i.e.
    `Monad\Clarity\Services\CheckoutAdapters\StripeCheckout`, `Monad\Clarity\Services\CheckoutAdapters\StripeConnectExpress`, `Monad\Clarity\Services\CheckoutAdapters\Fiuu`, etc.
    5. Supports custom checkout page and payment gateway hosted checkout page
    6. Requirements:
        1. Checkout / authorisation with payment gateway
        2. Transaction creation (status: pending)
        3. Transaction status re-query
        4. Capture callbacks from payment gateway
        5. Transaction update after status re-query or callback capture (status: success / failed / cancelled)
        6. Initiate refunds
        7. Built-in operations to generate reports
        8. Built-in with table structure for common implementation patterns:
            1. Transaction records
            2. Immutable transaction status records (insert only) with failure reason column
10. Clarity Schema Service:
    1. Namespace: `Monad\Clarity\Services\Schema`
    2. Database abstraction layer with default support for MySQL
    3. Available built-in support for PostgreSQL and SQLite
    4. Uses PDO as the baseline driver
    5. Defaults to using UUID for primary keys, with configurable option to use integer
11. Clarity LLM Service:
    1. Namespace: `Monad\Clarity\Services\LLM` for the service stub and facade
    2. Supports:
        1. OpenAI, namespaced as `Monad\Clarity\Services\LLMAdapters\OpenAI`
        2. Anthropic, namespaced as `Monad\Clarity\Services\LLMAdapters\Anthropic`
        3. DeepSeek, namespaced as `Monad\Clarity\Services\LLMAdapters\DeepSeek`
        4. Gemini, namespaced as `Monad\Clarity\Services\LLMAdapters\Gemini`
    3. Requirements:
        1. Provider
        2. Model
        3. System instruction
        4. Messages
        5. Temperature
        6. Maximum output tokens
        7. Timeout
        8. Structured JSON response
        9. Usage information
        10. Provider request ID
    4. Does not include: agents, tool orchestration, vector databases, memory, prompt pipelines, automatic retries across providers
12. Clarity Migration Service:
    1. Namespace `Monad\Clarity\Services\Migration`
    2. Database migration services executed from the CLI using `mitosis`. Requirements:
        1. Create/drop database
        2. Create/alter/drop table
        3. Create/drop index
        4. Run SQL scripts
        5. Run seed scripts
        6. Rollback migration
        7. Check migration status of a given migration file
        8. Import/export DB dump as DDL. Dumped DDL statements should be idempotent
13. Monad middleware CSRF Service:
    1. Namespace `Monad\Clarity\Middlewares\Csrf`
    2. Requirements:
        1. Session token storage
        2. Database-backed session token storage using Monad's `sessions` table
        3. Token rotation
        4. Form token
        5. Request header token
        6. Origin and same-site checks
        7. Constant-time comparison
        8. Configurable exclusions for webhook and stateless API routes
        9. For session-less forms, use HMAC-hashed token
14. Monad middleware Logger Service:
    1. Namespace `Monad\Clarity\Middlewares\Logger`
    2. Requirements:
        1. Log levels
        2. Context arrays
        3. Request or correlation ID
        4. User ID where applicable
        5. Channel
        6. Timestamp with timezone
        7. Log rotation
        8. Sensitive-data redaction
        9. JSON-line output option
        10. PSR-3 compatible
        11. Stores logs in:
            1. `/storage/logs/error/app.log` for app level runtime errors
            2. `/storage/logs/error/db.log` for db level runtime errors
            3. `/storage/logs/event/timeline.log` for critical business or audit events
15. Monad middleware Authentication Service:
    1. Namespace `Monad\Clarity\Middlewares\Authentication`
    2. Requirements:
        1. Authentication service
        2. Credential authenticator service
        3. Google authenticator service
        4. Remember token service
        5. Password service
        6. Password hashing through PHP password APIs
        7. Password verification and rehash detection
        8. Login throttling
        9. Session regeneration
        10. Remember-me token rotation
        11. Email verification hooks
        12. Password reset tokens
        13. Account lock or disable state
        14. Authentication events
        15. Pluggable user resolver
        16. Google SSO
16. Monad middleware RBAC Service:
    1. Namespace `Monad\Clarity\Middlewares\RBAC`
    2. Requirements:
        1. user → role → permission check
        2. user → direct permission check
        3. role → permission check
        4. Route guards
        5. Service-level checks
17. Clarity Session Service:
    1. Namespace `Monad\Clarity\Services\Session`
    2. Includes built-in `sessions` table created during `composer create-project` command, bind `php mitosis setup` command. `user_id` is nullable to support guest / pre-login sessions (including pre-authentication CSRF token storage). `sessions` table shall be defined as:

    ```sql
    CREATE TABLE IF NOT EXISTS `sessions` (
      `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
      `user_id` char(36) COLLATE utf8mb4_unicode_ci NULL,
      `digest` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `ip_address` varchar(39) COLLATE utf8mb4_unicode_ci NOT NULL,
      `user_agent` text COLLATE utf8mb4_unicode_ci,
      `payload` json NOT NULL,
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `expire_at` datetime NOT NULL,
      `revoked_at` datetime,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_sessions_digest` (`digest`),
      KEY `idx_sessions_user_id` (`user_id`)
    ) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ```

18. Clarity Mediator Service:
    1. Namespace `Monad\Clarity\Services\Mediator`
    2. Requirements:
        1. Register error handler
        2. Register exception handler
        3. Register shutdown handler
        4. Development exception renderer, includes:
            1. Exception class
            2. Clear message
            3. File and line
            4. Relevant source-code excerpt
            5. Ordered stack frames
            6. Request ID
            7. Request summary
            8. Previous exception chain
        5. Production exception renderer, includes:
            1. Hide internals
            2. Return an appropriate HTTP status
            3. Record the full exception with Logger service
            4. Return a request or incident ID
19. Clarity Files Service
    1. Namespace `Monad\Clarity\Services\Files`
    2. Requirements:
        1. Single upload
        2. Multiple uploads
        3. MIME detection based on file contents
        4. Extension allowlist
        5. Maximum size
        6. Generated safe filename
        7. Upload error handling
        8. Atomic move
        9. Storage adapter; i.e. S3, filesystem (`/storage/userfiles`)
        10. File deletion
        11. Public/private visibility
20. Clarity Request Service
    1. Namespace `Monad\Clarity\Services\Request`
    2. Requirements:
        1. Parse input
        2. Normalise input where explicitly requested
        3. Validate against application rules
        4. Use parameterised SQL for database operations
        5. Escape according to the output context
        6. Request object should expose:
            1. `$request->method();`
            2. `$request->path();`
            3. `$request->query('page');`
            4. `$request->input('email');`
            5. `$request->json('customer.name');`
            6. `$request->header('Authorization');`
            7. `$request->cookie('mid');`
            8. `$request->file('avatar');`
            9. `$request->ip();`
            10. `$request->userAgent();`
            11. `$request->all();`
        7. Compatible with PSR-7
21. Clarity Response Service
    1. Namespace `Monad\Clarity\Services\Response`
    2. Response object should expose:
        1. `Response::json()`
        2. `Response::htm()`
        3. `Response::text()`
        4. `Response::download()`
        5. `Response::redirect()`
        6. `Response::noContent()`
        7. `Response::stream()`
    3. Returning plain PHP array should be possible, but the router should convert it predictably into a JSON response
22. Clarity Route Service
    1. Namespace `Monad\Clarity\Services\Route`
    2. Supports:
        1. HTTP methods
        2. Named routes
        3. Groups
        4. Prefixes
        5. Middleware
        6. Typed parameters
        7. Optional parameters
        8. Route constraints
        9. Route model binding as an optional extension
        10. Fallback route
        11. 404 and 405 distinction
23. Monad MetaTag Middleware
    1. Namespace `Monad\Clarity\Middlewares\MetaTag`
    2. Generates:
        1. Meta title
        2. Meta description
        3. Canonical link
        4. Robots directives
        5. Open Graph tags
        6. Twitter/X card tags
        7. JSON-LD structured data
        8. Alternate language links
24. Clarity View Service
    1. Namespace `Monad\Clarity\Services\View`
    2. Exposes:
        1. `View::render('users/view', ['user' => $user]);`
        2. `View::share('appName', config('app.name'));`
        3. `View::composer('layouts/app', $callback);`
        4. `View::exists('users/show');`
    3. Rendering pipeline:
        1. Resolve view
        2. Merge local and shared data
        3. Run explicitly registered composers or hooks
        4. Render content
        5. Apply layout
        6. Return response
    4. Avoid:
        1. Runtime magic
        2. Implicit variable injection
25. Clarity HttpClient Service
    1. Namespace `Monad\Clarity\Services\HttpClient`
    2. Abstract HTTP client layer to handle cURL and its responses
    3. Compatible with PSR-18
26. Clarity Cache Service
    1. Namespace `Monad\Clarity\Services\Cache`
    2. Supports:
        1. File cache. Stores in `/storage/cache`
        2. Database cache. Stores in `caches` table created during `composer create-project` command, bind `php mitosis setup` command
        3. Redis adapter
    3. Compatible with PSR-16
    4. `caches` table shall be defined as (DB driver rule: compare `cache_key` on read; never trust `key_hash` alone):

    ```sql
    CREATE TABLE IF NOT EXISTS `caches` (
        `key_hash` BINARY(32) NOT NULL,
        `cache_key` VARCHAR(512) NOT NULL,
        `cache_value` LONGBLOB NOT NULL,
        `encoding` VARCHAR(20) NOT NULL DEFAULT 'serialize',
        `expires_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (`key_hash`),
        INDEX `idx_cache_expires_at` (`expires_at`)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci;
    ```

    5. `key_hash` is the SHA-256 of `cache_key`. `expires_at` NULL means never expires (maps PSR-16 `ttl: null`).
27. Clarity Event Service
    1. Namespace `Monad\Clarity\Services\Event`
    2. A tiny synchronous event dispatcher to decouple:
        1. Successful login
        2. Failed login
        3. Payment completed
        4. User registered
        5. File uploaded
        6. Migration completed
28. Monad Rate Limiting Middleware
    1. Namespace `Monad\Clarity\Middlewares\RateLimiter`
    2. Required for:
        1. Login
        2. Password reset
        3. Public API
        4. LLM operations
        5. DEFERRED — Checkout creation
        6. Webhook abuse protection
29. Clarity security helpers
    1. Cryptographically secure tokens (`Monad\Clarity\Utils\CryptographicToken`)
    2. Encryption at rest (`Monad\Clarity\Utils\Encryption`)
    3. Signed URLs (`Monad\Clarity\Utils\SignedURL`)
    4. HMAC verification (`Monad\Clarity\Utils\HMAC`)
    5. Password hashing (`Monad\Clarity\Utils\Hash`)
    6. Secret redaction (`Monad\Clarity\Utils\Redactor`)
    7. Constant-time comparisons (`Monad\Clarity\Utils\ConstantTime`)
30. Clarity middleware CORS
    1. Controls whether browser-based code loaded from one origin may access a resource hosted at another origin
    2. Namespace `Monad\Clarity\Middlewares\CORS`
    3. Requirements:
        1. Configurable allowed origins
        2. Configurable allowed HTTP methods
        3. Configurable allowed request headers
        4. Configurable exposed response headers
        5. Credentials support
        6. Preflight requests
        7. Preflight cache duration
        8. Route-level override
        9. Environment-specific configuration
        10. Rejection of unauthorised origins
        11. Correct `Vary` response headers
31. Clarity middleware Jsonify
    1. Converts a valid JSON HTTP request body into structured request data before it reaches the controller
    2. Namespace `Monad\Clarity\Middlewares\Jsonify`
    3. Requirements:
        1. Parser accepts every valid JSON value
        2. `Request::json()` may return any decoded value
        3. Application API profiles may require the top-level value to be an object
        4. Not limited to `POST`; should support any method that contains a body; i.e. `POST, PUT, PATCH, DELETE`
        5. Media-type detection
        6. Raw body caching
        7. Body-size limit
        8. `json_decode()` with exceptions
        9. JSON parsing into associative arrays
        10. Large integers as strings
        11. Configurable maximum depth
        12. Structured `400` errors
        13. `415` for JSON-required routes
        14. Separate JSON request data bag
        15. Defer streaming JSON, JSON schema validation and strict duplicate-key detection
    4. Contract with Request service: see `CrossRepoContracts.md` §Jsonify-Request (canonical)
