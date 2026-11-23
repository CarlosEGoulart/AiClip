# Test Plan — Issue #129

## Overview

Governance/specification-only issue: define objective MVP completion criteria. No application code or UI changes exist in this issue, so application unit/API, E2E, Playwright, visual, and accessibility interaction are N/A (reason: criteria definition and validation only). Tests are deterministic criteria-coverage checks plus the existing governance suite. No network, no `gh`, no git execution required for unit level.

## Test Scenarios

### C1: MVP scope enumerated and traceable

**Input:** `specs/129-governance-mvp-completion-criteria/spec.md` and `docs/prd.md` MVP Feature List.

**Expected:** MVP end-to-end workflows enumerated (clip creation, image generation, social publishing, project management, account management) and each required feature traceable to the PRD MVP feature list or project-state completed capabilities.

### C2: Every completion criterion objectively verifiable

**Input:** `specs/129-governance-mvp-completion-criteria/spec.md` acceptance criteria.

**Expected:** Each completion criterion maps to at least one objective verification (unit test, integration test, E2E test, Playwright test, governance check, CI check, documentation artifact, or operational check). No criterion is purely subjective or unactionable.

### C3: Social publishing classified as deferred for MVP

**Input:** `specs/129-governance-mvp-completion-criteria/spec.md` classification section.

**Expected:** Social publishing (M10 YouTube, M11 Instagram, M12 TikTok) classified as deferred for MVP; requires separate explicit authorization; not claimed as implemented.

### C4: Image Studio extensions classified as deferred for MVP

**Input:** `specs/129-governance-mvp-completion-criteria/spec.md` classification section.

**Expected:** Image Studio remaining slices (real model-backed image generation provider, image deletion, batch generation, image editing) classified as deferred for MVP. Image Studio Slices 1–2 (foundation + signed download URLs/gallery previews) classified as implemented with required test suites listed.

### C5: Required test suites enumerated with commands

**Input:** `specs/129-governance-mvp-completion-criteria/test-plan.md` and `spec.md`.

**Expected:** Required unit tests (Pest), integration tests, E2E tests, Playwright tests, and governance tests listed with deterministic execution commands (`php artisan test --compact`, `npm test`, `npm run test:e2e`, `python -m unittest discover -s tests/governance`).

### C6: Required CI checks enumerated

**Input:** `.github/workflows/` and `spec.md`.

**Expected:** Backend CI, Frontend CI, E2E CI, and governance CI listed as required checks; all must be green for MVP completion.

### C7: Documentation and operational checks enumerated

**Input:** `spec.md`.

**Expected:** Documentation checks (README/roadmap/project-state consistency) and operational checks (health endpoint, rate limiting, storage, queue processing) listed as required for MVP completion.

### C8: Known gaps documented

**Input:** `spec.md` gaps section and `docs/project-state.md` Known Limitations.

**Expected:** Known gaps documented: email verification not implemented, no project update/edit endpoint, real runtime transcription model requires faster-whisper optional dependency + model download, real runtime scene detection requires scenedetect[opencv-headless], full approve/reject/video-workflow E2E coverage deferred until rendered-clip fixtures exist.

### C9: Release blockers documented

**Input:** `spec.md` release blockers section and `docs/project-state.md` Current Milestone.

**Expected:** Release blockers documented: M14 Production Hardening (Issue #123, PR #141 CI green/mergeable, pending merge and issue closure); PR #141 not yet merged via `scripts/merge_gate.py`.

### C10: Final MVP completion is a human-authorized decision

**Input:** `spec.md` acceptance criteria and classification section.

**Expected:** Explicit statement that final MVP completion remains a human-authorized decision; no automated merge-to-release without human authorization.

### C11: No production code introduced; no secrets read/printed

**Input:** `specs/129-governance-mvp-completion-criteria/` bundle files.

**Expected:** No application code files modified; no `.env`, token, credential, or secret content read or printed.

### C12: English-only repository content preserved

**Input:** `specs/129-governance-mvp-completion-criteria/` bundle files.

**Expected:** All content in English; no Portuguese artifacts (AGENTS.md §19).

### C13: Governance test suite passes (no SDD bundle regression)

**Input:** Full `tests/governance/` suite including `validate_sdd_bundle`.

**Expected:** All existing tests pass; `specs/129-governance-mvp-completion-criteria/` bundle (`spec.md`, `plan.md`, `test-plan.md`) is valid and non-empty; `validate_sdd_bundle` finds no regression; no new errors introduced.

### C14: No placeholders in spec bundle

**Input:** `spec.md`, `plan.md`, `test-plan.md`.

**Expected:** No placeholder tokens (TODO/FIXME/TBD/XXX/lorem ipsum/to be decided) outside code fences; no empty required sections.

## Acceptance Criteria Coverage

| AC | Covered by |
|----|------------|
| MVP scope explicit and traceable | C1 |
| Each criterion objectively testable or has review artifact | C2 |
| Social publishing classified required/deferred | C3 |
| Image Studio extensions classified required/deferred | C4 |
| Required test suites, CI checks listed | C5, C6 |
| Governance, documentation, operational checks listed | C5, C6, C7 |
| Gaps documented rather than omitted | C8 |
| Release blockers documented | C9 |
| Final MVP completion human-authorized | C10 |
| No production code implemented | C11 |
| English-only content preserved | C12 |
| Governance tests pass, no SDD bundle regression | C13 |
| No placeholders / no empty sections | C14 |

## Application E2E / Playwright

**N/A** — No application code or UI changes exist in this issue. Governance/criteria definition only. Playwright validation not applicable.

## Test Execution

```bash
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Plus deterministic criteria-coverage checks covering C1–C14 against `specs/129-governance-mvp-completion-criteria/` bundle files and source documents (`docs/prd.md`, `docs/project-state.md`, `docs/roadmap.md`, `README.md`).

## Document Reason

This issue defines MVP completion criteria (specification/planning only); no application behavior exists to test with E2E or Playwright.