# M14 — Production Hardening Implementation Plan

## Overview

This plan breaks down M14 Production Hardening into sequential, testable increments following TDD (RED → GREEN → REFACTOR). M14 does not add user-facing features; it hardens the existing platform through configuration fixes, test coverage, health/readiness checks, documentation, and a production-like smoke test.

Each phase delivers a vertical slice that can be independently verified. Where the work is documentation/audit, the "implementation" is the document plus tests that verify the properties it describes.

Branch: `@carlosegoulart/123/feat/production-hardening-v2` (created by Orchestrator).

---

## Phase 0: Project Setup & Verification

### 0.1 Verify Spec Directory

- [ ] Verify `specs/123-production-hardening/` exists (spec.md, plan.md, test-plan.md created by Planner)
- [ ] Verify branch `@carlosegoulart/123/feat/production-hardening` is checked out

No new production feature code is required in this phase.

---

## Phase 1: Configuration, Secrets, and Environment Validation (Backend)

### 1.1 Environment validation at bootstrap (RED)

**RED**: Write tests
- [ ] `tests/Feature/ProductionConfigTest.php`
- [ ] Test: required production env vars present (`APP_KEY`, `DB_*`, `AWS_*` where applicable) → bootstrap fails fast if missing
- [ ] Test: `APP_DEBUG` is `false` in production-like environment
- [ ] Test: `APP_KEY` is present and not the committed example value
- [ ] Test: no hardcoded secret literals in source (grep-style assertion for known secret patterns)
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Add required env var validation in `bootstrap/app.php` or a service provider `boot()` (missing required vars → throw, fail fast)
- [ ] Ensure `APP_DEBUG` defaults to `false` and cannot be silently enabled in production
- [ ] Update `apps/api/.env.example` with required production env vars documented; no real secrets
- [ ] Run test → passes

**REFACTOR**: Verify validation does not break local dev/CI where optional vars may be absent; run full backend test suite.

---

## Phase 2: CORS, CSRF, Authentication, Authorization, Rate Limits (Backend)

### 2.1 CORS hardening (RED)

**RED**: Write tests
- [ ] `tests/Feature/CorsTest.php`
- [ ] Test: CORS preflight with configured production origin → origin reflected, credentials supported
- [ ] Test: CORS does not allow `*` origin when credentials are enabled
- [ ] Test: allowed methods/headers scoped, not wildcard where credentials used
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Update `apps/api/config/cors.php`: `allowed_origins` driven by env (`CORS_ALLOWED_ORIGINS`), never `*` with `supports_credentials => true`
- [ ] Run test → passes

### 2.2 Sanctum stateful domains + session cookies (RED)

**RED**: Write tests
- [ ] Extend `tests/Feature/ProductionConfigTest.php` or new `tests/Feature/AuthConfigTest.php`
- [ ] Test: Sanctum `stateful` domains are env-driven, not hardcoded to localhost/cloudshell only
- [ ] Test: session cookie `secure` true over HTTPS, `http_only` true, `same_site` lax/strict
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Update `apps/api/config/sanctum.php`: `stateful` from `SANCTUM_STATEFUL_DOMAINS` env
- [ ] Update `apps/api/config/session.php`: `secure` defaults true when `APP_ENV=production`; `same_site` lax/strict; `http_only` true
- [ ] Run test → passes

### 2.3 CSRF enforcement + rate limits (RED)

**RED**: Write tests
- [ ] `tests/Feature/CsrfRateLimitTest.php`
- [ ] Test: state-changing POST without CSRF → 419
- [ ] Test: state-changing POST with valid CSRF → proceeds
- [ ] Test: rate-limit headers present on login/register/upload/publish endpoints
- [ ] Test: rate limit enforced (429 after threshold) with `Retry-After`
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Verify `VerifyCsrfToken` applied to all state-changing `auth:sanctum` routes in `routes/api.php`
- [ ] Verify every state-changing route has a `throttle:` middleware in `routes/api.php`
- [ ] Confirm rate limiters registered in `AppServiceProvider::boot()` (existing; review coverage)
- [ ] Run test → passes

### 2.4 Authorization / ownership boundaries (RED)

