# M14 — Production Hardening Specification

## Overview

This specification defines M14 "Production Hardening" — an audit-and-hardening effort that prepares AiClip for a controlled production release by validating operational readiness, security, reliability, observability, and recovery behavior across the implemented features (M0–M13 mainline).

M14 does not add user-facing features. It hardens the existing platform: production configuration, secret management, environment validation, CORS/CSRF/authentication/authorization, rate limits, security-sensitive logging, queue reliability, retries, timeouts, failed-job visibility, idempotency, storage/worker failure handling, health/readiness checks, migration/rollback strategy, backup/restore expectations, storage lifecycle, data retention, deployment/rollback procedures, runbooks, incident response, and a release checklist. It concludes with a focused end-to-end smoke test of critical user flows in a production-like environment.

Acceptance criteria are risk-based: every checklist item must either be fixed or recorded as an explicit release blocker / accepted risk with owner and rationale.

## Description

Goal: validate that AiClip is operationally ready for a controlled production release, fix defects found by the audit, document operational procedures, and record any residual accepted risks with owners.

Why it is needed: M0–M13 delivered features but no production-readiness audit. Before a controlled release, the team must confirm secrets are externalized, authentication/authorization boundaries hold, rate limits protect endpoints, queue failures are observable and recoverable, health checks exist, deployment/rollback/backup/restore procedures are documented, and critical flows pass a smoke test in a production-like environment.

Scope boundaries:
- Only implemented M0–M13 features are in scope (auth, projects, media, processing, clip review, image studio, social connections, publishing). No new features.
- No infrastructure redesign or speculative migrations (AGENTS.md §25 out-of-scope).
- No changes to agent permissions/governance without explicit authorization.
- Real platform credentials are never used in CI/tests; fakes are used instead.
- Secrets are never committed, returned to clients, or written to logs.

---

## Technical Tasks

### Phase A — Configuration, Secrets, and Environment Validation

- [ ] Audit `.env.example` and production configuration for hardcoded secrets, example keys, and debug flags.
- [ ] Add required production environment variables with validation at bootstrap (missing required vars fail fast, do not silently fall back to insecure defaults).
- [ ] Ensure `APP_DEBUG` is forced `false` in production and cannot be enabled via env without explicit override guard.
- [ ] Ensure `APP_KEY` is not committed to Git and is generated per deployment environment.
- [ ] Audit all `env()` reads for secrets; ensure no secret is returned in API responses, error messages, or logs.
- [ ] Add a configuration audit test asserting required env vars are present and non-default in production-like test environment.

### Phase B — CORS, CSRF, Authentication, Authorization, Rate Limits

- [ ] Audit CORS configuration: production origins must be explicit, never `*` when credentials are supported; allowed methods/headers scoped.
- [ ] Audit Sanctum `stateful` domains: production frontend domain(s) must be configurable via env, not hardcoded to localhost/cloudshell.
- [ ] Audit session cookie settings: `secure`, `http_only`, `same_site` must be production-safe (secure over HTTPS, same_site strict/lax as appropriate).
- [ ] Verify CSRF protection (`VerifyCsrfToken`) is applied to all state-changing routes under `auth:sanctum`.
- [ ] Verify every state-changing endpoint has a rate limiter; review limits for appropriateness (auth, upload, publish, social).
- [ ] Verify authorization: all user-scoped queries are filtered by `auth()->id()`; non-owner access returns 404 (no existence leak).
- [ ] Verify upload/download boundaries: media and image download endpoints enforce ownership and do not leak storage internals (`storage_key`, `bucket`, prompt).
- [ ] Verify error responses are sanitized: no stack traces, no storage internals, no tokens/secrets in 500/422/404 bodies.
- [ ] Add backend tests for CORS preflight, CSRF enforcement, rate-limit headers, ownership 404, and sanitized error responses.

### Phase C — Security-Sensitive Logging

- [ ] Audit all log call sites across `apps/api/` for secrets, tokens, credentials, PII, and storage internals (`storage_key`, `bucket`).
- [ ] Add log sanitization: mask or exclude `access_token`, `refresh_token`, API secrets, and DB credentials from log context.
- [ ] Ensure error logs never include exception messages that embed secrets (e.g., DB connection strings with passwords).
- [ ] Add a test asserting a sample log record contains no token/secret values.
- [ ] Define log retention and level configuration for production (e.g., `LOG_LEVEL=info`/`error`, rotation).

### Phase D — Queue Reliability, Retries, Timeouts, Failed-Job Visibility, Idempotency

