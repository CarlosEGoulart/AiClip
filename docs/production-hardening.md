# M14 — Production Hardening

Production readiness checklist and operational runbooks for AiClip release.

## Overview

This document captures the production-hardening work completed for issue #123,
covering configuration, security, queue reliability, observability, and
disaster recovery across the implemented M0–M14 feature surface.

## Phases

| Phase | Area | Status |
|-------|------|--------|
| 1 | Configuration, secrets, environment validation | Complete |
| 2 | CORS, CSRF, session, rate limits | Complete |
| 3 | Security-sensitive logging | Complete |
| 4 | Queue reliability, retries, idempotency | Complete |
| 5 | Health/readiness checks | Complete |
| 6 | Migration/rollback strategy | Complete |
| 7 | Deployment/runbooks/incident response/release checklist | Complete |
| 8 | E2E smoke test | Complete |

## Backend Changes

- `app/Services/ProductionEnvironmentValidator.php` — fail-fast env validation in production
- `app/Log/SanitizeLogProcessor.php` — Monolog processor masking tokens, secrets, keys, passwords
- `app/Providers/AppServiceProvider.php` — env validation hook, rate limiters
- `config/app.php` — APP_DEBUG forced false in production
- `config/cors.php` — env-driven allowed origins, scoped methods/headers
- `config/session.php` — secure cookies in production
- `config/logging.php` — LOG_LEVEL default `error`
- `routes/api.php` — CSRF + throttle on state-changing routes, `/readiness` endpoint
- `app/Jobs/ProcessMediaAsset.php`, `RenderMediaClip.php`, `GenerateImage.php` — `$timeout` + `uniqueId()`

## Test Coverage

- `tests/Feature/ProductionConfigTest.php`
- `tests/Feature/CorsTest.php`
- `tests/Feature/AuthConfigTest.php`
- `tests/Feature/CsrfRateLimitTest.php`
- `tests/Feature/AuthorizationTest.php`
- `tests/Feature/ErrorSanitizationTest.php`
- `tests/Feature/LoggingSanitizationTest.php`
- `tests/Feature/QueueReliabilityTest.php`
- `tests/Feature/HealthReadinessTest.php`
- `tests/Feature/MigrationRollbackTest.php`

## E2E

- `apps/web/tests/e2e/production-smoke.spec.ts` — critical flow smoke test at 3 viewports

## Runbooks

- `docs/runbooks/deployment.md`
- `docs/runbooks/rollback.md`
- `docs/runbooks/incident-response.md`
- `docs/runbooks/backup-restore.md`
- `docs/known-limitations.md`
- `docs/release-checklist.md`

## Acceptance Criteria

- Production-readiness checklist completed with evidence
- Secrets externalized, never committed or returned to clients
- AuthN/AuthZ, rate limits, error responses tested
- Queue failures observable and recoverable
- Health checks documented and validated
- Smoke tests pass in production-like environment
- Findings fixed or recorded as release blockers/accepted risks
