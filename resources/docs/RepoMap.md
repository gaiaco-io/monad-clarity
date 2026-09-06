# RepoMap.md — Monad Framework repositories

Monad ships as two repositories / Composer packages. This document is maintained canonically
in `monad/clarity`; the skeleton repository carries a copy for local reference — treat
this copy as authoritative on any discrepancy, per the pattern established for
`CrossRepoContracts.md`.

## monad/skeleton

Cloned once via `composer create-project monad/skeleton NewApp`, then owned by the
developer forever — never updated via Composer again (only `monad/clarity` is).

```
/root
├── app
│   ├── Api                  <!-- capitalised to match `namespace App\Api;` — PSR-4 -->
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
│   ├── Middlewares          <!-- extends Monad\Clarity\Middlewares\*; capitalised, PSR-4 -->
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
│   ├── checkout.php         <!-- credentials + adapter for the gateway CHECKOUT_GATEWAY names -->
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
│   │   ├── fonts             <!-- self-hosted Fraunces/IBM Plex Sans/IBM Plex Mono, copied by scripts/copy-assets.js -->
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
│   ├── copy-assets.js
│   └── setup-env.php         <!-- post-create-project-cmd: writes .env, generates APP_SECRET -->
├── storage
│   ├── cache
│   ├── logs
│   │   ├── error
│   │   │   ├── app.log
│   │   │   └── db.log
│   │   └── event
│   │       └── timeline.log
│   └── userfiles
├── mitosis                    <!-- thin stub: exit(Monad\Clarity\Services\Console::run($argv)); -->
├── .env
├── .env_example
├── .git
├── .gitignore
├── CLAUDE.md
├── CLAUDE.md.example
├── composer.json              <!-- requires "monad/clarity": "^1.0" -->
├── package.json
└── README.md
```

## monad/clarity

Installed to `vendor/monad/clarity`, upgraded via `composer update monad/clarity`.
Never modified by application developers.