- [ ] Audit queue configuration: default connection, retry_after, failed_jobs driver/table, `after_commit`.
- [ ] Verify failed jobs are persisted and visible (failed_jobs table); add a failed-jobs listing/inspection capability if absent.
- [ ] Verify job timeouts are set for media worker jobs (probe, extract, transcribe, scene detect, render, image generation) matching `config/media.php` timeouts.
- [ ] Verify retry behavior: failed jobs retry with backoff; exhausted jobs move to failed_jobs, not silently dropped.
- [ ] Verify idempotency of existing jobs (ProcessMediaAsset, RenderMediaClip, GenerateImage, PublishTo*, SocialPublish) — skip-if-not-pending / uniqueId semantics.
- [ ] Verify storage/worker failure handling: worker subprocess failure, FFmpeg timeout, MinIO/S3 unavailability produce durable, sanitized failures; work is not silently lost.
- [ ] Add backend tests for job timeout, retry exhaustion, failed-job persistence, idempotent skip, and worker failure recovery.

### Phase E — Health / Readiness Checks and Operational Diagnostics

- [ ] Enhance `GET /api/v1/health` to include dependency checks (database, queue, storage) with degraded/ok status.
- [ ] Add `GET /api/v1/readiness` (or extend health) that reports dependency readiness without exposing internals (no stack traces, no storage keys, no credentials).
- [ ] Ensure health endpoints are unauthenticated but return only safe, aggregated status.
- [ ] Add backend tests for health/readiness: success path, database disconnected → 503, storage unreachable → degraded, no internals leaked.
- [ ] Document what each health field means and how operators should interpret degraded status.

### Phase F — Migration/Rollback, Backup/Restore, Storage Lifecycle, Data Retention

- [ ] Document database migration strategy: run before deploy, rollback procedure, destructive migration guard.
- [ ] Document backup/restore expectations: PostgreSQL backup frequency, point-in-time recovery, object storage versioning.
- [ ] Document storage lifecycle: media retention policy, cleanup of orphaned uploads, derived asset retention.
- [ ] Document data retention: user data, sessions, failed jobs, log retention.
- [ ] Add a migration test verifying `migrate:fresh` and `migrate:rollback` succeed on the test schema.

### Phase G — Deployment, Rollback, Runbooks, Incident Response, Release Checklist

- [ ] Document deployment procedure for API, frontend, worker, and supporting services (PostgreSQL, object storage, queue).
- [ ] Document rollback procedure: code rollback, database rollback, job draining, health-check gate before/after deploy.
- [ ] Document incident response: severity levels, on-call escalation, incident timeline template, post-mortem requirements.
- [ ] Document known limitations from `docs/project-state.md` with workarounds and owners.
- [ ] Create a release checklist: pre-deploy (migrations, backups, feature flags), deploy (health checks, smoke test), post-deploy (monitoring, rollback criteria).
- [ ] These documents live in `docs/` (e.g., `docs/production-hardening.md`, `docs/runbooks/`, `docs/incident-response.md`) — referenced from spec but created by Builder/Orchestrator per AGENTS.md.

### Phase H — End-to-End Smoke Test

- [ ] Define critical user flows for smoke test: register → login → create project → upload media → wait for processing → view clip review → approve → publish to connected platform → verify download.
- [ ] Implement Playwright smoke test at 3 viewports (390x844, 768x1024, 1440x900) against a production-like environment (real PostgreSQL, MinIO/S3, queue worker).
- [ ] Smoke test must pass with deterministic fakes for social platforms (no real credentials, no network).
- [ ] Smoke test asserts console/network cleanliness and no unexpected 4xx/5xx.

### Phase I — Governance, Lint, Final Verification

- [ ] Run backend Pint lint → clean.
- [ ] Run frontend ESLint → clean; frontend build → clean.
- [ ] Run governance tests → all pass.
- [ ] Full backend test suite → all pass; full frontend unit suite → all pass; E2E → all pass at 3 viewports.
- [ ] Independent Tester approval; CI green; merge via `scripts/merge_gate.py`.

---

## Acceptance Criteria

