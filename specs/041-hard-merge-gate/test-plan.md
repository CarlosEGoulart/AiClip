# Test Plan: Deterministic Merge Gate Enforcement

## Issue and Strategy

[Issue #41](https://github.com/CarlosEGoulart/AiClip/issues/41) enforces a deterministic merge gate. Use the written manual acceptance tests below and the automated governance tests. The merge gate script must be validated through both automated unit tests and manual scenario verification.

## Execution Record

Record each test execution in this issue's `evidence.md`:

```text
Test ID:
Phase: RED / GREEN / final rerun / independent review
Inspection performed:
Expected:
Observed:
Result: PASS / FAIL
Supporting path/excerpt or source reference:
```

## Governance Regression Tests (23 Required)

### Permission Tests (7)

**T1: Orchestrator direct gh pr merge = DENY**
- Procedure: Load `.opencode/agents/orchestrator.md` frontmatter, parse bash rules, evaluate `gh pr merge 1 --merge`
- Expected: `decide(bash_rules, "gh pr merge 1 --merge") == "deny"`
- Automation: `tests/governance/test_agent_permissions.py::TestOrchestratorPermissions::test_orchestrator_denies_direct_merge`

**T2: Orchestrator merge_gate command = ALLOW**
- Procedure: Evaluate `python scripts/merge_gate.py 1` and `python scripts/merge_gate.py 1 --check`
- Expected: Both return `"allow"`
- Automation: `tests/governance/test_agent_permissions.py::TestOrchestratorPermissions::test_orchestrator_allows_merge_gate`

**T3: Orchestrator feature branch push = ALLOW**
- Procedure: Evaluate `git push origin @carlosegoulart/41/fix/hard-merge-gate`
- Expected: `"allow"`
- Automation: `tests/governance/test_agent_permissions.py::TestOrchestratorPermissions::test_orchestrator_allows_feature_push`

**T4: Orchestrator master push = DENY**
- Procedure: Evaluate `git push origin master`
- Expected: `"deny"`
- Automation: `tests/governance/test_agent_permissions.py::TestOrchestratorPermissions::test_orchestrator_denies_master_push`

**T5: Orchestrator main push = DENY**
- Procedure: Evaluate `git push origin main`
- Expected: `"deny"`
- Automation: `tests/governance/test_agent_permissions.py::TestOrchestratorPermissions::test_orchestrator_denies_main_push`

**T6: Force push = DENY**
- Procedure: Evaluate `git push --force`
- Expected: `"deny"`
- Automation: `tests/governance/test_agent_permissions.py::TestOrchestratorPermissions::test_orchestrator_denies_force_push`

**T7: Unknown agent permissions remain denied**
- Procedure: Load a non-existent agent (e.g., "explorer") with no frontmatter
- Expected: All permissions denied by default
- Automation: `tests/governance/test_agent_permissions.py::TestOrchestratorPermissions::test_unknown_agent_denied`

### Merge Gate Logic Tests (16)

**T8: Merge gate rejects draft PR**
- Procedure: Mock PR with `draft: true`, run merge gate
- Expected: Exit non-zero, output `MERGE_BLOCKED: PR_DRAFT`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_rejects_draft_pr`

**T9: Merge gate rejects wrong base branch**
- Procedure: Mock PR with base branch `develop`, run merge gate
- Expected: Exit non-zero, output `MERGE_BLOCKED: WRONG_BASE_BRANCH`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_rejects_wrong_base_branch`

**T10: Merge gate rejects missing Closes #N**
- Procedure: Mock PR body without `Closes #N` reference
- Expected: Exit non-zero, output `MERGE_BLOCKED: MISSING_CLOSES_REFERENCE`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_rejects_missing_closes_reference`

**T11: Merge gate rejects missing Tester APPROVE**
- Procedure: Mock evidence file missing `Decision: APPROVE`
- Expected: Exit non-zero, output `MERGE_BLOCKED: TESTER_NOT_APPROVED`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_rejects_missing_tester_approve`

**T12: Merge gate rejects Tester REJECT**
- Procedure: Mock evidence file containing `Decision: REJECT`
- Expected: Exit non-zero, output `MERGE_BLOCKED: TESTER_NOT_APPROVED`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_rejects_tester_reject`

**T13: Merge gate rejects missing required check**
- Procedure: Mock check runs missing `E2E CI / e2e`
- Expected: Exit non-zero, output `MERGE_BLOCKED: CHECK_MISSING:E2E CI / e2e`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_rejects_missing_required_check`

**T14: Merge gate rejects pending required check**
- Procedure: Mock check run with status `pending`
- Expected: Exit non-zero, output `MERGE_BLOCKED: CHECK_PENDING:<check_name>`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_rejects_pending_required_check`

**T15: Merge gate rejects failed required check**
- Procedure: Mock check run with status `failure`
- Expected: Exit non-zero, output `MERGE_BLOCKED: CHECK_FAILED:<check_name>`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_rejects_failed_required_check`

**T16: Merge gate rejects cancelled required check**
- Procedure: Mock check run with status `cancelled`
- Expected: Exit non-zero, output `MERGE_BLOCKED: CHECK_CANCELLED:<check_name>`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_rejects_cancelled_required_check`

**T17: Merge gate rejects skipped mandatory check**
- Procedure: Mock check run with status `skipped` for mandatory check
- Expected: Exit non-zero, output `MERGE_BLOCKED: CHECK_SKIPPED:<check_name>`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_rejects_skipped_mandatory_check`

**T18: Merge gate rejects changed HEAD SHA**
- Procedure: Mock HEAD SHA change between capture and merge attempt
- Expected: Exit non-zero, output `MERGE_BLOCKED: HEAD_CHANGED`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_rejects_changed_head_sha`

**T19: Merge gate does not invoke gh pr merge on rejected path**
- Procedure: Mock validation failure, monitor subprocess calls
- Expected: `gh pr merge` not called
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_does_not_invoke_merge_on_rejected_path`

**T20: Complete GREEN state invokes exactly one merge command**
- Procedure: Mock all validations pass, monitor subprocess calls
- Expected: Exactly one `gh pr merge` call
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_green_invokes_exactly_one_merge`

**T21: Merge command uses validated HEAD SHA**
- Procedure: Mock validation pass, inspect merge command arguments
- Expected: `--match-head-commit <captured_sha>` present
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_merge_uses_validated_head_sha`

**T22: --check performs validation without merging**
- Procedure: Mock validation pass, run with `--check` flag
- Expected: No `gh pr merge` call, output `MERGE_READY`
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_check_performs_validation_without_merge`

**T23: No force/bypass CLI options exist**
- Procedure: Inspect script source code for forbidden options
- Expected: No `--force`, `--skip-ci`, `--ignore-tester` options
- Automation: `tests/governance/test_merge_gate.py::TestMergeGateValidation::test_no_force_bypass_options`

## Manual Acceptance Tests

### M1: Orchestrator Permission Verification

**Procedure:**
1. Read `.opencode/agents/orchestrator.md` frontmatter
2. Parse bash permission rules
3. Verify `gh pr merge *` is deny
4. Verify `python scripts/merge_gate.py*` is allow
5. Verify existing allowed commands remain unchanged

**Expected:** Permission changes match spec exactly.

### M2: Merge Gate Script Exists and Is Executable

**Procedure:**
1. Glob `scripts/merge_gate.py`
2. Check file exists and is non-empty
3. Check file permissions include execute bit

**Expected:** Script exists, is non-empty, is executable.

### M3: Merge Gate CLI Interface

**Procedure:**
1. Run `python scripts/merge_gate.py --help`
2. Verify usage shows `<PR_NUMBER>` argument
3. Verify `--check` flag is documented
4. Verify no `--force`, `--skip-ci`, `--ignore-tester` options

**Expected:** Clean CLI interface with only allowed options.

### M4: Merge Gate Error Handling

**Procedure:**
1. Run `python scripts/merge_gate.py` (no arguments)
2. Run `python scripts/merge_gate.py abc` (invalid PR number)
3. Run `python scripts/merge_gate.py 99999999` (non-existent PR)

**Expected:** Proper error messages and non-zero exit codes.

### M5: Governance Suite Regression

**Procedure:**
1. Run `python -m unittest discover -s tests/governance -p 'test_*.py' -v`
2. Verify all tests pass
3. Verify test count includes new tests

**Expected:** All governance tests pass, no regressions.

## RED, GREEN, and Final Rerun

### RED Phase

Before implementing merge gate script and permission changes:
1. Run T1-T23 tests
2. Record expected failures:
   - T1: FAIL (orchestrator still allows `gh pr merge *`)
   - T2-T7: FAIL (permission changes not applied)
   - T8-T23: FAIL (script doesn't exist or logic not implemented)

### GREEN Phase

After implementing merge gate script and permission changes:
1. Run T1-T23 tests
2. Record all tests passing
3. Run M1-M5 manual tests
4. Record manual test results

### Refactor Phase

After any code quality improvements:
1. Re-run all automated tests (T1-T23)
2. Re-run governance suite
3. Verify no regressions

### Final Rerun

Independent Tester executes:
1. All 23 automated governance tests
2. Manual acceptance tests M1-M5
3. Full governance suite
4. Reviews evidence file for completeness

## Applicability and Evidence Bounds

- **Application unit/API/E2E tests:** N/A — this issue changes only governance tooling and permissions, not application behavior
- **Browser/visual/accessibility interaction:** N/A — no UI changes
- **Existing required CI:** Must remain unchanged and pass
- **Merge gate script:** Must be validated through automated tests and manual verification
- **Permission changes:** Must be validated through automated permission tests

Record results in `specs/041-hard-merge-gate/evidence.md`. Keep automated test outputs and manual verification records separate. Do not claim execution, Tester approval, or CI outcomes before they occur.