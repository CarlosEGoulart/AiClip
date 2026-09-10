# AiClip — M1 Application Foundation

Implemented slice: a Laravel API health endpoint with a real PostgreSQL check
(`GET /api/v1/health`) and a React page rendering it. Everything else
(authentication, projects, media, AI clips, image studio, social publishing) is
planned, not implemented.

## Actual stack

Backend: Laravel 13, PHP 8.3, PostgreSQL 16, Pest.
Frontend: React 19, TypeScript, Vite 8, Vitest 5, React Testing Library.
E2E: Playwright Chromium. Infrastructure: Docker Compose, GitHub Actions.

## Structure

```text
apps/api/          Laravel API (health route, Pest suite)
apps/web/          React frontend (health page, Vitest + Playwright suites)
docker-compose.yml Local PostgreSQL 16 service (postgres)
docs/              Product, architecture, roadmap, decisions, project state
specs/             Per-issue spec.md, plan.md, test-plan.md, evidence.md
tests/governance/ Governance contract tests
```

## Prerequisites

Docker + Compose plugin, PHP 8.3 with `pdo_pgsql`, Composer, Node.js 24.

## Run locally

From the repository root, start PostgreSQL:

```sh
docker compose up -d --wait postgres
```

In `apps/api` (terminal 1) — install, configure, migrate, serve:

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan test --compact
php artisan serve --host=127.0.0.1 --port=8000
```

In `apps/web` (terminal 2) — install, check, develop:

```sh
npm ci
npm test -- --maxWorkers=1
npm run lint
npm run build
npm run dev
```

Open http://127.0.0.1:5173 for the health page (API at
http://127.0.0.1:8000/api/v1/health).

## E2E without prestarted apps

In `apps/web`, with PostgreSQL ready and ports 5173/8000 free. Stop any manually
started dev servers first (Ctrl+C in both the API and web terminals): E2E owns
and releases its servers, and occupied ports fail the run instead of being reused.

```sh
npx playwright install --with-deps chromium
npm run test:e2e
```

The command starts and stops its own Laravel (`:8000`, strict port) and Vite
(`:5173`, strict port) servers, runs Chromium at 390x844, 768x1024, and
1440x900, and writes screenshots to `test-results/screenshots/`.

## API routing and deployment note

Local development uses an empty `VITE_API_BASE_URL`, so the app calls
same-origin `/api/v1/health` through the Vite `/api` proxy (see
`apps/web/.env.example`). `VITE_*` values are public build-time configuration,
not secrets. An optional public origin prefixes the path; a separate production
origin requires deployment-owned same-origin routing or explicit CORS — the Vite
proxy does not apply in production.

## Project state

Authoritative status lives in [docs/project-state.md](docs/project-state.md):
current architecture, completed capabilities, important decisions, known
limitations, current milestone, and next architectural goal. One implementation
issue is active at a time; Tester approval and green CI are required before any
merge.
