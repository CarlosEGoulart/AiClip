# Implementation Plan — Issue #126

## Objective

Implement a deterministic evidence freshness and traceability validator that binds evidence to the exact commit/PR state, fails closed on stale/missing/contradictory evidence, standardizes decision-line validation, and provides regression coverage — without weakening the merge gate or modifying agent permissions.

## Phase 1: Evidence freshness contract (TDD RED)

### Step 1: Write RED unit tests for `scripts/evidence_freshness.py`

Create `tests/governance/test_evidence_freshness.py` covering scenarios E1–E15 from the spec. Tests fail because `scripts/evidence_freshness.py` does not exist yet (import/execution errors).

Test cases (RED expectations):

- Valid evidence with all fields consistent → no errors.
- Missing Decision line → error naming missing field.
- Stale commit SHA → error naming commit field.
- Stale PR URL → error naming PR field.
- Missing CI check from REQUIRED_CHECKS → error naming missing check.
- CI check state mismatch → error naming check and observed state.
- Fabricated CI check (listed but not run) → error.
- Multiple decision lines → error naming conflict.
- Malformed decision line → error.
- Empty evidence → error.
- Missing Commit/PR/Evidence-At fields → errors naming each field.
- No synthesized approval without source Decision line → error.
- Existing governance tests still pass (no regression).

## Phase 2: Implement `scripts/evidence_freshness.py`

### Step 2: Implement pure validator functions

Create deterministic pure functions:

- `parse_evidence(content)` — extracts Decision, Commit, PR, CI lines, Evidence-At, Merge-State.
- `validate_decision_line(content)` — exactly one valid Decision: APPROVE|REJECT; rejects multiple/conflicting/malformed/missing.
- `validate_commit(commit_sha, head_sha)` — evidence commit must match evaluated head SHA.
- `validate_pr(pr_url, evaluated_pr_url)` — evidence PR must match evaluated PR.
- `validate_ci_checks(evidence_ci, observed_checks)` — every required check present, state matches, no fabricated checks.
- `validate_freshness(evidence, head_sha, pr_url, observed_checks)` — aggregates all freshness checks; fails closed with field-named reasons.
- `run_evidence_freshness(evidence_path, head_sha, pr_url, observed_checks)` — entry point returning errors + exit code.

No file writes, no network, no secrets, no `.env` access.

## Phase 3: Single command entry point

### Step 3: Wire `python scripts/evidence_freshness.py`

Add `if __name__ == "__main__": main()` that prints violations with remediation and exits non-zero on any failure. Prints "Evidence freshness checks passed" on exit 0.

## Phase 4: Verification and regression

### Step 4: Execute RED → GREEN → REFACTOR

- Run `tests/governance/test_evidence_freshness.py` → RED (fail, module missing).
- Implement `scripts/evidence_freshness.py` → GREEN (all new tests pass).
- Refactor for clarity/consistency with `tests/governance/validators.py`; all tests remain green.
- Run full governance suite `python -m unittest discover -s tests/governance -p 'test_*.py' -v` → no regression.

### Step 5: Builder writes evidence.md

Builder creates `specs/126-governance-evidence-freshness/evidence.md` with TDD sections (RED, GREEN, REFACTOR) and a valid `Decision: APPROVE` line plus Commit/PR/CI/Evidence-At/Merge-State fields. Planner does not write this file.

## Expected Final State

- `scripts/evidence_freshness.py` — deterministic evidence freshness validator.
- `tests/governance/test_evidence_freshness.py` — unit tests covering E1–E15.
- `specs/126-governance-evidence-freshness/evidence.md` — written by Builder/Tester.
- All governance + evidence freshness tests pass; no regression.
- No application code introduced.
- Merge gate not weakened; agent permissions unchanged.

## Commits (Conventional Commits, Refs #126)

- `feat(governance): add evidence freshness validator` Refs #126
- `test(governance): add evidence freshness unit tests` Refs #126
- `docs(spec): record evidence freshness contract` Refs #126

## Verification

- `PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/governance -p 'test_*.py' -v` passes.
- `python scripts/evidence_freshness.py` exits 0 on valid evidence; non-zero with field-named reason on violation.
- No secrets read or printed.
- Merge gate behavior unchanged (fail-closed preserved).
