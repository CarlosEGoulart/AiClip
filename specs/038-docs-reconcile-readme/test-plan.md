# Test Plan — Issue #38: Reconcile README after Media Storage completion

## Scope Classification

This is a **documentation-only** issue. Application unit tests, integration tests, and E2E tests are **N/A** because no application behavior, API, UI, or component logic changes.

Application checks: **N/A** — no application or interface affected by this governance-only documentation change.

## Test Approach

Verification is performed by inspecting file contents against the required repository state and running the governance test suite to confirm no regressions.

## Verification Checklist

### README.md Content Verification

- [ ] **README no longer says M1 "In progress"**
  - Inspect: `README.md` status table
  - Expected: M1 — Application Foundation: Completed
  - Method: grep for "In progress" in README.md — must return zero matches

- [ ] **README no longer says authentication is the next M1 slice**
  - Inspect: `README.md` M1 delivered text
  - Expected: M1 delivered text reflects completed auth and project management
  - Method: grep for "next.*slice" or "authentication.*next" in README.md — must return zero matches

- [ ] **README no longer labels Sanctum as planned**
  - Inspect: `README.md` architecture diagram
  - Expected: "Laravel Sanctum SPA" (not "Laravel Sanctum (planned)")
  - Method: grep for "Sanctum.*planned" in README.md — must return zero matches

- [ ] **README records M1 as Completed**
  - Inspect: `README.md` status table
  - Expected: M1 — Application Foundation: Completed

- [ ] **README records M2 Media Storage as Completed**
  - Inspect: `README.md` status table
  - Expected: M2 — Media Storage: Completed

- [ ] **README does NOT claim M3 is started**
  - Inspect: `README.md` status table and body
  - Expected: M3 not listed or listed as planned/not started
  - Method: grep for "M3.*Completed" or "M3.*In progress" in README.md — must return zero matches

- [ ] **README does NOT represent future components as current**
  - Inspect: `README.md` architecture diagram
  - Expected: Python Media Worker, FFmpeg, Transcription, Scene detection, Image generation remain labeled as "(planned)"
  - Method: grep for planned components without "(planned)" label — must return zero matches

### docs/project-state.md Content Verification

- [ ] **project-state.md unchanged if already correct**
  - Inspect: `docs/project-state.md`
  - If PR #37 already corrected it: no changes needed
  - If stale: verify corrections applied and consistent with README.md
  - Expected: "Completed Capabilities" includes M2 Media Storage
  - Expected: "Current Milestone" does not show M1 as current
  - Expected: "Next Architectural Goal" does not show M2 as upcoming

### docs/roadmap.md Content Verification

- [ ] **roadmap.md unchanged if already correct**
  - Inspect: `docs/roadmap.md`
  - If PR #37 already corrected it: no changes needed
  - If stale: verify corrections applied and consistent with README.md
  - Expected: M1 section says "completed" (not "in progress")
  - Expected: M1 completed slices include auth and project management
  - Expected: M2 is not in "Planned Milestones" section
  - Expected: M1 has no "Next slice" referencing authentication

### Scope Verification

- [ ] **No application files changed**
  - Method: `git diff --name-only` must not include any files under `apps/`
  - Expected: zero application files in diff

- [ ] **No agent files changed**
  - Method: `git diff --name-only` must not include any files under `.opencode/agents/`
  - Expected: zero agent files in diff

- [ ] **No governance tests changed**
  - Method: `git diff --name-only` must not include any files under `tests/governance/`
  - Expected: zero governance test files in diff

### Governance Test Suite

- [ ] **Run: `python -m unittest discover -s tests/governance -p 'test_*.py' -v`**
  - Expected: ALL tests GREEN
  - Expected: No failures, no errors
  - Record: total tests run, total passed, total failed

## Test Execution Order

1. Run governance tests first (establishes baseline)
2. Inspect README.md content (verify all stale statements removed)
3. Inspect project-state.md content (verify consistency)
4. Inspect roadmap.md content (verify consistency)
5. Verify scope boundaries (no unrelated changes)
6. Cross-document consistency check (all three files agree)

## Evidence Recording

Results must be recorded in `specs/038-docs-reconcile-readme/evidence.md` with:

- Decision: APPROVE or REJECT
- Governance test results (count, pass/fail)
- Each verification item checked with PASS/FAIL
- Any deviations documented with rationale
