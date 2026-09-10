# Specification: Wire SDD Bundle Enforcement into PR Governance Execution

## Issue

#15 - fix(governance): wire SDD bundle enforcement into PR governance execution

## Overview

Complete the SDD bundle enforcement by wiring `validate_sdd_bundle()` into the actual repository/PR governance execution path. Restore true integration coverage for PR enforcement.

## Current Defects

1. `run_checks()` in `pr_enforcement.py` does NOT call `validate_sdd_bundle()` — the validator exists but is never executed against the real `specs/` tree by CI
2. Integration tests (`test_reject_fails_merge_gate`, `test_bare_na_fails`) call validators directly instead of exercising `run_checks()`
3. `test_g10_historical_issue_bundles_complete` is hardcoded to Issues #9 and #11 instead of using generic `validate_sdd_bundle()`

## Required Changes

### pr_enforcement.py

1. Import `validate_sdd_bundle` from `validators`
2. Add `specs_dir: Path | None = None` parameter to `run_checks()`
3. Call `validate_sdd_bundle(specs_dir or default_specs_dir)` in `run_checks()`

### test_governance.py

Replace `test_g10_historical_issue_bundles_complete` with generic validation using `validate_sdd_bundle()` against the real `specs/` directory.

### test_pr_enforcement.py

Rewrite integration tests to exercise `run_checks()` with isolated `TemporaryDirectory()` specs:

| Test | Scenario |
|------|----------|
| test_valid_pr_passes | Complete bundle + valid PR → [] |
| test_reject_fails | REJECT evidence → errors |
| test_bare_na_fails | TDD N/A without reason → errors |
| test_missing_plan_fails | Missing plan.md → errors |
| test_missing_test_plan_fails | Missing test-plan.md → errors |
| test_empty_required_fails | Empty required file → errors |
| test_isolated_specs_not_mutated | Temp specs don't affect real specs/ |

## Acceptance Criteria

- [ ] `run_checks()` calls `validate_sdd_bundle()`
- [ ] Generic validation catches any incomplete `specs/<issue>-*` directory
- [ ] No hardcoded issue list for future correctness
- [ ] Integration tests exercise `run_checks()` orchestration
- [ ] Missing plan.md fails through `run_checks()`
- [ ] Missing test-plan.md fails through `run_checks()`
- [ ] Empty required artifact fails through `run_checks()`
- [ ] APPROVE-only gate remains intact
- [ ] All governance tests pass

## Out of Scope

- Application scaffolding
- API endpoints or authentication

## Dependencies

- Issue #13 (SDD bundle validator)
