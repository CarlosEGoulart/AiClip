# AiClip Web Foundation (M1)

React 19 + TypeScript + Vite frontend for AiClip. The implemented M1 foundation is a
health page backed by the Laravel API; authentication, projects, media, and publishing
UI are planned and not implemented here.

## Prerequisites

- Node.js 24 (see `.nvmrc` if present, otherwise use Node 24)
- The Laravel API in `../api` with PostgreSQL 16 prepared (see `../api/README.md`)
- Playwright Chromium for E2E: `npx playwright install chromium`

## Working directory

All commands below run from `apps/web`.

## Environment

Copy the example and keep the default empty value for local development so requests
use the same-origin Vite `/api` proxy:

```sh
cp .env.example .env
```

- Empty/unset `VITE_API_BASE_URL`: the app calls relative `/api/v1/health`,
  proxied to `http://127.0.0.1:8000` by the Vite dev server.
- Optional public origin (no trailing slash, for example
  `https://api.example.com`): the app prefixes `/api/v1/health` with it. A separate
  production origin requires deployment-owned routing or explicit CORS; the Vite
  proxy applies to local development only.

## Commands

```sh
npm ci
npm run dev          # Vite dev server on http://127.0.0.1:5173 (strict port)
npm test -- --maxWorkers=1   # Vitest unit/component suite (non-watch)
npm run test:watch   # Vitest in watch mode
npm run lint         # Oxlint
npm run build        # TypeScript + Vite production build (outputs dist/)
```

`npm run test:e2e` starts the real stack itself (Laravel API on
`http://127.0.0.1:8000` plus Vite on `http://127.0.0.1:5173`) with readiness checks,
runs Playwright Chromium at 390x844, 768x1024, and 1440x900, then releases both
servers and ports. Stop any manually started dev servers first (Ctrl+C in the
terminal running `npm run dev`, and Ctrl+C in the API terminal): E2E owns its
servers, and occupied ports fail the run instead of being reused. Screenshots land in `test-results/screenshots/` labeled by
viewport and state.

## What is tested

- `src/__tests__/HealthCheck.test.tsx`: pending, success, rejection, non-2xx, and
  API URL selection against the production component (fetch stubbed only at the
  network boundary).
- `e2e/health.spec.ts`: real-stack success through the proxy, deterministic pending,
  controlled network rejection, and HTTP 503, with console/network/overflow checks.
