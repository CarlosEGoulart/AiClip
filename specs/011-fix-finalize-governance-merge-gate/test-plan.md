# Test Plan: Issue #11

> Historical recovery note:
> This document was reconstructed after Issue #11 had already been merged.
> It restores the required SDD artifact structure and does not claim that this
> test plan existed before the original implementation.

## Unit Tests (test_enforcement.py)

### TestMergeApprovalValidator

| Test | Scenario |
|------|----------|
| test_approve_passes | APPROVE passes merge gate |
| test_reject_fails | REJECT fails merge gate |
| test_missing_decision_fails | No decision fails merge gate |
| test_both_decisions_fail | Both APPROVE and REJECT fail |
| test_empty_content_fails | Empty evidence fails |

### TDD N/A Tests

| Test | Scenario |
|------|----------|
| test_invalid_bare_na_without_reason | TDD: N/A without reason fails |
| test_invalid_na_with_empty_reason | TDD: N/A — (empty) fails |

## Integration Tests (test_pr_enforcement.py)

| Test | Scenario |
|------|----------|
| test_valid_pr_context_passes | Real evidence with APPROVE passes |
| test_reject_fails_merge_gate | REJECT evidence blocks merge |
| test_bare_na_fails | TDD N/A without reason blocked |
