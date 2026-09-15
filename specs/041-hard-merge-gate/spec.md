# Specification: Deterministic Merge Gate Enforcement

## Issue

- [Issue #41](https://github.com/CarlosEGoulart/AiClip/issues/41)
- Title: fix(governance): enforce deterministic merge gate
- Branch: `@carlosegoulart/41/fix/hard-merge-gate`

## Description

The current Orchestrator permissions allow direct `gh pr merge *` commands, enabling merges that bypass required validation steps. This creates a race condition where a PR could be merged before CI finishes, before Tester approval, or while the HEAD SHA changes.

This issue eliminates direct merge capability and replaces it with a trusted repository script (`scripts/merge_gate.py`) that enforces a deterministic merge gate. The gate validates PR state, Tester approval, required CI checks, and HEAD SHA stability before executing a single, atomic merge command.

The goal is to make accidental or premature merges impossible through tooling, while preserving the Orchestrator's ability to merge after all gates pass.

## Exact File Scope

Only these paths may change:

1. `scripts/merge_gate.py` — new trusted merge gate script
2. `.opencode/agents/orchestrator.md` — deny `gh pr merge *`, allow `python scripts/merge_gate.py*`
3. `tests/governance/test_agent_permissions.py` — add permission regression tests
4. `tests/governance/test_merge_gate.py` — new merge gate unit tests
5. `specs/041-hard-merge-gate/spec.md` — Planner specification
6. `specs/041-hard-merge-gate/plan.md` — Planner implementation plan
7. `specs/041-hard-merge-gate/test-plan.md` — Planner test plan

## Technical Tasks

### 1. Deny Direct Merge in Orchestrator Permissions

Edit `.opencode/agents/orchestrator.md` bash permissions:

- Change `"gh pr merge *": allow` to `"gh pr merge *": deny`
- Add `"python scripts/merge_gate.py*": allow`
- Keep all existing allowed read/inspection commands unchanged

### 2. Implement Trusted Merge Gate Script

Create `scripts/merge_gate.py` with the following contract:

**Allowed commands:**
- `python scripts/merge_gate.py <PR_NUMBER>` — validate and merge
- `python scripts/merge_gate.py <PR_NUMBER> --check` — validate only (no merge)

**Forbidden options:** `--force`, `--skip-ci`, `--ignore-tester`, or any weakening option.

**Validation steps (in order):**

1. **PR Existence and State:** PR exists, state is OPEN, not draft, base branch is master, PR is mergeable, body contains `Closes #N`.
2. **Tester Approval:** Extract issue number from PR body. Read `specs/{NNN}-{slug}/evidence.md`. Verify file contains `Decision: APPROVE`. Block on REJECT or missing.
3. **Required CI Checks:** Verify all five required GitHub checks for current PR HEAD SHA. Required checks:
   - `governance / governance`
   - `governance / pr-enforcement`
   - `Backend CI / tests`
   - `Frontend CI / test`
   - `E2E CI / e2e`
   Block on: missing, queued, pending, in_progress, failed, cancelled, timed_out, action_required, skipped (when mandatory), neutral (when success required), unknown.
4. **HEAD SHA Race Protection:** Capture HEAD SHA before validation. Verify same SHA before merge. Use `gh pr merge <PR> --merge --match-head-commit <HEAD_SHA>`. Block on SHA change.
5. **No Wait-and-Hope:** If any required check is still running (queued, pending, in_progress), return blocked. No auto-merge.

**Output:** Machine-readable exit codes and reasons:
- Exit 0 = success
- Non-zero = blocked/error
- Output reasons in format: `MERGE_BLOCKED: <REASON>` or `MERGE_READY` or `MERGED`

Reason codes:
- `PR_DRAFT`
- `TESTER_NOT_APPROVED`
- `CHECK_MISSING:<check_name>`
- `CHECK_PENDING:<check_name>`
- `CHECK_FAILED:<check_name>`
- `CHECK_CANCELLED:<check_name>`
- `CHECK_SKIPPED:<check_name>`
- `HEAD_CHANGED`

### 3. Governance Tests (23 Required)

Add tests to `tests/governance/test_agent_permissions.py` and create `tests/governance/test_merge_gate.py`.

**Permission tests (12):**
1. Orchestrator direct `gh pr merge *` = DENY
2. Orchestrator merge_gate command = ALLOW
3. Orchestrator feature branch push = ALLOW
4. Orchestrator master push = DENY
5. Orchestrator main push = DENY
6. Force push = DENY
7. Unknown agent permissions remain denied

**Merge gate logic tests (16):**
8. Merge gate rejects draft PR
9. Merge gate rejects wrong base branch
10. Merge gate rejects missing `Closes #N`
11. Merge gate rejects missing Tester APPROVE
12. Merge gate rejects Tester REJECT
13. Merge gate rejects missing required check
14. Merge gate rejects pending required check
15. Merge gate rejects failed required check
16. Merge gate rejects cancelled required check
17. Merge gate rejects skipped mandatory check
18. Merge gate rejects changed HEAD SHA
19. Merge gate does not invoke `gh pr merge` on rejected path
20. Complete GREEN state invokes exactly one merge command
21. Merge command uses validated HEAD SHA
22. `--check` performs validation without merging
23. No force/bypass CLI options exist

### 4. Control-Plane Immutability

After this issue, the following files must NOT be modified by Builder/Tester/Planner/Orchestrator (except through explicit human approval):
- `scripts/merge_gate.py`
- `tests/governance/test_agent_permissions.py`
- `tests/governance/test_merge_gate.py`
- `.opencode/agents/orchestrator.md`

Document this restriction in the spec and plan.

### 5. GitHub Server-Side Backstop

Document required GitHub branch protection / ruleset configuration for master:
- Require status checks before merging
- Require branches to be up to date before merging
- Require review from Code Owners (if applicable)
- Restrict force pushes
- Restrict deletions

## Acceptance Criteria

- [ ] `gh pr merge *` is DENIED for Orchestrator in `.opencode/agents/orchestrator.md`
- [ ] `python scripts/merge_gate.py*` is ALLOWED for Orchestrator
- [ ] `scripts/merge_gate.py` exists and is executable
- [ ] Merge gate validates PR state, tester approval, required CI, and HEAD SHA
- [ ] Merge gate uses `--match-head-commit` for atomic merge
- [ ] No `--force`, `--skip-ci`, or weakening options exist
- [ ] All 23 governance regression tests pass
- [ ] Existing governance suite remains green
- [ ] Evidence file contains honest RED, GREEN, and Refactor sections
- [ ] Independent Tester approves the implementation

## Security Considerations

- The merge gate script is the only path to merge, preventing accidental merges
- HEAD SHA validation prevents race conditions between validation and merge
- No weakening options exist in the script interface
- Control-plane immutability prevents agents from modifying the gate after implementation
- GitHub server-side protection provides a backstop for direct pushes

## UX Considerations

- Orchestrator receives clear error messages when merge is blocked
- Machine-readable output enables programmatic handling
- Exit codes allow shell-level decision making
- Human operators can run the script directly for debugging

## Out of Scope

- Application feature changes
- Media processing changes
- Social publishing changes
- New CI workflows (existing workflows unchanged)
- Database migrations
- API endpoint changes
- Frontend UI changes
- Deployment configuration changes

## Dependencies and Ownership

- Requires GitHub CLI (`gh`) to be installed and authenticated
- Requires read access to PR data, check runs, and evidence files
- Planner owns spec, plan, and test-plan
- Builder implements merge gate script and permission changes
- Tester validates governance tests and merge gate behavior
- Orchestrator coordinates lifecycle and documents GitHub configuration
- Human operator applies GitHub branch protection rules

## Control-Plane Patch

Human operator must manually update these files after merge:

1. `.opencode/agents/orchestrator.md` — permission changes (done by Builder)
2. `scripts/merge_gate.py` — new script (done by Builder)
3. `tests/governance/test_agent_permissions.py` — permission tests (done by Builder)
4. `tests/governance/test_merge_gate.py` — merge gate tests (done by Builder)

GitHub repository settings must be configured manually:
- Branch protection rules for `master`
- Required status checks
- Restrict force pushes
- Restrict deletions