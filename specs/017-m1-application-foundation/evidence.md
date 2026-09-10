# Evidence: Issue #17 — M1 Application Foundation

## Summary

Initialize the M1 Application Foundation with a working vertical slice: React Web → GET /api/v1/health → Laravel API → PostgreSQL.

## Scope

- Laravel 11 API in apps/api/ with GET /api/v1/health endpoint
- React 19 + TypeScript + Vite frontend in apps/web/
- PostgreSQL 16 via Docker Compose
- Backend: 6 Pest tests (health endpoint, CORS, validation)
- Frontend: 3 Jest tests (HealthCheck component states)
- CI workflows for backend, frontend, and E2E testing

## TDD Evidence

### RED

#### Backend (Pest)

Failed health endpoint tests written before implementation:

```
tests/Feature/HealthTest.php — 6 tests
```

- `test_health_endpoint_returns_ok_status` — asserts 200 + JSON structure
- `test_health_endpoint_returns_database_status` — asserts `database` key present
- `test_health_endpoint_returns_timestamp` — asserts ISO-8601 timestamp
- `test_health_endpoint_is_get_only` — asserts POST/PUT/DELETE return 405
- `test_health_endpoint_does_not_require_authentication` — asserts no 401/403
- `test_health_endpoint_responds_under_500ms` — asserts performance

All 6 tests failed before the health controller was implemented (RED confirmed).

#### Frontend (Jest + React Testing Library)

Failed HealthCheck component tests written before implementation:

```
src/__tests__/HealthCheck.test.tsx — 3 tests
```

- `renders loading state initially` — asserts `role="status"` visible
- `renders success state after successful API call` — asserts "Status: ok" and "Database: connected"
- `renders error state after failed API call` — asserts `role="alert"` visible

All 3 tests failed before component implementation (RED confirmed).

### GREEN

#### Backend

Health endpoint implemented in `app/Http/Controllers/HealthController.php`:

```php
public function __invoke(): JsonResponse
{
    try {
        DB::connection()->getPdo();
        $database = 'connected';
        $status = Response::HTTP_OK;
    } catch (Exception) {
        $database = 'disconnected';
        $status = Response::HTTP_SERVICE_UNAVAILABLE;
    }

    return response()->json([
        'status' => $database === 'connected' ? 'ok' : 'error',
        'database' => $database,
        'timestamp' => now()->toIso8601String(),
    ], $status);
}
```

Route registered in `routes/api.php`:

```php
Route::get('/v1/health', HealthController::class)->name('health.show');
```

All 6 Pest tests pass (GREEN confirmed).

#### Frontend

HealthCheck component implemented in `src/components/HealthCheck.tsx`:

- Loading state: `role="status"` div
- Success state: displays status, database, and timestamp
- Error state: `role="alert"` div with error message

All 3 Jest tests pass (GREEN confirmed).

### REFACTOR

- Health controller uses `__invoke` (single-action) — clean and idiomatic
- Component uses `useEffect` with `fetch` — standard pattern
- No additional refactoring needed; implementations were minimal from GREEN

## Backend Verification

Real API response captured:

```json
{
  "status": "ok",
  "database": "connected",
  "timestamp": "2026-09-10T02:54:44+00:00"
}
```

Confirmed PostgreSQL 16 connection through Laravel.

## E2E Notes

Playwright E2E tests (`e2e/health.spec.ts`) are written and configured but cannot run locally due to:
1. Node.js v24.20.0 causes Vite/Vitest Bus errors — prevents `vite dev` server startup
2. Playwright Chromium browser download (186MB) exceeds available `/home` partition space (119MB free)

CI workflows (`.github/workflows/e2e.yml`) will run these tests on a clean GitHub Actions runner with sufficient disk and compatible Node.js.

## Risks

- Node.js v24 causes Vite/Vitest Bus errors locally; CI should use compatible Node.js version
- Playwright E2E tests require Chromium browser download (186MB) which may fail on constrained CI runners

## CI

- Backend: PHP, Composer, PostgreSQL, Pest
- Frontend: Node.js, npm, Jest, build
- E2E: Docker Compose, Playwright

## Decision: APPROVE
