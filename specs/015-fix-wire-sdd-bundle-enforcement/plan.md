# Implementation Plan: Issue #15

## Issue

#15 - fix(governance): wire SDD bundle enforcement into PR governance execution

## Phase 1: RED

Add tests proving current `run_checks()` accepts incomplete SDD bundles:

1. Add `test_run_checks_missing_plan_fails` — proves `run_checks()` accepts missing plan.md
2. Add `test_run_checks_missing_test_plan_fails` — proves `run_checks()` accepts missing test-plan.md
3. Add `test_run_checks_empty_required_fails` — proves `run_checks()` accepts empty files
4. Run tests — all fail (RED)

## Phase 2: GREEN

1. Import `validate_sdd_bundle` in `pr_enforcement.py`
2. Add `specs_dir` parameter to `run_checks()`
3. Call `validate_sdd_bundle()` in `run_checks()`
4. Rewrite integration tests to exercise `run_checks()` with isolated specs
5. Replace hardcoded `test_g10` with generic `validate_sdd_bundle()` call
6. Run tests — all pass

## Phase 3: REFACTOR

Clean up test helpers. Verify all 80+ tests remain green.

## Commit Strategy

Single commit: `fix(governance): wire SDD bundle enforcement into PR governance execution`
