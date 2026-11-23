# Issue #159 — Test Plan

## Overview

There is no application code in this issue; verification is operational. The test plan validates that `docker compose up -d` starts a healthy local infrastructure stack (PostgreSQL 16 + MinIO) after the image tag fix.

## Test Environment

- Host: local CloudShell / Linux
- Docker Engine 29.9.3, Docker Compose v5.7.0
- Network access to Docker Hub (verified)
- Ports 5432 and 9000 free

## Test Scenarios

| # | Scenario | Expected Result | Pass/Fail |
|---|----------|-----------------|-----------|
| T.1 | `docker compose up -d` with corrected tags | both images pull, no 'not found' / reference errors, containers start | |
| T.2 | `docker compose ps` | `aiclip-postgres` healthy, `aiclip-minio` healthy | |
| T.3 | `docker compose logs minio-init` | 'Bucket created successfully `aiclip/aiclip-media`', 'Access permission ... set to `private`', 'MinIO bucket initialized successfully' | |
| T.4 | `docker inspect aiclip-minio-init-1` | `exited 0` | |
| T.5 | `git diff docker-compose.yml` | only the three image-tag substitution lines; no unrelated changes | |
| T.6 | Backend `php artisan migrate --force` against `127.0.0.1:5432` | migrations run successfully (confirms PostgreSQL reachable) | |

## Execution Notes

- Run T.1–T.5 on the feature branch `@carlosegoulart/159/fix/docker-compose-image-tags`.
- T.6 requires `apps/api` configured (`.env`, `composer install`, `php artisan key:generate`) and is a secondary confirmation that PostgreSQL is reachable.
- No automated test files are added (this is an infrastructure/config fix).

## Definition of Done (Test)

- [ ] All test scenarios T.1–T.5 pass
- [ ] Tester records approval in issue comments
- [ ] No `FAIL` or unexpected error in any scenario
