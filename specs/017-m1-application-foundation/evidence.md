# Evidence: Issue #17 — M1 Application Foundation

## Summary

Initialize the M1 Application Foundation with a working vertical slice: React Web → GET /api/v1/health → Laravel API → PostgreSQL.

## Scope

- Laravel 13 API in apps/api/ with GET /api/v1/health endpoint
- React 19 + TypeScript + Vite frontend in apps/web/
- PostgreSQL 16 via Docker Compose
- Backend: 4 Pest tests (health endpoint response, fields, timestamp, DB connectivity)
- Frontend: 3 Jest tests (HealthCheck component states)
- CI workflows for backend, frontend, and E2E testing

## TDD Evidence

### RED

#### Backend (Pest)

Failed health endpoint tests written before implementation:

```
tests/Feature/HealthTest.php — 4 tests
```

- `returns 200 when database is connected` — asserts HTTP 200
- `returns valid JSON with expected fields` — asserts status, database, timestamp keys
- `has a timestamp in ISO 8601 format` — asserts timestamp regex
- `actually queries the database` — asserts database field is "connected"

All 4 tests failed before the health route was implemented (RED confirmed).

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

Health endpoint implemented as closure in `routes/api.php`:

```php
Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        try {
            DB::select('SELECT 1');
            return response()->json([
                'status' => 'ok',
                'database' => 'connected',
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'database' => 'disconnected',
            ], 503);
        }
    });
});
```

All 4 Pest tests pass (GREEN confirmed).

#### Frontend

HealthCheck component implemented in `src/components/HealthCheck.tsx`:

- Loading state: `role="status"` div
- Success state: displays status, database, and timestamp
- Error state: `role="alert"` div with error message

All 3 Jest tests pass (GREEN confirmed).

### REFACTOR

- Health endpoint uses closure — appropriate for a single simple endpoint
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