- [ ] A risk-based production-readiness checklist is completed with evidence for each item (config, secrets, auth, CORS, rate limits, logging, queue, health, migrations, backups, deployment, runbooks, smoke test).
- [ ] Secrets are externalized and never committed, returned to clients, or written to logs.
- [ ] `APP_DEBUG` is `false` in production; required env vars validated at bootstrap; missing required vars fail fast.
- [ ] Authentication/authorization, upload/download boundaries, rate limits, and error responses are reviewed and tested; no existence leaks; sanitized error bodies.
- [ ] Queue and provider failures have observable, recoverable outcomes and do not silently lose work (failed jobs visible, retries, timeouts, idempotency).
- [ ] Health/readiness checks exist and return safe, aggregated status without exposing internals.
- [ ] Deployment, rollback, backup/restore, incident runbooks, and release checklist are documented.
- [ ] Critical smoke tests pass in a production-like environment at 3 viewports.
- [ ] All findings are either fixed or recorded as explicit release blockers / accepted risks with owner and rationale.
- [ ] Unit tests pass, integration tests pass, E2E tests pass at 3 viewports, lint clean, governance green.
- [ ] Independent Tester approval and all required CI checks green before merge.
- [ ] Backend CI `php artisan test --compact` → 0 failures (SocialPublish publish flow ends with status `published`, not `failed`)
- [ ] E2E CI `npm run test:e2e` → 0 failures at all 3 viewports (390x844, 768x1024, 1440x900)
- [ ] All existing M14 hardening acceptance criteria remain satisfied; CI correction scope adds no new user-facing features

---

## Out of Scope

- Unapproved infrastructure redesign or speculative migrations (AGENTS.md §25).
- New user-facing features (no new endpoints, models, or UI beyond hardening tests/docs).
- Changes to agent permissions/governance without explicit authorization.
- Real platform credentials or real model downloads in CI (fakes only).
- Multi-region deployment, auto-scaling configuration, or managed-service provisioning.
- Email verification (not implemented; remains a known limitation).
- Real S3/AWS credentials in tests (MinIO/fakes only).
- Payment processing, subscriptions, or billing.

---

## Security Considerations

### Secret Management