**RED**: Write tests
- [ ] `tests/Feature/AuthorizationTest.php`
- [ ] Test: non-owner access to own media/image/clip-review → 404 (no existence leak)
- [ ] Test: upload/download endpoints enforce ownership
- [ ] Test: storage internals (`storage_key`, `bucket`, prompt) never in response bodies
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Audit controllers for user-scoped queries (`where('user_id', auth()->id())`)
- [ ] Audit `ImageController::download()` and `MediaAssetController` for ownership + sanitized 500 (existing behavior; verify via tests)
- [ ] Run test → passes

### 2.5 Sanitized error responses (RED)

**RED**: Write tests
- [ ] `tests/Feature/ErrorSanitizationTest.php`
- [ ] Test: 500 error body contains no stack trace, no storage internals, no secrets
- [ ] Test: 422/404 bodies contain no tokens or credentials
- [ ] Test: exception messages with DB passwords are sanitized before log/response
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Verify Laravel exception handler returns generic 500 in production (`APP_DEBUG=false`)
- [ ] Ensure no `storage_key`/`bucket`/prompt in `ImageResource`/download error paths (existing whitelist; verify)
- [ ] Run test → passes

**REFACTOR**: Run full backend test suite.

---

## Phase 3: Security-Sensitive Logging (Backend)

### 3.1 Logging audit + sanitization (RED)

**RED**: Write tests
- [ ] `tests/Feature/LoggingSanitizationTest.php`
- [ ] Test: a log record triggered by a publish/social operation contains no `access_token`/`refresh_token`
- [ ] Test: exception log containing a DB connection string with password is sanitized
- [ ] Test: no `storage_key`/`bucket` in log context
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Audit log call sites across `apps/api/` (grep for `Log::`, `\Log`, `logger()`, `report()`)
- [ ] Add log sanitization helper/masker for token and secret fields in log context
- [ ] Ensure production `LOG_LEVEL` is `info`/`error` (not `debug`)
- [ ] Run test → passes

**REFACTOR**: Verify no log statement in media worker job handlers leaks tokens; run full backend suite.

---

## Phase 4: Queue Reliability, Retries, Timeouts, Failed-Job Visibility, Idempotency (Backend)

### 4.1 Queue config audit (RED)

**RED**: Write tests
- [ ] `tests/Feature/QueueReliabilityTest.php`
- [ ] Test: default queue connection is database (persisted, not sync-only in prod-like)
- [ ] Test: `retry_after` configured for media worker jobs
- [ ] Test: `failed_jobs` driver/table configured; failed jobs persisted
- [ ] Test: job timeouts set for media jobs matching `config/media.php`
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Review `apps/api/config/queue.php`: `retry_after`, `after_commit`, failed driver `database-uuids`, `failed_jobs` table
- [ ] Confirm media jobs (ProcessMediaAsset, RenderMediaClip, GenerateImage, PublishTo*) set `--timeout` or `timeout` property matching config
- [ ] Run test → passes

### 4.2 Failed-job visibility (RED)

**RED**: Write tests
- [ ] Extend `tests/Feature/QueueReliabilityTest.php`
- [ ] Test: exhausted job moves to `failed_jobs` table, not silently dropped
- [ ] Test: failed job payload/exception is stored and inspectable (sanitized)
- [ ] Test: failed-job listing endpoint or Artisan command available (or document absence as accepted risk)
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Ensure `failed_jobs` migration exists (Laravel default); verify via `migrate:fresh`
- [ ] If no failed-job inspection exists, either add a lightweight admin endpoint or document as accepted risk in release checklist
- [ ] Run test → passes

### 4.3 Retry behavior + idempotency (RED)

**RED**: Write tests
- [ ] Extend `tests/Feature/QueueReliabilityTest.php` + job-specific tests
- [ ] Test: failed job retries with backoff; exhausted → failed_jobs
- [ ] Test: `ProcessMediaAsset` idempotent (skip-if-not-pending / unique claim)
- [ ] Test: `RenderMediaClip` idempotent (no duplicate DerivedAsset on re-dispatch)
- [ ] Test: `GenerateImage` idempotent (no duplicate image on re-dispatch)
- [ ] Test: `PublishTo*` idempotent (uniqueId + pending-skip; no duplicate publication)
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Verify idempotency guards in each job (existing M3–M9 behavior; verify via tests, fix gaps)
- [ ] Ensure worker subprocess failure (FFmpeg timeout, MinIO unavailability) → durable sanitized failure, work not lost
- [ ] Run test → passes

