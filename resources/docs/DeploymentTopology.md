# DeploymentTopology.md — monad/clarity

Clarity is a library, not a deployable unit — it has no server topology of its own. This
document covers the deployment-*relevant* properties Clarity must satisfy so that applications
built on it (via `monad/skeleton`) can be deployed correctly. Application-level topology
(load balancers, containers, process managers, CDN) is the skeleton/application's concern, not
this repo's.

## 1. Runtime requirements

- PHP `>=8.2` (see `Architecture.md` §3).
- Required extensions (verified at runtime by `php mitosis health`, §8.11): `pdo`,
  `pdo_mysql` (default DB driver), `mbstring`, `json`, `curl` (HttpClient), `openssl` and/or
  `sodium` (Encryption, SignedURL), `fileinfo` (Files — content-based MIME detection,
  never the client-supplied `Content-Type`), `redis` (only if the Redis cache adapter is
  in use).
- `ext-redis` and PostgreSQL/SQLite PDO drivers are optional, feature-gated by config — Clarity
  must not hard-require them at the package level (`composer.json` `suggest`, not `require`).

## 2. Statelessness and horizontal scaling

Because Session and Cache both support DB-backed and Redis-backed drivers (not filesystem-only),
an application built on Clarity CAN run across multiple stateless web nodes without sticky
sessions — the file-based Session/Cache drivers are single-node only, by nature of local disk.
This is an architectural property Clarity must preserve: any new stateful feature needs a
shared-backend story (DB or Redis) before it can be considered production-ready, not just a
local-filesystem implementation.

## 3. Storage adapter topology

- **Files service**: filesystem adapter (`/storage/userfiles`) is the default; S3 is an
  optional adapter for object storage. Which one is active is an application-level config
  choice, not a Clarity-level assumption — Clarity must not assume local disk is durable or
  shared across nodes.
- **Cache service**: file (`/storage/cache`, single-node), database (`caches` table, shared),
  Redis (shared, external service). See §2.
- **Logger**: writes to local files (`/storage/logs/...`) by design (§14.2.11) — on multi-node
  deployments, log aggregation across nodes is an application/ops concern, not something
  Clarity centralises.

## 4. Outbound network dependencies

- **HttpClient / LLM adapters**: outbound HTTPS to provider APIs (OpenAI, Anthropic, DeepSeek,
  Gemini). Provider API keys are supplied via application config/`.env` — Clarity never
  hardcodes, logs, or persists raw API keys (Logger's redaction utility, `Utils\Redactor`,
  must treat these as sensitive by default).
- **Authentication (Google SSO)**: outbound HTTPS to Google's OAuth endpoints via HttpClient.
- **Checkout adapters** (1.2.0 onward): outbound HTTPS to each configured payment gateway.
  Currently that means Stripe (`api.stripe.com`) via `CheckoutAdapters\StripeCheckout` and
  Paddle (`api.paddle.com`, or `sandbox-api.paddle.com` for the sandbox environment) via
  `CheckoutAdapters\PaddleCheckout` and, from 1.4.0, `CheckoutAdapters\PaddleSubscription` —
  the same hosts, the same credentials, and the same two account prerequisites below, since a
  subscription is started through the same Transactions API; each further gateway adds its own
  host. Gateway secret keys and webhook signing secrets
  come from application config/`.env` and are treated exactly as provider API keys above —
  never hardcoded, logged, or persisted, and sensitive to `Utils\Redactor` by default.
  Paddle additionally requires a **default payment link** configured on the Paddle account
  (Paddle > Checkout > Checkout settings) before it will create a transaction at all — it
  refuses with `transaction_default_checkout_url_not_set` regardless of collection mode, and
  a per-transaction `checkout.url` override does not substitute for it. This is account
  configuration rather than application config, so it is a deployment prerequisite for
  `CheckoutAdapters\PaddleCheckout` in both of its modes. Its `$paymentPageUrl` mode needs a
  second piece of the same kind: that URL's domain must be approved under Paddle > Checkout >
  Website approval, in sandbox as well as live, or transaction creation fails with
  `transaction_checkout_url_domain_is_not_approved`. Both were confirmed against a live
  sandbox account, the second contradicting the common claim that sandbox approves any domain
  automatically, and both reconfirmed for `CheckoutAdapters\PaddleSubscription` in 1.4.0 — a
  domain already serving as the account's default payment link is still refused as a
  per-transaction override, because the default-link setting and the Website-approval list are
  different things.
  For verification purposes, note a subscription can be created **without a browser**: a
  `collection_mode: manual` transaction against a customer with a *complete* postal address
  (region and second line present) reaches `billed` and creates a live subscription with no card
  involved. `ReleaseNotes_1.3.0.md` §5.3 recorded that route as closed; the blocker was the
  address, not the customer.
  Note the dependency is bidirectional, unlike every other entry here: gateways deliver
  **inbound** webhook callbacks, so the callback route must be publicly reachable and must
  not sit behind authentication. Callback handling is idempotent, which matters because
  gateways redeliver on any non-2xx or timeout.

## 5. Health checks (`php mitosis health`, §8.11)

The single command an operator or CI pipeline runs to verify a deployment is viable. Must
check: configuration completeness (required `.env`/config keys present), DB connectivity,
writable storage paths (`storage/cache`, `storage/logs/*`, `storage/userfiles`), migration
status (no pending migrations), and required PHP extensions per §1. Health is the deployment
acceptance gate referenced in `GapAnalysis_BuildPlan_1.0.0.md` — a deployment is not
considered live until `health` passes clean.

## 6. Out of scope for this document

Server provisioning, container images, reverse proxy/load balancer configuration, CI/CD
pipeline definitions, and process supervision (e.g. `php mitosis serve` is a development
convenience per §8.12, not a production server) belong to the application repository
(`monad/skeleton`) or the deploying team's own infrastructure documentation, not to Clarity.