- All secrets (`APP_KEY`, `DB_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, social OAuth client secrets, `SESSION_ENCRYPT` keys) must come from environment variables only.
- `.env` files are gitignored (`apps/api/.gitignore` includes `.env`, `.env.backup`, `.env.production`); `.env.example` must contain no real secrets.
- No secret may appear in API responses, error bodies, log records, browser DevTools, or network payloads.
- Social OAuth tokens remain encrypted at rest (M9 `encrypted` cast) and are never returned to the frontend.

### Authentication & Authorization

- All state-changing endpoints require `auth:sanctum` + CSRF.
- Sanctum `stateful` domains must be production-configured, not hardcoded to dev origins.
- Session cookies: `secure` over HTTPS, `http_only`, `same_site` lax/strict.
- All data access scoped to `auth()->id()`; non-owner returns 404 (no existence leak).
- Upload/download endpoints enforce ownership; storage internals never leak.

### Rate Limiting

- Every state-changing endpoint has a rate limiter with per-user or per-IP fallback.
- Auth endpoints (login/register) rate-limited to prevent brute force.
- Rate-limit headers (`Retry-After`, `X-RateLimit-*`) present where applicable.

### Security-Sensitive Logging

- Log context excludes `access_token`, `refresh_token`, API secrets, DB credentials, `storage_key`, `bucket`, prompts.
- Exception messages containing secrets are sanitized before logging.
- Production `LOG_LEVEL` set to `info`/`error` (not `debug`) to avoid verbose secret leakage.

### CI Safety

- No real platform API credentials in CI; deterministic fakes only.
- No multi-gigabyte model downloads; `MEDIA_CLIP_RANKING_PROVIDER=fake` in CI.
- No secrets in fixtures committed to Git (AGENTS.md §23).

---

## UX Considerations

- Health/readiness endpoints are machine-readable (JSON) for operators/monitoring; no UI changes required.
- Error messages shown to users are actionable and do not expose internals (e.g., "Reconnect TikTok" rather than raw OAuth error).
- No new frontend components; smoke test exercises existing UI.
- Accessibility of existing UI is not regressed by this issue (Playwright console/accessibility checks retained).

---

## Test Scenarios

### Backend Unit Tests (Pest)

| # | Scenario | Expected Result |
|---|----------|-----------------|
| A.1 | Required production env vars present | bootstrap fails fast if missing |
| A.2 | `APP_DEBUG` false in production-like env | `config('app.debug')` is `false` |
| A.3 | `APP_KEY` not committed / not default | key present and not the example value |
| B.1 | CORS preflight with production origin | allowed origin reflected, credentials supported |
| B.2 | CORS with `*` origin and credentials | rejected / not allowed |
| B.3 | CSRF missing on state-changing POST | 419 |
| B.4 | Rate-limit headers on login | 429 after threshold, `Retry-After` present |
| B.5 | Non-owner access to media/image | 404 (no existence leak) |
| B.6 | 503/500 error body sanitized | no stack trace, no storage internals, no secrets |
| C.1 | Log record contains no token/secret | no `access_token`/`refresh_token` in log |
| C.2 | Exception log sanitized | no DB password in logged message |
| D.1 | Job retry exhaustion → failed_jobs | job moved to failed_jobs, not dropped |
| D.2 | Idempotent job skip | re-dispatch of completed job is skipped |
| D.3 | Worker subprocess failure → durable failure | sanitized error stored, work not lost |
| E.1 | Health endpoint success | 200, status ok, dependencies listed |
| E.2 | Health endpoint DB disconnected | 503, status degraded, no internals |
| E.3 | Health endpoint storage unreachable | degraded status, no storage keys leaked |
| F.1 | `migrate:fresh` succeeds | test schema loads cleanly |
| F.2 | `migrate:rollback` succeeds | migrations reverse cleanly |

### Backend Feature / Integration Tests

- Full smoke-flow integration: upload → processing → clip review → approve → publish → download, with fakes.
- Failed-job visibility: list failed jobs, inspect payload/exception (sanitized).
- Idempotency: duplicate render/publish job does not duplicate output.

### Frontend Unit Tests (Vitest)

- Smoke-test supporting assertions: API client error classification, no tokens in response handling.
- Existing Vitest suites must remain green (no regressions).

### E2E Tests (Playwright, 3 viewports: 390x844, 768x1024, 1440x900)

| # | Scenario | Expected Result |
|---|----------|-----------------|
| H.1 | Register → login → create project → upload media → processing completes | media asset processed, DerivedAsset created |
| H.2 | View clip review → approve → publish to connected platform | campaign/publish succeeds, status published |
| H.3 | Publish failure → retry → success | retry recovers, no duplicate |
| H.4 | No tokens in browser DevTools / network | no `access_token`/`refresh_token` in payloads |
| H.5 | Console cleanliness | no unexpected JS console errors |
| H.6 | Network cleanliness | no unexpected 4xx/5xx unless scenario requires |

### CI Correction Test Scenarios

#### Backend — SocialPublish CI Fix

| # | Scenario | Expected Result |
|---|----------|-----------------|
| CC.B.1 | SocialPublish publish flow ends with status `published` (not `failed`) | `PublishToYouTube`/`PublishToInstagram`/`PublishToTikTok` job marks the publish `published` when the fake publisher succeeds |
| CC.B.2 | `SocialConnectionManager::getValidAccessToken()` returns valid token in testing env | valid access token returned for a connected social connection in the testing environment |
| CC.B.3 | Backend CI `php artisan test --compact` → 0 failures | all backend tests pass, including the 13 SocialPublish tests |

#### Frontend — E2E CI Fix

| # | Scenario | Expected Result |
|---|----------|-----------------|
| CC.F.1 | E2E auth flow passes at 3 viewports | register → login → logout succeeds at 390x844, 768x1024, 1440x900 |
| CC.F.2 | E2E media flow passes at 3 viewports | upload media / processing completes at all viewports |
| CC.F.3 | E2E clip review flow passes at 3 viewports | view clip review → approve succeeds at all viewports |
| CC.F.4 | E2E image studio flow passes at 3 viewports | image generation / approval succeeds at all viewports |
| CC.F.5 | E2E projects flow passes at 3 viewports | create / list / delete project succeeds at all viewports |
| CC.F.6 | Clean console/network at all viewports | no unexpected JS console errors or 4xx/5xx at any viewport |
| CC.F.7 | E2E CI `npm run test:e2e` → 0 failures at all 3 viewports | all 69 E2E tests pass at 390x844, 768x1024, 1440x900 |

---

## CI Requirements

### Backend Lint
- `vendor/bin/pint --dirty --format agent` → clean

### Frontend Lint & Build
- `npm run lint` → clean
- `npm run build` → clean

### Governance Tests
- `php artisan test --filter=Governance` → all pass

### Full Test Execution (Definition of Done)

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

## CI Correction Scope

Issue #123 (M14 Production Hardening) now also includes fixing the pre-existing CI failures that block its merge. These failures existed before the M14 hardening work and must be resolved so issue #123 can reach CI green and merge (AGENTS.md §11).

### Backend CI Correction

**Problem:** 13 tests in `Tests\Feature\SocialPublish\` fail because SocialPublish publish jobs end with status `failed` instead of `published`. The publish flow does not reach the `published` terminal state when the fake publisher succeeds.

**Scope:** Investigate root cause of the `failed` terminal status in the SocialPublish publish flow (jobs `PublishToYouTube`, `PublishToInstagram`, `PublishToTikTok`, `SocialConnectionManager::getValidAccessToken()` in testing env) and implement the minimal code/test fix so all 13 SocialPublish backend tests pass.

### Frontend CI Correction

**Problem:** 69 E2E tests fail across auth, clip-review, image-studio, media, and projects flows. E2E flows do not pass reliably at the required viewports.

**Scope:** Investigate root cause of the E2E failures and implement the minimal code/test fix so all 69 E2E tests pass at all 3 viewports (390x844, 768x1024, 1440x900) with clean console/network.

### Out of Scope (CI Correction)

- CI workflow configuration files (`.github/workflows/*.yml`) are not modified; CI correction addresses application/test behavior so existing CI passes unchanged.
- Local environment infrastructure (pdo_pgsql PHP extension, running PostgreSQL, shell access) — CI correction scope covers test behavior; the CI environment provides database services.
- New user-facing features beyond hardening and CI correction.
- Multi-region deployment, auto-scaling, or managed-service provisioning.

---

## Test Data & Fixtures

- Use deterministic fakes for social platforms (`FakeYouTubePublisher`, `FakeInstagramPublisher`, `FakeTikTokPublisher`, `FakeImageGenerationProvider`).
- Queue: sync driver in tests so jobs run inline; database queue for failed-job visibility tests.
- Use existing factories: `User`, `SocialConnection`, `ClipReview`, `MediaAsset`, `DerivedAsset`, `Image`, `SocialPublish`.
- No secrets in fixtures committed to Git.
- E2E needs prepared PostgreSQL, installed Chromium browsers, and free ports 5173/8000 (AGENTS.md Known Limitations).

### Environment Guards
- CI uses deterministic fakes; no real platform API credentials, no network calls to platforms.
- Production-like smoke test runs against real PostgreSQL + MinIO + queue worker with social fakes.
- `.env` never inspected or committed (AGENTS.md Planner boundary).

---

## Dependencies

- M0–M13 implemented features (auth, projects, media, processing, clip review, image studio, social connections, publishing).
- Laravel Sanctum authentication (existing).
- Laravel Queue + database failed_jobs table (existing).
- S3-compatible storage (MinIO dev, AWS S3 prod) (existing).
- `config/media.php` timeouts (existing).
- `config/social.php` provider configuration (existing).
- Playwright E2E infrastructure (existing).
- `scripts/merge_gate.py` for merge governance (existing).

---

## Files Touched Summary

The Planner does not create production code. This section lists the categories of files the Builder is expected to touch, for planning purposes only.

### Backend (config/tests/docs)
- `apps/api/config/cors.php` — production origins scoped
- `apps/api/config/sanctum.php` — stateful domains env-driven
- `apps/api/config/session.php` — secure/same_site cookie defaults
- `apps/api/config/logging.php` — production log level/retention
- `apps/api/config/queue.php` — retry/timeout/failed-job review
- `apps/api/config/app.php` — APP_DEBUG production guard
- `apps/api/.env.example` — required env vars documented, no real secrets
- `apps/api/routes/api.php` — health/readiness route(s), CSRF/rate-limit review
- `apps/api/tests/Feature/HealthTest.php` — extended health/readiness tests
- New test files for config audit, CORS, CSRF, rate limits, logging sanitization, queue reliability, migration rollback

### Frontend (tests)
- `apps/web/tests/e2e/production-smoke.spec.ts` — Playwright smoke test (3 viewports)

### Documentation (created by Builder/Orchestrator, referenced here)
- `docs/production-hardening.md` — production-readiness checklist + evidence
- `docs/runbooks/deployment.md` — deploy/rollback procedure
- `docs/runbooks/incident-response.md` — incident response
- `docs/runbooks/backup-restore.md` — backup/restore expectations
- `docs/known-limitations.md` — known limitations with owners/rationale
- `docs/release-checklist.md` — pre/deploy/post-deploy checklist

---

## Branch & Commit Strategy

**Branch**: `@carlosegoulart/123/feat/production-hardening-v2` (created by Orchestrator).

**Commits** (Conventional Commits, each referencing `Refs #123`):

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
