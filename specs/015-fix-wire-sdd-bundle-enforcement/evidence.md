# Evidence — Issue #15: Wire SDD Bundle Enforcement into PR Governance Execution

## Execution Date

2026-09-10

## TDD Evidence

### RED

Added 3 tests proving `run_checks()` accepts incomplete SDD bundles: missing plan.md, missing test-plan.md, empty required file. All 3 tests passed (RED confirmed).

### GREEN

Wired `validate_sdd_bundle()` into `run_checks()` with `specs_dir` parameter for dependency injection. Rewrote integration tests to exercise `run_checks()` orchestration. Replaced hardcoded `test_g10` with generic validation. All 83 tests pass.

### REFACTOR

Cleaned up test helpers. Verified all tests remain green.

## Builder Implementation

### Modified Files

- `tests/governance/pr_enforcement.py` — Added `specs_dir` parameter to `run_checks()`, imported and called `validate_sdd_bundle()`
- `tests/governance/test_pr_enforcement.py` — Rewrote integration tests to exercise `run_checks()`, added `TestRunChecksSddBundle` class
- `tests/governance/test_governance.py` — Replaced hardcoded `test_g10` with generic `validate_sdd_bundle()` call

## Governance Tests

All 83 tests pass:
- 58 unit tests (existing)
- 10 governance tests (9 existing + 1 updated generic validation)
- 15 integration tests (12 existing rewritten + 3 new SDD bundle tests)

## Independent Tester Review

Reviewer: Tester

Decision: APPROVE

`run_checks()` now calls `validate_sdd_bundle()`. Generic validation catches any incomplete `specs/<issue>-*` directory. No hardcoded issue list. Integration tests exercise `run_checks()` orchestration with isolated temporary directories. All existing merge gates remain intact.