**REFACTOR**: Extract shared idempotency/retry pattern if duplicated; run full backend suite.

---

## Phase 5: Health / Readiness Checks (Backend)

### 5.1 Health endpoint enhancement (RED)

**RED**: Write tests
- [ ] `tests/Feature/HealthReadinessTest.php`
- [ ] Test: `GET /api/v1/health` returns 200 with `status`, `dependencies` (database, queue, storage)
- [ ] Test: database disconnected → 503, status degraded, no stack trace/internals
- [ ] Test: storage unreachable → degraded status, no `storage_key`/`bucket` leaked
- [ ] Test: queue unreachable → degraded status, no internals
- [ ] Test: response contains no credentials, tokens, or stack traces
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Enhance `GET /api/v1/health` in `routes/api.php` to check database, queue connection, storage disk
- [ ] Add `GET /api/v1/readiness` (or readiness field in health) returning aggregated dependency readiness
- [ ] Ensure endpoints are unauthenticated but return only safe aggregated status
- [ ] Run test → passes

**REFACTOR**: Extract dependency checkers into a small service/class; verify no internals leaked; run full backend suite.

---

## Phase 6: Migration/Rollback, Backup/Restore, Storage Lifecycle, Data Retention (Docs + Tests)

### 6.1 Migration/rollback tests (RED)

**RED**: Write tests
- [ ] `tests/Feature/MigrationRollbackTest.php`
- [ ] Test: `php artisan migrate:fresh --env=testing` succeeds (schema loads cleanly)
- [ ] Test: `php artisan migrate:rollback --env=testing` succeeds (migrations reverse)
- [ ] Test: destructive migrations are guarded/flagged
- [ ] Run test → fails

**GREEN**: Implement
- [ ] Verify all migrations are reversible; add `down()` methods where missing
- [ ] Document migration strategy (run before deploy, rollback procedure, destructive migration guard) in `docs/runbooks/deployment.md`
- [ ] Run test → passes

### 6.2 Backup/restore, storage lifecycle, data retention docs

- [ ] Document PostgreSQL backup frequency, point-in-time recovery in `docs/runbooks/backup-restore.md`
- [ ] Document object storage versioning and media retention policy
- [ ] Document storage lifecycle: cleanup of orphaned uploads, derived asset retention
- [ ] Document data retention: user data, sessions, failed jobs, log retention
- [ ] Record any gaps as accepted risks with owner and rationale in `docs/known-limitations.md`

**REFACTOR**: Cross-reference docs with spec acceptance criteria; ensure every finding has a status (fixed / blocker / accepted risk).

---

## Phase 7: Deployment, Rollback, Runbooks, Incident Response, Release Checklist (Docs)

- [ ] `docs/runbooks/deployment.md`: deploy procedure for API, frontend, worker, PostgreSQL, object storage, queue; health-check gate before/after deploy
- [ ] `docs/runbooks/rollback.md`: rollback procedure (code, database, job draining)
- [ ] `docs/runbooks/incident-response.md`: severity levels, on-call escalation, incident timeline template, post-mortem requirements
- [ ] `docs/release-checklist.md`: pre-deploy (migrations, backups, feature flags), deploy (health checks, smoke test), post-deploy (monitoring, rollback criteria)
- [ ] `docs/known-limitations.md`: known limitations from `docs/project-state.md` with workarounds and owners
- [ ] These docs are created by Builder (not Planner); Planner specifies them here for traceability

**REFACTOR**: Verify docs are internally consistent with spec and project-state.md.

---

## Phase 8: End-to-End Smoke Test (Frontend / Playwright)

### 8.1 Smoke test spec (RED)

**RED**: Write test
- [ ] `apps/web/tests/e2e/production-smoke.spec.ts`
- [ ] Test: register → login → create project → upload media → processing completes → clip review → approve → publish → verify download (critical flow)
- [ ] Test: no tokens in browser DevTools / network payloads
- [ ] Test: console cleanliness (no unexpected JS errors)
- [ ] Test: network cleanliness (no unexpected 4xx/5xx unless scenario requires)
- [ ] Run test at 3 viewports → fails (implementation not yet present / smoke test not run)

