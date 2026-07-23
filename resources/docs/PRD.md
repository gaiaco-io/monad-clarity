# PRD.md — gaia/monad-clarity, Release 26.07

## Purpose

Clarity is the core library of the Monad Framework: an MVC-based PHP library providing
routing, HTTP request/response handling, views, database/schema access, sessions,
authentication, security utilities, caching, an LLM service, and a CLI, distributed as a
standalone Composer package consumed by the `gaia/monad-skeleton` application scaffold.

## Audience

Solo developers and small teams building server-rendered PHP applications who want a
non-opinionated core with the fundamentals (security, performance) handled, without the
convention overhead or abstraction depth of Laravel or Symfony.

## Philosophy (governs every decision in this release)

- **Light scaffolding** — implement only what's necessary.
- **Elegant coding** — code reads like clear, purposeful prose.
- **Keep it simple** — no over-engineering; every line has meaning.
- **Fundamentals are given** — security and performance are default, not optional add-ons.
- **Beautifully done** — human-first, including exception and CLI output.
- **Freedom** — Monad enables, never dictates implementation choices.
- **Very fast** — in development and at runtime.

## Scope: what ships in 26.07

**Services:** DB, Schema, Request, Response, Route, Session, View, Mediator, Files, Migration,
Cache, Event, HttpClient, LLM (+ 4 adapters: OpenAI, Anthropic, DeepSeek, Gemini), Console.

**Middlewares:** Csrf, Logger, Authentication, RBAC, RateLimiter, MetaTag, CORS, Jsonify.

**Security utilities:** CryptographicToken, Encryption, SignedURL, HMAC, Hash, Redactor,
ConstantTime.

**CLI (`mitosis`, 15 commands):** make:controller, make:model, make:migration, make:service,
migrate, migrate:status, migrate:rollback, db:seed, db:execute, test, health, serve,
cache:clear, logs:clear, setup.

**Distribution:** MIT-licensed, published to Packagist as `gaia/monad-clarity`; consumed via
`composer create-project gaia/monad-skeleton NewApp` and updated via
`composer update gaia/monad-clarity`.

## Explicitly out of scope for 26.07

- **Checkout service** and all payment gateway adapters (Fiuu, iPay88, BillPlz, Stripe, Adyen,
  Airwallex, HitPay, Xendit) — deferred; namespace reserved; not on `main`.
- Agentic LLM features: tool orchestration, agents, vector databases, memory, prompt
  pipelines, automatic cross-provider retries.
- SPA framework support (React/Vue) — not a Monad concern at any layer.
- Full PSR-7 interface implementation on Request/Response (bridge methods only — see
  `Architecture.md` §6).
- Server/infrastructure topology (see `DeploymentTopology.md` §6).

## Success criteria (1.0.0 acceptance gate)

From `GapAnalysis_BuildPlan_26.07.md`: `composer create-project gaia/monad-skeleton NewApp`
succeeds from Packagist for a stranger; all 15 `mitosis` commands function; `php mitosis health`
passes all five checks on a fresh install; the PHPUnit suite is green; both repos' READMEs and
`CrossRepoContracts.md` are published; no placeholder, mock-only, or non-functional code exists
on `main` (Checkout excluded by design, not by omission).

## Non-negotiable constraints

No partial implementations, no placeholders, no TODO-only code — every feature in scope ships
end-to-end in production-ready form or is formally deferred (as Checkout is). PHP `>=8.2`.
Namespace `Gaia\Clarity\`. Strict semver from `1.0.0` onward.
