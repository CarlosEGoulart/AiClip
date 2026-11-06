# M14 — Production Hardening Test Plan

## Overview

This test plan defines the verification strategy for M14 Production Hardening. It follows the repository's TDD (RED → GREEN → REFACTOR) policy and AGENTS.md §13–§14 testing requirements.

M14 is primarily an audit-and-hardening effort. Tests verify that: secrets are externalized and never leaked; CORS/CSRF/authentication/authorization/rate limits hold; security-sensitive logging excludes secrets/tokens/storage internals; queue failures are observable and recoverable (retries, timeouts, failed-job visibility, idempotency); health/readiness checks return safe aggregated status without exposing internals; migrations are reversible; and a production-like E2E smoke test passes at 3 viewports.

Test environments must use deterministic fakes only — no real platform API credentials, no network calls to platforms, per AGENTS.md §21 and spec §Security Considerations.

---

## 1. Backend Unit / Feature Tests (Pest)

### 1.1 ProductionConfigTest

File: `tests/Feature/ProductionConfigTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.1.1 | Required production env vars present (`APP_KEY`, `DB_*`, `AWS_*`) | bootstrap fails fast if missing |
| 1.1.2 | `APP_DEBUG` false in production-like env | `config('app.debug')` is `false` |
| 1.1.3 | `APP_KEY` present and not the committed example value | key is non-default |
| 1.1.4 | No hardcoded secret literals in source | grep assertion finds no known secret patterns |

### 1.2 CorsTest

File: `tests/Feature/CorsTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.2.1 | CORS preflight with configured production origin | origin reflected, credentials supported |
| 1.2.2 | CORS with `*` origin and credentials enabled | rejected / not allowed |
| 1.2.3 | Allowed methods/headers scoped (not wildcard with credentials) | no `*` when `supports_credentials` true |

### 1.3 AuthConfigTest

File: `tests/Feature/AuthConfigTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.3.1 | Sanctum `stateful` domains env-driven | not hardcoded to localhost/cloudshell only |
| 1.3.2 | Session cookie `secure` true over HTTPS | `config('session.secure')` true in production |
| 1.3.3 | Session cookie `http_only` true | `config('session.http_only')` is `true` |
| 1.3.4 | Session cookie `same_site` lax or strict | `config('session.same_site')` in (`lax`, `strict`) |

### 1.4 CsrfRateLimitTest

File: `tests/Feature/CsrfRateLimitTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.4.1 | State-changing POST without CSRF token | 419 |
| 1.4.2 | State-changing POST with valid CSRF token | request proceeds (not 419) |
| 1.4.3 | Rate-limit headers present on login | `Retry-After` / `X-RateLimit-*` present |
| 1.4.4 | Rate limit enforced on login | 429 after threshold |
| 1.4.5 | Every state-changing route has a `throttle:` middleware | no unthrottled state-changing endpoint |
| 1.4.6 | Every state-changing `auth:sanctum` route has CSRF | `VerifyCsrfToken` applied |

### 1.5 AuthorizationTest

File: `tests/Feature/AuthorizationTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.5.1 | Non-owner access to own media | 404 (no existence leak) |
| 1.5.2 | Non-owner access to own image | 404 (no existence leak) |
| 1.5.3 | Non-owner access to own clip review | 404 (no existence leak) |
| 1.5.4 | Upload/download endpoints enforce ownership | unauthorized user cannot access |
| 1.5.5 | Storage internals not in response bodies | no `storage_key`, `bucket`, prompt in API responses |

### 1.6 ErrorSanitizationTest

File: `tests/Feature/ErrorSanitizationTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.6.1 | 503/500 error body sanitized | no stack trace, no storage internals, no secrets |
| 1.6.2 | 422/404 bodies contain no tokens/credentials | no `access_token`, `refresh_token`, secrets |
| 1.6.3 | Exception message with DB password sanitized before log/response | password not present in logged message or response |

### 1.7 LoggingSanitizationTest

File: `tests/Feature/LoggingSanitizationTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.7.1 | Log record from publish/social operation contains no token | no `access_token`/`refresh_token` in log |
| 1.7.2 | Exception log with DB connection string password is sanitized | password not present in log |
| 1.7.3 | No `storage_key`/`bucket` in log context | storage internals not logged |
| 1.7.4 | Production `LOG_LEVEL` is `info`/`error` (not `debug`) | `config('logging.level')` != `debug` in prod-like |

### 1.8 QueueReliabilityTest

File: `tests/Feature/QueueReliabilityTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.8.1 | Default queue connection is database (persisted) | `config('queue.default')` = `database` |
| 1.8.2 | `retry_after` configured for media worker jobs | value present and > 0 |
| 1.8.3 | `failed_jobs` driver/table configured | failed jobs persisted, not dropped |
| 1.8.4 | Job timeouts set for media jobs | timeout matches `config/media.php` values |
| 1.8.5 | Exhausted job moves to `failed_jobs` table | job persisted as failed, not silently dropped |
| 1.8.6 | Failed job payload/exception stored and inspectable (sanitized) | exception present, no secrets |
| 1.8.7 | Failed job retry with backoff; exhausted → failed_jobs | retry behavior observed |
| 1.8.8 | `ProcessMediaAsset` idempotent | skip-if-not-pending / unique claim |
| 1.8.9 | `RenderMediaClip` idempotent | no duplicate DerivedAsset on re-dispatch |
| 1.8.10 | `GenerateImage` idempotent | no duplicate image on re-dispatch |
| 1.8.11 | `PublishTo*` idempotent | uniqueId + pending-skip; no duplicate publication |
| 1.8.12 | Worker subprocess failure → durable sanitized failure | sanitized error stored, work not lost |

