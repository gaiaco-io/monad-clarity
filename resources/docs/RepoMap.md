# RepoMap.md — Monad Framework repositories

Monad ships as two repositories / Composer packages. This document is maintained canonically
in `gaia/monad-clarity`; the skeleton repository carries a copy for local reference — treat
this copy as authoritative on any discrepancy, per the pattern established for
`CrossRepoContracts.md`.

## gaia/monad-skeleton

Cloned once via `composer create-project gaia/monad-skeleton NewApp`, then owned by the
developer forever — never updated via Composer again (only `gaia/monad-clarity` is).

```
/root
├── app
│   ├── api
│   ├── client
│   │   └── src
│   │       ├── css
│   │       │   └── styles.css
│   │       └── js
│   ├── Controllers          <!-- capitalised to match `namespace App\Controllers;` — PSR-4 -->
│   ├── Models               <!-- capitalised to match `namespace App\Models;` — PSR-4 -->
│   ├── routes
│   │   ├── api.php
│   │   ├── cli.php
│   │   └── web.php
│   ├── Middlewares          <!-- extends Gaia\Clarity\Middlewares\*; capitalised, PSR-4 -->
│   ├── Services             <!-- capitalised to match `namespace App\Services;` — PSR-4 -->
│   └── views
│       ├── Errors
│       │   └── 404.php
│       ├── Home
│       ├── Layouts
│       │   └── main.php
│       └── Users
├── config
│   ├── bootstrap.php        <!-- thin: autoload + .env + hand config to Clarity kernel -->
│   ├── database.php
│   ├── dir.php
│   ├── llm.php
│   ├── locale.php
│   └── mail.php
├── database
│   ├── migrations
│   └── seeds
├── public
│   ├── assets
│   │   ├── css
│   │   ├── img
│   │   └── js
│   ├── index.php             <!-- thin: delegates to Clarity kernel -->
│   ├── llms.txt
│   ├── router.php            <!-- PHP built-in server -->
│   └── sitemap.xml
├── resources
│   ├── docs                  <!-- app-level: PRD, API_Contracts, DDL, DesignTokens, UIUXRules, etc. -->
│   ├── reports
│   └── tests
├── scripts
│   └── copy-assets.js
├── storage
│   ├── cache
│   ├── logs
│   │   ├── error
│   │   │   ├── app.log
│   │   │   └── db.log
│   │   └── event
│   │       └── timeline.log
│   └── userfiles
├── mitosis                    <!-- thin stub: exit(Gaia\Clarity\Services\Console::run($argv)); -->
├── .env
├── .env_example
├── .git
├── .gitignore
├── CLAUDE.md
├── CLAUDE.md.example
├── composer.json              <!-- requires "gaia/monad-clarity": "^1.0" -->
├── package.json
└── README.md
```

## gaia/monad-clarity

Installed to `vendor/gaia/monad-clarity`, upgraded via `composer update gaia/monad-clarity`.
Never modified by application developers.

```
/root
└── vendor
    └── gaia
        └── monad-clarity
            ├── src
            │   ├── Middlewares
            │   │   ├── Csrf.php
            │   │   ├── Authentication.php
            │   │   ├── RBAC.php
            │   │   ├── CORS.php
            │   │   ├── Logger.php
            │   │   ├── RateLimiter.php
            │   │   └── Jsonify.php
            │   ├── Services
            │   │   ├── DB.php
            │   │   ├── Files.php
            │   │   ├── Mediator.php
            │   │   ├── Request.php
            │   │   ├── Response.php
            │   │   ├── Route.php
            │   │   ├── MetaTag.php
            │   │   ├── Session.php
            │   │   ├── View.php
            │   │   ├── Console.php          <!-- console kernel: Console::run(array $argv): int -->
            │   │   ├── Checkout.php         <!-- DEFERRED — not on main -->
            │   │   ├── Schema.php
            │   │   ├── LLM.php
            │   │   ├── Migration.php
            │   │   ├── Cache.php
            │   │   ├── Event.php
            │   │   ├── HttpClient.php
            │   │   ├── CheckoutAdapters      <!-- DEFERRED — not on main -->
            │   │   │   ├── Fiuu.php
            │   │   │   ├── iPay88.php
            │   │   │   ├── BillPlz.php
            │   │   │   ├── StripeCheckout.php
            │   │   │   ├── StripeConnectExpress.php
            │   │   │   ├── Adyen.php
            │   │   │   ├── Airwallex.php
            │   │   │   ├── HitPay.php
            │   │   │   └── Xendit.php
            │   │   └── LLMAdapters
            │   │       ├── OpenAI.php
            │   │       ├── Anthropic.php
            │   │       ├── DeepSeek.php
            │   │       └── Gemini.php
            │   ├── Utils
            │   │   ├── CryptographicToken.php
            │   │   ├── Encryption.php
            │   │   ├── SignedURL.php
            │   │   ├── HMAC.php
            │   │   ├── Hash.php
            │   │   ├── Redactor.php
            │   │   └── ConstantTime.php
            │   └── Console                   <!-- command classes, dispatched by Services\Console -->
            │       ├── MakeController.php
            │       ├── MakeModel.php
            │       ├── MakeMigration.php
            │       ├── MakeService.php
            │       ├── Migrate.php
            │       ├── MigrateStatus.php
            │       ├── MigrateRollback.php
            │       ├── DBSeed.php
            │       ├── DBExecute.php
            │       ├── Test.php
            │       ├── Health.php
            │       ├── Serve.php
            │       ├── Setup.php
            │       ├── CacheClear.php
            │       └── LogsClear.php
            ├── CHANGELOG.md
            ├── composer.json
            ├── README.md
            ├── LICENSE
            ├── CLAUDE.md                     <!-- export-ignore'd from Packagist dist -->
            ├── .gitattributes                <!-- declares export-ignore for resources/, CLAUDE.md -->
            ├── .gitignore
            └── resources                     <!-- export-ignore'd from Packagist dist -->
                ├── docs
                │   ├── API_Contracts.md
                │   ├── Architecture.md
                │   ├── CrossRepoContracts.md  <!-- CANONICAL copy -->
                │   ├── DDL.sql
                │   ├── DeploymentTopology.md
                │   ├── GapAnalysis_BuildPlan_26.07.md
                │   ├── PRD.md
                │   ├── ReleaseNotes_26.07.md
                │   ├── ReleasePolicy.md
                │   ├── RepoMap.md             <!-- this file -->
                │   └── TestingStrategy.md
                ├── reports
                └── tests
```

## Key structural notes

- Checkout and its adapters are shown here as reserved namespace/file locations for reference
  only. Per `Architecture.md` §8 and `ReleasePolicy.md`, they must not exist on `main` or in
  any tagged 1.0.0 release.
- `Services\Console.php` is the stable kernel entry point (`CrossRepoContracts.md` §2–3);
  `src/Console/*` command classes are internal and may be reorganised freely in minor releases.
- `app/Middlewares` in the skeleton and `src/Middlewares` in Clarity are two different things:
  the skeleton's are thin developer-owned extensions of the Clarity engines.
- Every `App\*`-namespaced skeleton directory (`Controllers`, `Models`, `Services`,
  `Middlewares`) is capitalised to match its namespace segment exactly — PSR-4 resolves
  paths case-sensitively, so a lowercase directory paired with a capitalised namespace
  autoloads correctly by coincidence on a case-insensitive filesystem (macOS, Windows) and
  fails on a case-sensitive one (Linux — most CI and production hosts). `routes/` and
  `views/` stay lowercase since neither is PSR-4-autoloaded (route files are `require`d
  directly; views are resolved by `View`'s own path logic).