**GREEN**: Implement
- [ ] The smoke test exercises existing UI (no new components); it runs against a production-like environment (real PostgreSQL, MinIO, queue worker, social fakes)
- [ ] Social platform calls use deterministic fakes (no real credentials, no network)
- [ ] Run test at 3 viewports → passes

**REFACTOR**: Consolidate shared setup/helpers; verify responsiveness at all viewports; run lint/build.

---

## Phase 9: Governance, Lint, Final Verification

### 9.1 Lint & build

- [ ] `vendor/bin/pint --dirty --format agent` (backend) → clean
- [ ] `npm run lint` (frontend, eslint) → clean
- [ ] `npm run build` (frontend) → clean

### 9.2 Governance

- [ ] `php artisan test --filter=Governance` → all pass

### 9.3 Full test execution

| Step | Command | Target |
|------|---------|--------|
| Backend unit + feature | `php artisan test --compact` (apps/api) | all pass |
| Frontend unit | `npm run test` (apps/web) | all pass |
| E2E | `npm run test:e2e` (apps/web) | all pass at 3 viewports |
| Governance | `php artisan test --filter=Governance` | all pass |
| Backend lint | `vendor/bin/pint --dirty --format agent` | clean |
| Frontend lint | `npm run lint` | clean |
| Frontend build | `npm run build` | clean |

### 9.4 Evidence & handoff

- [ ] Update `specs/123-production-hardening/evidence.md` (Orchestrator commits — Planner/Builder do not edit evidence.md)
- [ ] Record all findings: fixed, release blocker, or accepted risk with owner and rationale
- [ ] Independent Tester approval; CI green; merge via `scripts/merge_gate.py`

---

## Phase 10: CI Correction — Backend SocialPublish Fix

### 10.1 Investigate root cause (RED)

