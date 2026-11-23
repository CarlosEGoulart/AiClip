# Issue #159 — fix(docker): update postgres and minio image tags

## Overview

This specification defines the fix for the local development infrastructure defined in `docker-compose.yml`. The compose file references image tags that do not exist on Docker Hub, so `docker compose up -d --build` fails and the development environment (PostgreSQL + MinIO) cannot be started.

- `postgres:16.4-alpine` — invalid tag. PostgreSQL 16 patch tags follow the pattern `16.x-alpine` (e.g. `16.16-alpine`), never `16.4-alpine`.
- `minio/minio:RELEASE.2025-10-02T04-12-34Z` — MinIO removed their Docker Hub repositories entirely in 2025. The image cannot be pulled.

## Description

Goal: make `docker compose up -d` start a healthy PostgreSQL 16 and a healthy S3-compatible MinIO (with the `aiclip/aiclip-media` bucket initialized) so the AiClip backend and front-end can run locally.

Why it is needed: without a working local infrastructure stack, no backend migration, no front-end development, and no E2E testing can happen. This is a prerequisite for all other development work.

Scope boundaries:
- Only `docker-compose.yml` image tags are changed. No environment variables, ports, volumes, or commands are added/removed.
- No application code changes.
- No new infrastructure services.
- The replacement MinIO image must be pullable and must include both the `minio` server binary and the `mc` client binary (required by the healthcheck and `minio-init` scripts).
- No secrets introduced; existing dev-only local credentials (`secret`, `minioadmin`) remain unchanged.

## Technical Tasks

- [ ] Replace `postgres:16.4-alpine` with `postgres:16-alpine` in the `postgres` service.
- [ ] Replace `minio/minio:RELEASE.2025-10-02T04-12-34Z` with `pgsty/minio:latest` in both the `minio` and `minio-init` services. `pgsty/minio` is a community-maintained MinIO fork (5M+ pulls on Docker Hub) verified pullable locally and includes `/usr/bin/minio`, `/bin/mc`, and `/usr/bin/mc`.
- [ ] Run `docker compose up -d` and confirm both containers reach `healthy` status.
- [ ] Confirm `minio-init` exits 0 and creates bucket `aiclip/aiclip-media` with private access.
- [ ] Run `docker compose ps` and verify `aiclip-postgres` (port 5432) and `aiclip-minio` (port 9000) are listed as healthy.

## Acceptance Criteria

- [ ] `docker compose up -d` completes without image pull/reference errors.
- [ ] `aiclip-postgres` (`postgres:16-alpine`) is `healthy` on port 5432.
- [ ] `aiclip-minio` (`pgsty/minio:latest`) is `healthy` on port 9000.
- [ ] `aiclip-minio-init` exits 0; logs show bucket created and 'MinIO bucket initialized successfully'.
- [ ] No changes are committed to `master` directly — all work happens on a feature branch.
- [ ] Branch naming follows `@carlosegoulart/{n}/fix/docker-compose-image-tags`.
- [ ] Commit follows Conventional Commits: `fix(docker): update postgres and minio image tags` with `Refs #159`.

## Out of Scope

- No application code changes.
- No new infrastructure services.
- No changes to environment variables, port mappings, volumes, or healthcheck commands.
- No production deployment concerns (this is local development only).

## Security Considerations

- MinIO root credentials (`MINIO_ROOT_USER`/`MINIO_ROOT_PASSWORD`) remain as existing dev-only local values (`minioadmin`).
- PostgreSQL password remains the existing dev-only local value (`secret`).
- No secrets are introduced, committed, or logged.
- The replacement image (`pgsty/minio`) is a public community fork; no private registry credentials are required.

## UX Considerations

- N/A (infrastructure/configuration only; no user interface).

## Test Scenarios

| # | Scenario | Expected Result |
|---|----------|-----------------|
| T.1 | `docker compose up -d` | both images pull without reference errors; containers start |
| T.2 | `docker compose ps` | `aiclip-postgres` and `aiclip-minio` both show `healthy` |
| T.3 | `docker compose logs minio-init` | bucket `aiclip/aiclip-media` created; 'MinIO bucket initialized successfully'; exit code 0 |
| T.4 | `php artisan migrate --force` (backend) | can connect to PostgreSQL on 127.0.0.1:5432 and run migrations |

## Dependencies

- Docker Engine 29.9.3, Docker Compose v5.7.0 (available locally).
- Network access to Docker Hub (verified working).
- Existing `docker-compose.yml` structure (services, volumes, healthchecks) remains intact.

## Branch & Commit Strategy

**Branch**: `@carlosegoulart/159/fix/docker-compose-image-tags` (created by Orchestrator).

**Commits** (Conventional Commits, each referencing `Refs #159`):
1. `fix(docker): update postgres and minio image tags`

## Agent Pipeline (AGENTS.md)

Strict linearity — one active implementation issue at a time:

1. **Planner** — creates this issue + spec + plan + test plan (`specs/159-fix-docker-compose-image-tags/`).
2. **Orchestrator** — verifies issue is active, creates branch `@carlosegoulart/159/fix/docker-compose-image-tags`, invokes Builder.
3. **Builder** — implements the fix (only `docker-compose.yml` image tags), verifies `docker compose up -d` health.
4. **Tester** — independently verifies both services healthy, bucket initialized, no unrelated changes in the diff.
5. **Orchestrator** — commits, pushes, opens PR via `scripts/merge_gate.py`, CI green, merge, close issue.

## SDD / TDD Requirements

- **RED**: Verify `docker compose up -d` fails before the fix (image reference not found / not found on Docker Hub).
- **GREEN**: Apply image tag changes in `docker-compose.yml`; verify `docker compose up -d` succeeds and both services are `healthy`.
- **REFACTOR**: Review the compose file for consistency (same image for `minio` and `minio-init`, indentation, no stray changes); re-run verification; everything remains green.

## Documentation

- No `specs/` plan/test-plan markdown is strictly required for this governance/config fix, but this spec is created for traceability per repo convention.
- `docs/project-state.md` does not need updating (infrastructure assumptions unchanged).
- Evidence of working `docker compose up -d` can be recorded in issue comments.

## Definition of Done

- [ ] Issue exists (#159)
- [ ] Branch naming valid (`@carlosegoulart/159/fix/docker-compose-image-tags`)
- [ ] Specification exists (`specs/159-fix-docker-compose-image-tags/spec.md`)
- [ ] Plan exists (`specs/159-fix-docker-compose-image-tags/plan.md`)
- [ ] Test plan exists (`specs/159-fix-docker-compose-image-tags/test-plan.md`)
- [ ] Acceptance criteria satisfied
- [ ] RED demonstrated (`docker compose up -d` fails before fix)
- [ ] GREEN demonstrated (`docker compose up -d` succeeds after fix)
- [ ] Refactor remained green
- [ ] `docker compose up -d` verified manually
- [ ] Tester approved
- [ ] CI green (if applicable)
- [ ] PR references the issue
- [ ] PR merged via `scripts/merge_gate.py`
- [ ] Issue closed
