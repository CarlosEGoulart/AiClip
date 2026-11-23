# Issue #159 — fix(docker): update postgres and minio image tags

## Overview

This plan implements the docker-compose image tag fix in a single verifiable increment. No TDD code cycles are required because there is no application code; the "test" is the operational verification of `docker compose up -d`.

Branch: `@carlosegoulart/159/fix/docker-compose-image-tags` (created by Orchestrator).

## Phase 0: Project Setup & Verification

### 0.1 Verify Working Tree State

- [ ] Confirm current branch is `master` and working tree has the uncommitted `docker-compose.yml` change (image tags already edited locally but not committed).
- [ ] Confirm `docker` and `docker compose` are available (`docker --version`, `docker compose version`).
- [ ] Confirm network access to Docker Hub (verified: `postgres:16-alpine` and `pgsty/minio:latest` pull successfully).

## Phase 1: Move Change to Feature Branch

### 1.1 Create Branch

- [ ] Orchestrator creates branch `@carlosegoulart/159/fix/docker-compose-image-tags` from `master`.
- [ ] Orchestrator checks out the new branch.

### 1.2 Apply / Verify Image Tag Changes

The working tree already contains these edits (from prior local work). Verify them on the branch:

- [ ] `docker-compose.yml` line 3: `image: postgres:16-alpine` (was `postgres:16.4-alpine`).
- [ ] `docker-compose.yml` line 20: `image: pgsty/minio:latest` (was `minio/minio:RELEASE.2025-10-02T04-12-34Z`).
- [ ] `docker-compose.yml` line 40: `image: pgsty/minio:latest` (was `minio/minio:RELEASE.2025-10-02T04-12-34Z`).
- [ ] No other lines in `docker-compose.yml` are modified.
- [ ] `git diff docker-compose.yml` shows only the three image-tag substitutions.

## Phase 2: Operational Verification (RED → GREEN)

### 2.1 RED — Verify Failure Before Fix (Reference State)

- [ ] Temporarily revert `docker-compose.yml` to the original invalid tags OR reason from the known failure: `docker.io/minio/minio:RELEASE.2025-10-02T04-12-34Z: not found` and `postgres:16.4-alpine: not found`.
- [ ] Confirm `docker compose up -d` cannot start the services with invalid tags.
- [ ] Record RED evidence (error output) in issue comments.

### 2.2 GREEN — Apply Fix and Verify

- [ ] On branch `@carlosegoulart/159/fix/docker-compose-image-tags`, ensure `docker-compose.yml` has the corrected tags.
- [ ] Run `docker compose up -d`.
- [ ] Confirm output shows both containers created and started.
- [ ] Run `docker compose ps`; confirm `aiclip-postgres` and `aiclip-minio` are `healthy`.
- [ ] Run `docker compose logs minio-init`; confirm bucket created and exit code 0.
- [ ] Run `docker inspect aiclip-minio-init-1 --format '{{.State.Status}} {{.State.ExitCode}}'`; confirm `exited 0`.

### 2.3 REFACTOR — Consistency Check

- [ ] Review `docker-compose.yml` as a whole: indentation, both minio services use the same image/tag, no unrelated edits.
- [ ] Re-run `docker compose up -d` (idempotent) to confirm no regressions.
- [ ] `git diff` shows only the three intended image-tag lines.

## Phase 3: Commit, Push, PR, Merge

### 3.1 Commit

- [ ] `git add docker-compose.yml`
- [ ] Commit: `fix(docker): update postgres and minio image tags`
- [ ] Commit body/footer: `Refs #159`

### 3.2 Push and PR

- [ ] Push branch `@carlosegoulart/159/fix/docker-compose-image-tags` to origin.
- [ ] Open Pull Request via `scripts/merge_gate.py` referencing issue #159 (`Closes #159`).
- [ ] PR description includes Summary, Scope, TDD Evidence (RED/GREEN/REFACTOR), Tests, CI, Scope Verification per AGENTS.md §11.

### 3.3 Merge and Close

- [ ] CI green (no CI workflow for compose config; manual verification is the gate).
- [ ] Tester approval recorded.
- [ ] PR merged via `scripts/merge_gate.py`.
- [ ] Issue #159 closed.

## File Creation Summary

| File | Change |
|------|--------|
| `docker-compose.yml` | 3 image-tag substitutions (`postgres:16.4-alpine` → `postgres:16-alpine`; `minio/minio:RELEASE...` → `pgsty/minio:latest` ×2) |

## Definition of Done

- [ ] Issue exists
- [ ] Branch naming valid
- [ ] Specification exists
- [ ] Plan exists
- [ ] Test plan exists
- [ ] Acceptance criteria satisfied
- [ ] RED demonstrated
- [ ] GREEN demonstrated
- [ ] Refactor remained green
- [ ] `docker compose up -d` verified manually (both services healthy, bucket initialized)
- [ ] Tester approved
- [ ] PR references the issue
- [ ] PR merged via `scripts/merge_gate.py`
- [ ] Issue closed