```
/root
└── vendor
    └── monad
        └── clarity
            ├── src
            │   ├── Middlewares
            │   │   ├── Csrf.php
            │   │   ├── Authentication.php
            │   │   ├── RBAC.php
            │   │   ├── CORS.php
            │   │   ├── Logger.php
            │   │   ├── RateLimiter.php
            │   │   ├── Jsonify.php
            │   │   ├── MetaTag.php          <!-- was Services\SeoService; relocated + renamed -->
            │   │   └── Authentication        <!-- what Authentication.php hands back -->
            │   │       ├── AuthResult.php
            │   │       └── AuthenticationException.php
            │   ├── Services
            │   │   ├── DB.php
            │   │   ├── Files.php
            │   │   ├── Mediator.php
            │   │   ├── Request.php
            │   │   ├── Response.php
            │   │   ├── Route.php
            │   │   ├── Session.php
            │   │   ├── View.php
            │   │   ├── Console.php          <!-- console kernel: Console::run(array $argv): int -->
            │   │   ├── Checkout.php         <!-- adapter contract; released 1.2.0 -->
            │   │   ├── Schema.php
            │   │   ├── LLM.php
            │   │   ├── Migration.php
            │   │   ├── Cache.php
            │   │   ├── CacheInvalidArgumentException.php  <!-- PSR-16 InvalidArgumentException -->
            │   │   ├── Event.php
            │   │   ├── HttpClient.php
            │   │   ├── HttpClientException.php            <!-- PSR-18 RequestExceptionInterface -->
            │   │   ├── Scheduler.php        <!-- job registry; released 1.5.0 -->
            │   │   ├── Mail.php             <!-- adapter contract, no constructor; released 1.6.0 -->
            │   │   ├── Checkout               <!-- value objects + ledger; released 1.2.0 -->
            │   │   │   ├── CallbackEvent.php
            │   │   │   ├── CheckoutException.php
            │   │   │   ├── CheckoutRequest.php
            │   │   │   ├── CheckoutSession.php
            │   │   │   ├── LineItem.php
            │   │   │   ├── Money.php
            │   │   │   ├── RefundRequest.php
            │   │   │   ├── RefundResult.php
            │   │   │   ├── TransactionLedger.php
            │   │   │   ├── TransactionSnapshot.php
            │   │   │   ├── TransactionStatus.php
            │   │   │   <!-- subscriptions; 1.4.0 -->
            │   │   │   ├── BillingCycle.php
            │   │   │   ├── BillingInterval.php
            │   │   │   ├── PaymentFailureBehaviour.php
            │   │   │   ├── ProrationBillingMode.php
            │   │   │   ├── ResumeBilling.php
            │   │   │   ├── ScheduledChange.php
            │   │   │   ├── ScheduledChangeAction.php
            │   │   │   ├── SubscriptionEffectiveFrom.php
            │   │   │   ├── SubscriptionEvent.php
            │   │   │   ├── SubscriptionItem.php
            │   │   │   ├── SubscriptionLedger.php
            │   │   │   ├── SubscriptionSnapshot.php
            │   │   │   └── SubscriptionStatus.php
            │   │   ├── CheckoutAdapters
            │   │   │   ├── PaddleCheckout.php
            │   │   │   ├── PaddleSubscription.php   <!-- recurring billing; 1.4.0 -->
            │   │   │   ├── SpeaksPaddle.php         <!-- trait: what both Paddle adapters share -->
            │   │   │   └── StripeCheckout.php
            │   │   │   <!-- StripeConnectExpress, Fiuu, iPay88, BillPlz, Adyen, Airwallex,
            │   │   │        HitPay, Xendit: namespaces reserved, files do NOT exist. Each
            │   │   │        ships in its own minor when built end to end — never a stub. -->
            │   │   ├── LLM                   <!-- request/response value objects -->
            │   │   │   ├── LLMException.php
            │   │   │   ├── LLMRequest.php
            │   │   │   └── LLMResponse.php
            │   │   ├── LLMAdapters
            │   │   │   ├── OpenAI.php
            │   │   │   ├── Anthropic.php
            │   │   │   ├── AnthropicStructuredOutput.php  <!-- enum; released 1.8.0 -->
            │   │   │   ├── DeepSeek.php
            │   │   │   └── Gemini.php
            │   │   ├── Mail                  <!-- value objects + MIME + pool; released 1.6.0 -->
            │   │   │   ├── Address.php
            │   │   │   ├── Attachment.php
            │   │   │   ├── Attempt.php
            │   │   │   ├── FailureScope.php  <!-- enum: whose fault a send failure was -->
            │   │   │   ├── Header.php        <!-- injection guard + RFC 2047 encoding -->
            │   │   │   ├── MailException.php
            │   │   │   ├── MailerPool.php    <!-- extends Services\Mail; ordered failover -->
            │   │   │   ├── Message.php
            │   │   │   ├── MimeMessage.php   <!-- RFC 5322; used by Smtp AND AmazonSes -->
            │   │   │   ├── SentMessage.php
            │   │   │   ├── SmtpEncryption.php
            │   │   │   ├── SmtpTransport.php <!-- interface: the socket seam -->
            │   │   │   └── SocketTransport.php
            │   │   ├── MailAdapters          <!-- released 1.6.0; all seven built -->
            │   │   │   ├── AmazonSes.php     <!-- injected SesV2Client-shaped object -->
            │   │   │   ├── Mailgun.php
            │   │   │   ├── Mailtrap.php
            │   │   │   ├── Postmark.php
            │   │   │   ├── Resend.php
            │   │   │   ├── SendGrid.php
            │   │   │   ├── Smtp.php          <!-- no HttpClient; speaks to a socket -->
            │   │   │   └── SpeaksHttpApi.php <!-- trait: what the six HTTP mailers share -->
            │   │   ├── Scheduler             <!-- cron parser + run ledger; released 1.5.0 -->
            │   │   │   ├── CronExpression.php
            │   │   │   ├── JobLedger.php
            │   │   │   ├── RunState.php
            │   │   │   ├── ScheduledJob.php
            │   │   │   └── SchedulerException.php
            │   │   └── Schema                <!-- what Schema.php's closures receive -->
            │   │       ├── Blueprint.php
            │   │       └── RawExpression.php
            │   ├── Utils
            │   │   ├── CryptographicToken.php
            │   │   ├── Encryption.php
            │   │   ├── SignedURL.php
            │   │   ├── HMAC.php
            │   │   ├── Hash.php
            │   │   ├── Redactor.php
            │   │   └── ConstantTime.php
            │   └── Console                   <!-- command classes, dispatched by Services\Console -->
            │       ├── Command.php           <!-- base class every command below extends -->
            │       ├── Arguments.php         <!-- parsed argv: flags, options, positionals -->
            │       ├── GeneratesFiles.php    <!-- trait: what the Make* commands share -->
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
            │       ├── CheckoutInstall.php   <!-- checkout:install; added 1.2.0 -->
            │       ├── ScheduleInstall.php   <!-- schedule:install; added 1.5.0 -->
            │       ├── ScheduleList.php      <!-- schedule:list; added 1.5.0 -->
            │       ├── ScheduleRun.php       <!-- schedule:run; added 1.5.0 -->
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
                │   ├── GapAnalysis_BuildPlan_1.0.0.md
                │   ├── GapAnalysis_BuildPlan_1.6.0.md
                │   ├── PRD.md
                │   ├── ReleaseNotes_1.0.0.md
                │   ├── ReleaseNotes_1.2.0.md
                │   ├── ReleaseNotes_1.3.0.md
                │   ├── ReleaseNotes_1.4.0.md
                │   ├── ReleaseNotes_1.5.0.md
                │   ├── ReleaseNotes_1.6.0.md
                │   ├── ReleaseNotes_1.7.0.md
                │   ├── ReleasePolicy.md
                │   ├── RepoMap.md             <!-- this file -->
                │   └── TestingStrategy.md
                ├── reports
                └── tests
```

## Key structural notes

- Checkout shipped in 1.2.0: `Services\Checkout` (the adapter contract), `Services\Checkout\*`
  (value objects and `TransactionLedger`), and one adapter, `CheckoutAdapters\StripeCheckout`.
  `CheckoutAdapters\PaddleCheckout` followed in 1.3.0, adding nothing to the facade or the
  tables — the second adapter is the whole release. The eight remaining adapter namespaces are
  reserved and their files genuinely do not exist — per `Architecture.md` §8 and
  `ReleasePolicy.md`, an unbuilt adapter is an absent file, never a stub on `main`. See
  `ReleaseNotes_1.2.0.md` and `ReleaseNotes_1.3.0.md` for what is and is not in each release.
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
