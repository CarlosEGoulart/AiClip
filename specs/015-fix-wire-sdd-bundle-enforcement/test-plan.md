# Test Plan: Issue #15

## Integration Tests (test_pr_enforcement.py)

All tests exercise `run_checks()` orchestration with isolated `TemporaryDirectory()` specs.

| Test | Scenario | Expected |
|------|----------|----------|
| test_valid_pr_passes | Complete bundle + valid PR | [] |
| test_reject_fails | REJECT evidence | errors |
| test_bare_na_fails | TDD N/A without reason | errors |
| test_missing_plan_fails | Missing plan.md | errors |
| test_missing_test_plan_fails | Missing test-plan.md | errors |
| test_empty_required_fails | Empty required file | errors |
| test_isolated_specs_not_mutated | Temp specs don't affect real | no mutation |

## Governance Tests (test_governance.py)

| Test | Change |
|------|--------|
| test_g10_generic_sdd_validation | Use validate_sdd_bundle() generically |

## Existing Regression Tests

| Test | Status |
|------|--------|
| test_invalid_commit_message_fails | Preserve |
| test_invalid_branch_name_fails | Preserve |
| test_missing_closes_fails | Preserve |
| test_branch_issue_mismatch_fails | Preserve |
| test_multiple_closes_fails | Preserve |
| test_missing_event_path_fails | Preserve |
