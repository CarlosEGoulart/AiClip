# Specification: M1 Application Foundation

## Issue

#17 - feat(m1): initialize application foundation with Laravel, React, PostgreSQL, and health verification

## Overview

Create a working vertical slice proving React Web → GET /api/v1/health → Laravel API → PostgreSQL.

## Verified Technology Versions

| Technology | Version | Source |
|---|---|---|
| PHP | 8.3.6 | php.net |
| Laravel | 11.x | laravel.com |
| Node.js | v24.20.0 | nodejs.org |
| npm | 12.0.2 | npmjs.com |
| Docker | 29.7.2 | docker.com |
| Docker Compose | v5.5.0 | docker.com |
| PostgreSQL | 16 | postgresql.org |

## Repository Structure

```
apps/
├── api/          (Laravel)
└── web/          (React + Vite)

docker-compose.yml
```

## Laravel API (apps/api/)

- Laravel 11 with PHP 8.3
- PostgreSQL 16 via Docker Compose
- `GET /api/v1/health` endpoint
- CORS configured for localhost:5173

## Health Endpoint Response

Success (200):
```json
{
  "status": "ok",
  "database": "connected",
  "timestamp": "2026-09-10T00:00:00.000000Z"
}
```

Failure (503):
```json
{
  "status": "error",
  "database": "disconnected"
}
```

## React Frontend (apps/web/)

- React 19 + TypeScript + Vite
- Health check page with loading/success/error states
- Vite proxy for /api requests to localhost:8000

## PostgreSQL Infrastructure

- Docker Compose with PostgreSQL 16
- Port 5432
- Named volume for persistence
- pg_isready health check

## CI Workflows

- Backend: PHP, Composer, PostgreSQL, Pest
- Frontend: Node.js, npm, Vitest, build
- E2E: Docker Compose, Playwright
