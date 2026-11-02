# Rollback Runbook

## Overview

Rollback procedure for AiClip production release failures.

## When to Rollback

Rollback is required when any of the following occur:

- Production CI checks fail after deployment
- `/api/v1/health` returns 503 or non-200
- `/api/v1/readiness` shows any dependency as unhealthy
- E2E smoke test fails on critical path
- Database migration fails or is partially applied
- Queue workers fail repeatedly with unrecoverable errors
- Security vulnerability discovered in deployed code

## Rollback Steps

### 1. Stop Traffic

Stop the web server and queue workers to prevent new requests from being processed:

```
php artisan queue:restart
# Stop web server (PHP-FPM, Octane, nginx, etc.)
```

### 2. Revert Code

```bash
git checkout <previous-release-tag>
# or
git revert <bad-commit>
```

### 3. Rollback Database (if migration was applied)

```bash
php artisan migrate:rollback --step=1
```

Only rollback if the migration was successfully applied and needs to be undone. If migration failed, do not attempt rollback — restore from backup instead.

### 4. Clear Caches

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### 5. Restart Services

Restart web server and queue workers:

```
# Restart web server
# Restart queue workers: php artisan queue:work --daemon
```

### 6. Verify

- `GET /api/v1/health` → 200
- `GET /api/v1/readiness` → all checks passing
- E2E smoke test passes

## Data Recovery

If data corruption is suspected:
1. Restore database from latest backup (see `docs/runbooks/backup-restore.md`)
2. Point application to restored database
3. Verify data integrity with application smoke tests

## Communication

- Notify on-call engineer
- Update incident status page
- Document incident in `docs/runbooks/incident-response.md`