### 1.9 HealthReadinessTest

File: `tests/Feature/HealthReadinessTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.9.1 | `GET /api/v1/health` returns 200 | `status`, `dependencies` (database, queue, storage) |
| 1.9.2 | Database disconnected → 503 | status degraded, no stack trace/internals |
| 1.9.3 | Storage unreachable → degraded status | no `storage_key`/`bucket` leaked |
| 1.9.4 | Queue unreachable → degraded status | no internals leaked |
| 1.9.5 | Response contains no credentials/tokens/stack traces | safe aggregated status only |

### 1.10 MigrationRollbackTest

File: `tests/Feature/MigrationRollbackTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.10.1 | `migrate:fresh --env=testing` succeeds | test schema loads cleanly |
| 1.10.2 | `migrate:rollback --env=testing` succeeds | migrations reverse cleanly |
| 1.10.3 | Destructive migrations guarded | flagged/reviewed before run |

---

## 2. Backend Integration Tests (Pest)

### 2.1 SmokeFlowIntegrationTest

File: `tests/Feature/SmokeFlowIntegrationTest.php`

Uses fakes for social platforms; sync queue; real database; no network.

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 2.1.1 | Register → login → create project → upload media → processing completes | DerivedAsset created, media processed |
| 2.1.2 | Clip review → approve → publish to connected platform | publish succeeds, status published |
| 2.1.3 | Publish failure → retry → success | retry recovers, no duplicate publication |
| 2.1.4 | No tokens in API responses | no `access_token`/`refresh_token` in JSON |
| 2.1.5 | Failed-job visibility | failed job listed in `failed_jobs`, inspectable |

---

## 3. Frontend Unit Tests (Vitest)

### 3.1 Smoke test supporting assertions

File: `apps/web/tests/e2e/production-smoke.spec.ts` (E2E, see section 5)

No new Vitest-only components are added by M14 (no new UI). Existing Vitest suites must remain green — no regressions.

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 3.1.1 | API client error classification handles sanitized 500/422/404 | error messages actionable, no internals exposed to UI |
| 3.1.2 | No tokens stored in browser state from API responses | response tokens not persisted |

### 3.2 Regression

- [ ] Full frontend unit suite (`npm run test`) → all pass (no regressions from M14)

---

## 4. Governance & Lint

### 4.1 Backend Lint

- `vendor/bin/pint --dirty --format agent` → clean (no diffs)

### 4.2 Frontend Lint & Build

- `npm run lint` (eslint) → clean
- `npm run build` → clean

### 4.3 Governance Tests

- `php artisan test --filter=Governance` → all pass

---

## 5. Frontend E2E Tests (Playwright)

File: `apps/web/tests/e2e/production-smoke.spec.ts`

Default viewports: `390x844`, `768x1024`, `1440x900` (run all tests at all 3 viewports).

Environment: production-like (real PostgreSQL, MinIO/S3, queue worker); social platforms via deterministic fakes (no real credentials, no network).

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 5.1 | Register → login → create project → upload media → processing completes | media processed, DerivedAsset created |
| 5.2 | View clip review → approve → publish to connected platform | publish succeeds, status published |
| 5.3 | Publish failure → retry → success | retry recovers, no duplicate |
| 5.4 | No tokens in browser DevTools / network | no `access_token`/`refresh_token` in DevTools or network payloads |
| 5.5 | Console cleanliness | no unexpected JS console errors |
| 5.6 | Network cleanliness | no unexpected 4xx/5xx unless scenario requires |

---

## 6. Full Test Execution (Definition of Done)

| Step | Command | Target |
|------|---------|--------|
| Backend unit + feature | `php artisan test --compact` (apps/api) | all pass |
| Frontend unit | `npm run test` (apps/web) | all pass |
| E2E | `npm run test:e2e` (apps/web) | all pass at 3 viewports |
| Governance | `php artisan test --filter=Governance` | all pass |
| Backend lint | `vendor/bin/pint --dirty --format agent` | clean |
| Frontend lint | `npm run lint` | clean |
| Frontend build | `npm run build` | clean |

Failure of any step blocks merge via `scripts/merge_gate.py` and blocks issue closure (AGENTS.md §11).

---

## 7. Test Data & Fixtures

- Deterministic fakes for social platforms (`FakeYouTubePublisher`, `FakeInstagramPublisher`, `FakeTikTokPublisher`, `FakeImageGenerationProvider`).
- Queue: sync driver in unit/feature tests; database queue for failed-job visibility tests.
- Factories: `User`, `SocialConnection`, `ClipReview`, `MediaAsset`, `DerivedAsset`, `Image`, `SocialPublish`.
- No secrets in fixtures committed to Git (AGENTS.md §23).
- E2E needs prepared PostgreSQL, installed Chromium browsers, and free ports 5173/8000 (AGENTS.md Known Limitations).

### Environment Guards

- CI uses deterministic fakes; no real platform API credentials, no network calls to platforms.
- Production-like smoke test runs against real PostgreSQL + MinIO + queue worker with social fakes.
- `.env` never inspected or committed (AGENTS.md Planner boundary).

---

## 8. Evidence Collection

The Planner and Builder do not edit `evidence.md` (Orchestrator-owned). Evidence of test execution, lint results, governance results, and the production-readiness checklist (fixed / blocker / accepted risk with owner and rationale) is recorded by Tester/Orchestrator during the merge gate.
