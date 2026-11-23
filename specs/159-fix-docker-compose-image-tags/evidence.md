# Issue #159 — fix(docker): update postgres and minio image tags — Tester Evidence

## Issue
#159 — fix(docker): update postgres and minio image tags

## Branch
`@carlosegoulart/159/fix/docker-compose-image-tags`

## Builder Model
Nemotron 3.5 Super VL 120B A12B (nvidia)

---

## 1. Verification Scope

This issue is an infrastructure/config fix only — no application code. The only production change is `docker-compose.yml` (3 image-tag substitutions). Verification is operational, not automated:

| Check | Method | Result |
|-------|--------|--------|
| RED — invalid tags fail | `docker compose up -d` on master | `postgres:16.4-alpine: not found`; `minio/minio:RELEASE...: not found` |
| GREEN — corrected tags work | `docker compose up -d` on branch | both images pulled, containers started |
| Service health | `docker compose ps` | `aiclip-postgres` healthy (5432), `aiclip-minio` healthy (9000) |
| Bucket init | `docker compose logs minio-init` | bucket `aiclip/aiclip-media` created, private, exit 0 |
| Diff scope | `git diff docker-compose.yml` | only the 3 intended image-tag lines |
| Unrelated changes excluded | `.gitignore` (`image*`) | deliberately NOT committed in this issue |

---

## 2. Tester Review

Reviewed independently:
- Branch naming valid: `@carlosegoulart/159/fix/docker-compose-image-tags` (fix type, lowercase, kebab-case, matches issue #159).
- Commit follows Conventional Commits: `fix(docker): update postgres and minio image tags` with `Refs #159`.
- Spec, plan, test-plan exist in `specs/159-fix-docker-compose-image-tags/`.
- Acceptance criteria all satisfied (services healthy, bucket initialized, no unrelated changes).
- No application code, no new services, no env/port/volume changes.
- Security: dev-only local credentials unchanged; no secrets introduced.
- UX: N/A (infrastructure only).

Both services confirmed `healthy` after `docker compose up -d`. `minio-init` exited 0 with bucket created.

---

## TDD Verification (AGENTS.md §12)

This issue is an infrastructure/config fix (no application code), so the TDD cycle applies to operational verification:

### RED
Verified `docker compose up -d` fails on `master` before the fix:
- `docker.io/minio/minio:RELEASE.2025-10-02T04-12-34Z: not found`
- `postgres:16.4-alpine: not found`

### GREEN
Applied the three image-tag substitutions in `docker-compose.yml`:
- `postgres:16.4-alpine` → `postgres:16-alpine`
- `minio/minio:RELEASE...` → `pgsty/minio:latest` (×2)

Verified `docker compose up -d` succeeds:
- `aiclip-postgres` (postgres:16-alpine) healthy on port 5432
- `aiclip-minio` (pgsty/minio:latest) healthy on port 9000
- `aiclip-minio-init` exits 0, bucket `aiclip/aiclip-media` created (private)

### REFACTOR
Reviewed `git diff docker-compose.yml`: only the three intended image-tag lines, no unrelated changes. Re-ran `docker compose up -d` idempotently — remains green.

---

## Decision

**Decision: APPROVE**

The implementation satisfies all acceptance criteria for issue #159. No unrelated scope changes. Ready for merge via `scripts/merge_gate.py`.
