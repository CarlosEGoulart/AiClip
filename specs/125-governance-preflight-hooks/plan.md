# Implementation Plan — Issue #125

## Objective

Implement a deterministic preflight validation command that catches governance violations before expensive tests or PR review, following SDD/TDD.

## Phase 1: Preflight contract and checks (TDD)

### Step 1: Write RED tests for `scripts/preflight.py` checks

Create `tests/governance/test_preflight.py` with tests that fail because `scripts/preflight.py` does not exist yet.

Check P1 (branch/issue association):
- Valid: `@carlosegoulart/125/feat/governance-preflight-hooks` with matching specs dir → []
- Invalid: `feat/branch-without-prefix` → ["P1 ..."]
- Invalid: `@carlosegoulart/abc/chore/desc` (non-numeric issue) → ["P1 ..."]
- Invalid: `@carlosegoulart/7/random/desc` (bad type) → ["P1 ..."]
- Invalid: `@carlosegoulart/999/feat/nonexistent` (no matching specs dir) → ["P1 ..."]
- Invalid: empty branch name → ["P1 ..."]

Check P2 (spec bundle):
- Valid: spec.md, plan.md, test-plan.md all present → []
- Invalid: missing plan.md → ["P2 ... plan.md"]
- Invalid: empty spec.md → ["P2 ... spec.md ... empty"]

Check P3 (working-tree hygiene):
- Valid: clean tree → []
- Invalid: unrelated modified file → ["P3 ... <file>"]

Check P4 (generated artifacts):
- Valid: no artifacts → []
- Invalid: node_modules present → ["P4 ... node_modules"]

Check P5 (commit metadata):
- Valid: `feat(governance): add preflight hooks` → []
- Invalid: `fix governance` (no scope) → ["P5 ..."]
- Invalid: `feat(governance): ` (empty subject) → ["P5 ..."]

### Step 2: Implement `scripts/preflight.py`

Create deterministic pure functions for each check plus a `run_preflight()` that aggregates errors and returns exit code. No file writes, no network, no secrets.

## Phase 2: Single command entry point

### Step 3: Wire `python scripts/preflight.py` entry point

Add `if __name__ == "__main__": main()` that prints violations with remediation and exits non-zero on any failure.

## Phase 3: CI integration

### Step 4: Extend `.github/workflows/governance.yml`

Add a `preflight` job (or step) that runs `python scripts/preflight.py` as an authoritative CI check. Keep CI authoritative; local pre-commit hook optional and documented.

## Phase 4: Evidence and state

### Step 5: Builder writes evidence.md

Builder creates `specs/125-governance-preflight-hooks/evidence.md` with TDD sections (RED, GREEN, REFACTOR) and `Decision: APPROVE`. Planner does not write this file.

### Step 6: Update `docs/project-state.md`

Add completed capability for governance preflight hooks.

## Expected Final State

- `scripts/preflight.py` — deterministic preflight checks
- `tests/governance/test_preflight.py` — unit tests covering success, failure, edge cases
- `.github/workflows/governance.yml` — extended with preflight CI check
- `docs/project-state.md` — updated
- All governance + preflight tests pass
- No application code introduced
- No files modified automatically by preflight

## Commits (Conventional Commits, Refs #125)

- `feat(governance): add preflight hooks contract` Refs #125
- `test(governance): add preflight unit tests` Refs #125
- `ci(governance): run preflight in CI` Refs #125
- `docs(project-state): record preflight capability` Refs #125

## Verification

- `PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/governance -p 'test_*.py' -v` passes.
- `python scripts/preflight.py` exits 0 on clean state; non-zero on violation.
- CI governance workflow green.
- No secrets read or printed.
