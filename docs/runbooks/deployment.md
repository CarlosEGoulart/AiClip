# Deployment Runbook

## Overview

Deployment procedure for AiClip production release.

## Pre-Deployment Checklist

- [ ] All CI checks green (backend, frontend, E2E, governance, pr-enforcement)
- [ ] Tester approved via `specs/123-production-hardening/evidence.md`
- [ ] `.env.example` reviewed; production secrets externalized
- [ ] `APP_DEBUG=false` confirmed in production config
- [ ] `LOG_LEVEL=error` confirmed in production config
- [ ] Migration files reviewed for reversibility (`down()` methods present)
- [ ] Queue jobs have `$timeout` and `uniqueId()` for idempotency

## Deployment Steps

1. Verify target environment has required env vars (see `.env.example`)
2. Run `composer install --no-dev --optimize-autoloader`
3. Run `php artisan migrate --force`
4. Clear and cache config: `php artisan config:clear && php artisan config:cache`
5. Clear and cache routes: `php artisan route:clear && php artisan route:cache`
6. Restart queue workers: `php artisan queue:restart`
7. Restart web server (PHP-FPM, Octane, or equivalent)
8. Verify `/api/v1/health` returns 200
9. Verify `/api/v1/readiness` returns all checks passing
10. Run E2E smoke test against production-like environment

## Rollback Triggers

- Any CI check red
- `/api/v1/health` returns non-200
- `/api/v1/readiness` shows degraded dependencies
- E2E smoke test failures
- Database migration errors
- Queue worker failures exceeding threshold

## Rollback Procedure

1. Stop web server and queue workers
2. Revert code to previous release tag/commit
3. Run `php artisan migrate:rollback --step=1` if migration applied
4. Clear config and route caches
5. Restart web server and queue workers
6. Verify `/api/v1/health` returns 200

## Verification

- Health endpoint: `GET /api/v1/health` → 200
- Readiness endpoint: `GET /api/v1/readiness` → all dependencies healthy
- E2E smoke test: critical user flow completes without errors
