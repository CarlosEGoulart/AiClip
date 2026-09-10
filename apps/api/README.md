# AiClip API Foundation (M1)

Laravel 13 API backend for AiClip. The implemented M1 foundation exposes
`GET /api/v1/health` with a real PostgreSQL connectivity check. Authentication,
projects, media, queues, AI, and social publishing are planned and not implemented
here.

## Prerequisites

- PHP 8.3 with the `pdo_pgsql` extension
- Composer
- PostgreSQL 16 with a prepared database (example development values: host
  `127.0.0.1`, port `5432`, database/user `aiclip`)

## Working directory

All commands below run from `apps/api`.

## Setup

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
```

## Development server

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

Stop it with Ctrl+C. Before a managed `npm run test:e2e` run from `apps/web`,
stop this manual server too (Ctrl+C here and in the web terminal): E2E starts
and releases its own servers, and an occupied port fails the run.

## Health contract

- Success: HTTP 200 with `{"status":"ok","database":"connected","timestamp":"<ISO-8601>"}`.
  The request executes `SELECT 1` against PostgreSQL.
- Database failure: HTTP 503 with exactly
  `{"status":"error","database":"disconnected"}`. Exception, SQL, and credential
  details are never exposed.

## Tests

```sh
php artisan test --compact tests/Feature/HealthTest.php  # targeted health contract
php artisan test --compact                               # full backend suite
```

The suite covers success fields/ISO timestamp, the exact safe 503 payload under a
forced query exception (synthetic markers only, never real secrets), and observation
of the real `SELECT 1` query through PostgreSQL.