- [ ] Reproduce the 13 failing `Tests\Feature\SocialPublish\` tests against CI-like PostgreSQL environment.
- [ ] Confirm root cause: SocialPublish publish jobs end with status `failed` instead of `published` when the fake publisher succeeds.
- [ ] Confirm `SocialConnectionManager::getValidAccessToken()` behavior in testing env returns a valid token for connected social connections.
- [ ] Document root cause in plan notes (no code change yet).

### 10.2 Implement fix (GREEN)

- [ ] Fix SocialPublish publish flow so a successful fake publisher marks the publish `published` (terminal status), not `failed`.
- [ ] Ensure `SocialConnectionManager::getValidAccessToken()` returns a valid token in testing env.
- [ ] Add/adjust backend tests asserting publish flow ends `published`.
- [ ] Run `php artisan test --filter=SocialPublish` → 13/13 pass.
- [ ] Run full backend suite `php artisan test --compact` → 0 failures.

### 10.3 REFACTOR

- [ ] Refactor for clarity without changing terminal-status behavior.
- [ ] Re-run SocialPublish tests → still 13/13 pass.

---

## Phase 11: CI Correction — Frontend E2E Fix

### 11.1 Investigate root cause (RED)

- [ ] Reproduce the 73 failing E2E tests across auth, clip-review, image-studio, media, projects at 3 viewports (390x844, 768x1024, 1440x900).
- [ ] Inspect browser console, network, API responses, and screenshots for the failure pattern.
- [ ] Confirm root cause of E2E instability/failures.
- [ ] Document root cause in plan notes (no code change yet).

### 11.2 Implement fix (GREEN)

- [ ] Fix E2E flows so auth, media, clip review, image studio, and projects pass at all 3 viewports.
- [ ] Ensure clean console/network (no unexpected JS errors or 4xx/5xx).
- [ ] Run `npm run test:e2e` → 0 failures at all 3 viewports.

### 11.3 REFACTOR

- [ ] Refactor E2E helpers/selectors for stability without changing flow behavior.
- [ ] Re-run E2E at all 3 viewports → still 0 failures.

---

## Phase 12: Full Definition of Done Verification

- [ ] Backend lint `vendor/bin/pint --dirty --format agent` → clean.
- [ ] Frontend lint `npm run lint` → clean.
- [ ] Frontend build `npm run build` → clean.
- [ ] Governance `php artisan test --filter=Governance` → all pass.
- [ ] Full backend suite `php artisan test --compact` → 0 failures.
- [ ] Full frontend unit suite `npm run test` → all pass.
- [ ] Full E2E `npm run test:e2e` → 0 failures at 3 viewports.
- [ ] Independent Tester approval; CI green; merge via `scripts/merge_gate.py`.
- [ ] Update `specs/123-production-hardening/evidence.md` (Orchestrator-owned; Planner/Builder do not edit).

---

## File Creation Summary

### Backend (new tests)
| File | Purpose |
|------|---------|
| `apps/api/tests/Feature/ProductionConfigTest.php` | env validation, APP_DEBUG, APP_KEY |
| `apps/api/tests/Feature/CorsTest.php` | CORS preflight/origin/credentials |
| `apps/api/tests/Feature/AuthConfigTest.php` | Sanctum stateful domains, session cookies |
| `apps/api/tests/Feature/CsrfRateLimitTest.php` | CSRF enforcement, rate-limit headers/429 |
| `apps/api/tests/Feature/AuthorizationTest.php` | ownership 404, upload/download boundaries |
| `apps/api/tests/Feature/ErrorSanitizationTest.php` | sanitized 500/422/404 bodies |
| `apps/api/tests/Feature/LoggingSanitizationTest.php` | no tokens/secrets/storage internals in logs |
| `apps/api/tests/Feature/QueueReliabilityTest.php` | queue config, failed jobs, retries, idempotency |
| `apps/api/tests/Feature/HealthReadinessTest.php` | health/readiness checks, no internals leaked |
| `apps/api/tests/Feature/MigrationRollbackTest.php` | migrate:fresh, migrate:rollback |

### Backend (config review — modified only as needed)
| File | Change |
|------|--------|
| `apps/api/config/cors.php` | env-driven allowed origins, no `*` with credentials |
| `apps/api/config/sanctum.php` | env-driven stateful domains |
| `apps/api/config/session.php` | secure/http_only/same_site production defaults |
| `apps/api/config/logging.php` | production log level/retention |
| `apps/api/config/queue.php` | retry/timeout/failed-job review |
| `apps/api/config/app.php` | APP_DEBUG production guard |
| `apps/api/.env.example` | required env vars documented, no real secrets |
| `apps/api/routes/api.php` | health/readiness route(s) |

### Frontend (new tests)
| File | Purpose |
|------|---------|
| `apps/web/tests/e2e/production-smoke.spec.ts` | Playwright smoke test at 3 viewports |

### Documentation (created by Builder/Orchestrator)
| File | Purpose |
|------|---------|
| `docs/production-hardening.md` | production-readiness checklist + evidence |
| `docs/runbooks/deployment.md` | deploy/rollback procedure |
| `docs/runbooks/incident-response.md` | incident response |
| `docs/runbooks/backup-restore.md` | backup/restore expectations |
| `docs/known-limitations.md` | known limitations with owners/rationale |
| `docs/release-checklist.md` | pre/deploy/post-deploy checklist |

---

## Branch & Commit Strategy

**Branch**: `@carlosegoulart/123/feat/production-hardening-v2`

**Commits** (Conventional Commits, each `Refs #123`):

1. `chore(hardening): audit config, secrets, and env validation`
2. `fix(hardening): harden CORS, CSRF, session, and rate limits`
3. `fix(hardening): sanitize security-sensitive logging`
4. `fix(hardening): improve queue reliability, retries, idempotency, failed-job visibility`
5. `feat(hardening): add health and readiness checks`
6. `docs(hardening): add deployment, rollback, runbooks, incident response, release checklist`
7. `test(hardening): add production smoke test (Playwright, 3 viewports)`
8. `test(hardening): add backend hardening tests (config, auth, queue, health, migration)`
9. `ci(hardening): lint, governance, and full suite green`
10. `fix(backend): fix SocialPublish publish terminal status to published`
11. `fix(frontend): fix E2E flows at all 3 viewports`
