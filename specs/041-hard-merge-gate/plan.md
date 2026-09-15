# Implementation Plan: Deterministic Merge Gate Enforcement

## Issue and Scope

[Issue #41](https://github.com/CarlosEGoulart/AiClip/issues/41) authorizes only the seven files listed in `spec.md`. No application behavior, CI workflows, database, API, or frontend changes are permitted.

## 1. Preserve the Baseline and Complete SDD

Orchestrator has created `@carlosegoulart/41/fix/hard-merge-gate`. Planner creates only `spec.md`, `plan.md`, and `test-plan.md`. Before execution, an authorized executing role creates a nonempty `evidence.md` with honest pending execution/review status.

## 2. RED Phase: Write Failing Tests First

### 2.1 Permission Regression Tests

Add to `tests/governance/test_agent_permissions.py`:

1. **Orchestrator direct gh pr merge = DENY**
   - Load orchestrator frontmatter
   - Parse bash rules
   - Assert `decide(bash_rules, "gh pr merge 1 --merge") == "deny"`

2. **Orchestrator merge_gate command = ALLOW**
   - Assert `decide(bash_rules, "python scripts/merge_gate.py 1") == "allow"`
   - Assert `decide(bash_rules, "python scripts/merge_gate.py 1 --check") == "allow"`

3. **Orchestrator feature branch push = ALLOW**
   - Assert `decide(bash_rules, "git push origin @carlosegoulart/41/fix/hard-merge-gate") == "allow"`

4. **Orchestrator master push = DENY**
   - Assert `decide(bash_rules, "git push origin master") == "deny"`

5. **Orchestrator main push = DENY**
   - Assert `decide(bash_rules, "git push origin main") == "deny"`

6. **Force push = DENY**
   - Assert `decide(bash_rules, "git push --force") == "deny"`

7. **Unknown agent permissions remain denied**
   - Test that unknown agents (e.g., "explorer") have all permissions denied by default

### 2.2 Merge Gate Unit Tests

Create `tests/governance/test_merge_gate.py` with the following test structure:

```python
class TestMergeGateValidation(unittest.TestCase):
    """Test merge gate validation logic using mocks."""

    def test_rejects_draft_pr(self):
        # Mock PR with draft=true
        # Expect: MERGE_BLOCKED: PR_DRAFT

    def test_rejects_wrong_base_branch(self):
        # Mock PR with base != master
        # Expect: MERGE_BLOCKED: WRONG_BASE_BRANCH

    def test_rejects_missing_closes_reference(self):
        # Mock PR body without "Closes #N"
        # Expect: MERGE_BLOCKED: MISSING_CLOSES_REFERENCE

    def test_rejects_missing_tester_approve(self):
        # Mock evidence file missing "Decision: APPROVE"
        # Expect: MERGE_BLOCKED: TESTER_NOT_APPROVED

    def test_rejects_tester_reject(self):
        # Mock evidence file containing "Decision: REJECT"
        # Expect: MERGE_BLOCKED: TESTER_NOT_APPROVED

    def test_rejects_missing_required_check(self):
        # Mock check runs missing required check
        # Expect: MERGE_BLOCKED: CHECK_MISSING:<name>

    def test_rejects_pending_required_check(self):
        # Mock check run with status "pending"
        # Expect: MERGE_BLOCKED: CHECK_PENDING:<name>

    def test_rejects_failed_required_check(self):
        # Mock check run with status "failure"
        # Expect: MERGE_BLOCKED: CHECK_FAILED:<name>

    def test_rejects_cancelled_required_check(self):
        # Mock check run with status "cancelled"
        # Expect: MERGE_BLOCKED: CHECK_CANCELLED:<name>

    def test_rejects_skipped_mandatory_check(self):
        # Mock check run with status "skipped" for mandatory check
        # Expect: MERGE_BLOCKED: CHECK_SKIPPED:<name>

    def test_rejects_changed_head_sha(self):
        # Mock HEAD SHA change between capture and merge
        # Expect: MERGE_BLOCKED: HEAD_CHANGED

    def test_does_not_invoke_gh_pr_merge_on_rejected_path(self):
        # Mock validation failure
        # Assert gh pr merge not called

    def test_complete_green_invokes_exactly_one_merge_command(self):
        # Mock all validations pass
        # Assert exactly one gh pr merge called with --match-head-commit

    def test_merge_command_uses_validated_head_sha(self):
        # Mock validation pass
        # Assert merge command includes --match-head-commit <captured_sha>

    def test_check_flag_performs_validation_without_merging(self):
        # Mock --check flag
        # Assert no gh pr merge called even if validation passes

    def test_no_force_bypass_cli_options(self):
        # Inspect script source for forbidden options
        # Assert no --force, --skip-ci, --ignore-tester
```

### 2.3 Verification

Run the new tests and verify they fail (RED):

```sh
python -m unittest tests/governance/test_agent_permissions.py -v
python -m unittest tests/governance/test_merge_gate.py -v
```

Expected: All new tests fail because:
- Orchestrator still allows `gh pr merge *`
- `scripts/merge_gate.py` does not exist
- Merge gate logic not implemented

## 3. GREEN Phase: Implement Enough Code to Pass

### 3.1 Update Orchestrator Permissions

Edit `.opencode/agents/orchestrator.md` bash section:

```yaml
bash:
  "**": deny
  # ... existing read-only commands ...
  "gh pr merge *": deny  # Changed from allow to deny
  "python scripts/merge_gate.py*": allow  # New allowance
  # ... keep other existing commands ...
```

### 3.2 Implement Merge Gate Script

Create `scripts/merge_gate.py` with the following structure:

```python
#!/usr/bin/env python3
"""Trusted merge gate script for deterministic PR merging.

Usage:
    python scripts/merge_gate.py <PR_NUMBER>
    python scripts/merge_gate.py <PR_NUMBER> --check

Exit codes:
    0 = success (merged or ready)
    1 = blocked/error

Output format:
    MERGE_BLOCKED: <REASON>
    MERGE_READY
    MERGED
"""

import argparse
import re
import subprocess
import sys
from pathlib import Path

REQUIRED_CHECKS = [
    "governance / governance",
    "governance / pr-enforcement",
    "Backend CI / tests",
    "Frontend CI / test",
    "E2E CI / e2e",
]

def run_command(cmd):
    """Run shell command and return (returncode, stdout, stderr)."""
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    return result.returncode, result.stdout.strip(), result.stderr.strip()

def validate_pr_state(pr_number):
    """Validate PR exists, is open, not draft, base=master, mergeable, has Closes #N."""
    # Implementation here
    pass

def validate_tester_approval(issue_number):
    """Validate evidence file contains Decision: APPROVE."""
    # Implementation here
    pass

def validate_required_checks(pr_head_sha):
    """Validate all required CI checks passed."""
    # Implementation here
    pass

def validate_head_sha(pr_number, captured_sha):
    """Validate HEAD SHA hasn't changed."""
    # Implementation here
    pass

def main():
    parser = argparse.ArgumentParser(description="Merge gate validator")
    parser.add_argument("pr_number", type=int, help="PR number to validate/merge")
    parser.add_argument("--check", action="store_true", help="Validate only, do not merge")
    args = parser.parse_args()

    # Validation sequence
    # 1. PR state
    # 2. Tester approval
    # 3. Required checks
    # 4. HEAD SHA stability
    # 5. If --check, exit with MERGE_READY
    # 6. Otherwise, merge with --match-head-commit
    pass

if __name__ == "__main__":
    main()
```

### 3.3 Make Script Executable

```sh
chmod +x scripts/merge_gate.py
```

### 3.4 Verification

Run the tests again and verify they pass (GREEN):

```sh
python -m unittest tests/governance/test_agent_permissions.py -v
python -m unittest tests/governance/test_merge_gate.py -v
```

Run the existing governance suite to ensure no regressions:

```sh
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

## 4. REFACTOR Phase: Improve Quality Without Changing Behavior

### 4.1 Code Review

Review the merge gate script for:
- Clear error messages
- Consistent output format
- Proper exit codes
- No dead code
- No duplicate logic
- Appropriate comments

### 4.2 Test Coverage Review

Ensure all 23 required tests are present and meaningful:
- Permission tests (7)
- Merge gate logic tests (16)

### 4.3 Documentation Review

Verify:
- `spec.md` accurately describes the implementation
- `plan.md` reflects actual steps taken
- `test-plan.md` covers all scenarios

### 4.4 Final Verification

Run all tests one more time after any refactoring:

```sh
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

## 5. Evidence and Handoff

### 5.1 Record Evidence

Builder creates `specs/041-hard-merge-gate/evidence.md` with:
- RED phase: test failures observed
- GREEN phase: test passes achieved
- Refactor phase: quality improvements made
- Final test counts and exit status

### 5.2 Independent Testing

Tester independently:
1. Runs all governance tests
2. Validates merge gate script behavior
3. Reviews permission changes
4. Records APPROVE or REJECT

### 5.3 Orchestrator Lifecycle

Only after Tester approval:
1. Orchestrator creates commit following Conventional Commits
2. Orchestrator pushes branch
3. Orchestrator creates PR referencing Issue #41
4. Orchestrator monitors CI
5. Orchestrator merges after CI green using merge gate script
6. Orchestrator verifies issue closure
7. Orchestrator returns to NO_ACTIVE_ISSUE

## Permission Boundaries

- Builder implements only the scoped files
- Builder does not run `gh pr merge` or lifecycle commands
- Tester runs governance tests and reviews artifacts
- Orchestrator coordinates lifecycle
- No permission workarounds or bypasses are allowed