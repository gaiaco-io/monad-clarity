# DeploymentTopology.md — gaia/monad-clarity

Clarity is a library, not a deployable unit — it has no server topology of its own. This
document covers the deployment-*relevant* properties Clarity must satisfy so that applications
built on it (via `gaia/monad-skeleton`) can be deployed correctly. Application-level topology
(load balancers, containers, process managers, CDN) is the skeleton/application's concern, not
this repo's.

## 1. Runtime requirements

- PHP `>=8.2` (see `Architecture.md` §3).
- Required extensions (verified at runtime by `php mitosis health`, §8.11): `pdo`,
  `pdo_mysql` (default DB driver), `mbstring`, `json`, `curl` (HttpClient), `openssl` and/or
  `sodium` (Encryption, SignedURL), `redis` (only if the Redis cache adapter is in use).
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
- **Checkout (deferred)**: would introduce outbound dependencies on each payment gateway;
  not applicable until Checkout ships.

## 5. Health checks (`php mitosis health`, §8.11)

The single command an operator or CI pipeline runs to verify a deployment is viable. Must
check: configuration completeness (required `.env`/config keys present), DB connectivity,
writable storage paths (`storage/cache`, `storage/logs/*`, `storage/userfiles`), migration
status (no pending migrations), and required PHP extensions per §1. Health is the deployment
acceptance gate referenced in `GapAnalysis_BuildPlan_26.07.md` — a deployment is not
considered live until `health` passes clean.

## 6. Out of scope for this document

Server provisioning, container images, reverse proxy/load balancer configuration, CI/CD
pipeline definitions, and process supervision (e.g. `php mitosis serve` is a development
convenience per §8.12, not a production server) belong to the application repository
(`gaia/monad-skeleton`) or the deploying team's own infrastructure documentation, not to Clarity.
