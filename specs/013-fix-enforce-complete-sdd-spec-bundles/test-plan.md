# Test Plan: Issue #13

## Unit Tests (test_enforcement.py)

### TestSddBundleValidator

| Test | Input | Expected |
|------|-------|----------|
| test_complete_bundle_passes | dir with 4 valid files | [] |
| test_missing_plan_fails | dir with spec+test-plan+evidence | [error] |
| test_missing_test_plan_fails | dir with spec+plan+evidence | [error] |
| test_missing_evidence_fails | dir with spec+plan+test-plan | [error] |
| test_missing_spec_fails | dir with plan+test-plan+evidence | [error] |
| test_empty_required_file_fails | dir with empty plan.md | [error] |
| test_multiple_valid_directories_pass | 2 valid dirs | [] |

All tests use `tempfile.TemporaryDirectory()`.

## Integration Tests (test_pr_enforcement.py)

### Refactored Tests

| Test | Change |
|------|--------|
| test_valid_pr_context_passes | Use temp specs dir |
| test_reject_fails_merge_gate | Use temp specs dir |
| test_bare_na_fails | Use temp specs dir |

All tests pass temporary directory to `find_evidence_file()`.

## Governance Tests (test_governance.py)

### Updated Tests

| Test | Change |
|------|--------|
| test_g1_required_artifacts_exist_nonempty | Verify 009 and 011 have 4 files |

## Historical Repair Validation

Verify:
- specs/009 has spec.md, plan.md, test-plan.md, evidence.md (non-empty)
- specs/011 has spec.md, plan.md, test-plan.md, evidence.md (non-empty)
- Both plan.md and test-plan.md contain retrospective recovery note
