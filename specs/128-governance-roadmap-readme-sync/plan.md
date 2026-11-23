# Implementation Plan — Issue #128

## Objective

Update `README.md` and `docs/roadmap.md` so they agree with `docs/project-state.md` and merged records: mark M7 Slice 2, M8 Slices 1–2, and M9 as completed with issue/PR evidence; list M14 Production Hardening as CI green/mergeable (not closed); document next architectural goals and dependencies; mark future work as planned/not implemented. All changes are documentation-only; follow TDD RED/GREEN/REFACTOR with documentation-validation tests.

## Phase 1: RED — Write documentation validation tests first

### Step 1: Write RED tests for README/roadmap consistency

Create governance-style tests (or add to existing test scenarios) that verify:

- README milestone table contains M7 Slice 2 with Issue #110 / PR #111.
- README milestone table contains M8 Slices 1–2 with Issue #113/#115, PR #114/#116.
- README milestone table contains M9 with Issue #118 / PR #130.
- README lists M14 Production Hardening (Issue #123, PR #141) as mergeable-not-closed.
- README contains a future-work section marking remaining M8/M7/M6 slices and M10/M11/M12 publishing as planned/not implemented.
- roadmap.md M7 section includes Slice 2 (Issue #110 / PR #111).
- roadmap.md M9 marked completed (Issue #118 / PR #130), not pending under Planned Milestones.
- roadmap.md Planned Milestones table has no duplicate M14 entries.
- No production-readiness/release claims for unimplemented items.
- All content in English.

These tests must fail (RED) before the documentation edits, because README/roadmap currently lack the corrected entries.

## Phase 2: GREEN — Implement documentation edits

### Step 2: Edit README.md

- Update milestone table: add M7 Slice 2 (Issue #110, PR #111); add M8 Slices 1–2 references (Issue #113/#115, PR #114/#116); add M9 (Issue #118, PR #130); add M14 Production Hardening (Issue #123, PR #141) as CI green/mergeable not closed.
- Add a "Future Work / Next Architectural Goals" section listing remaining M8/M7/M6 slices and M10/M11/M12 social publishing as planned/not implemented, requiring separate explicit authorization.
- Link each completed milestone to its issue/PR evidence where available.
- Remove any implication that future items are implemented.

### Step 3: Edit docs/roadmap.md

- Add M7 Slice 2 to M7 section (Issue #110, PR #111).
- Mark M9 Social Connection Framework as completed (Issue #118, PR #130); remove from Planned Milestones as pending.
- Correct Planned Milestones table: remove duplicate M14 entries; keep M10/M11/M12 publishing and Production Hardening marked as planned/not implemented.
- Ensure each completed milestone links to issue/PR evidence.

### Step 4: Re-run documentation validation tests

All RED tests must now pass (GREEN). No application code is introduced.

## Phase 3: REFACTOR — Improve documentation quality without altering meaning

### Step 5: Refactor wording for clarity and consistency

- Ensure milestone status descriptions match `docs/project-state.md` exactly.
- Verify no stale claims remain (no "Completed" for unmerged work, no production-ready claims for future items).
- Verify English-only content (AGENTS.md §19).
- Re-run documentation validation tests and governance suite; all must remain green.

## Expected Final State

- `README.md` — milestone table and future-work section agree with `docs/project-state.md`; each completed milestone links to issue/PR evidence; future work marked planned/not implemented.
- `docs/roadmap.md` — M7 Slice 2 and M9 completed; Planned Milestones table corrected (no duplicate M14); future work marked planned/not implemented.
- `specs/128-governance-roadmap-readme-sync/evidence.md` — Builder writes with TDD sections (RED/GREEN/REFACTOR or TDD: N/A — documentation-only with reason) and `Decision:` line.
- Governance tests pass; no SDD bundle validation regression.
- No application code introduced; no secrets read or printed.

## Commits (Conventional Commits, Refs #128)

- `docs(readme): sync milestone table with project state` Refs #128
- `docs(readme): add future work and next architectural goals` Refs #128
- `docs(roadmap): mark M7 Slice 2 and M9 completed` Refs #128
- `docs(roadmap): correct planned milestones table` Refs #128
- `test(governance): add readme/roadmap consistency tests` Refs #128
- `docs(project-state): record roadmap-readme sync` Refs #128 — only if in scope for Orchestrator after closure; otherwise omit.

## Verification

- Documentation validation tests pass (RED→GREEN→REFACTOR).
- Governance suite: `python -m unittest discover -s tests/governance -p 'test_*.py' -v` passes; `validate_sdd_bundle` finds no regression for specs/128 bundle.
- README and roadmap manually cross-checked against docs/project-state.md for M7 Slice 2, M8 Slices 1–2, M9, M14.
- English-only content confirmed.
- No unsupported completion/test/security/production-readiness claims.
