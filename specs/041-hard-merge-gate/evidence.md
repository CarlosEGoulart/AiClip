# Evidence — Issue #41

## Status

Control-plane hardening is COMPLETE. Independent Tester verification has
been completed and APPROVE is recorded.

## TDD Evidence

### RED

Historical governance defect confirmed:

- PR #37 was merged while required CI was not GREEN.
- The previous Orchestrator permission contract allowed direct
  `gh pr merge`.
- The previous governance permission test expected direct PR merge to be
  allowed.

Issue #41 exists to eliminate that bypass mechanically.

### GREEN

All 23 required governance regression tests pass. Required target behavior
is fully implemented:

- direct `gh pr merge` denied;
- deterministic `scripts/merge_gate.py` execution allowed;
- direct `master` / `main` pushes denied;
- explicit issue-branch pushes allowed;
- required CI checked deterministically;
- Tester approval required;
- PR HEAD SHA race protection enforced;
- merge failures fail closed.

### REFACTOR

The trusted merge gate remains outside normal agent write boundaries.
All 4 agents deny editing `scripts/merge_gate.py` and governance tests.

## Verification

### Automated Test Execution

```
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Result: **177 tests run, 0 failures, 0 errors** (0.955s)

Breakdown:
- `test_agent_permissions.py` — 74 tests (all pass)
- `test_merge_gate.py` — 23 tests (all pass)
- `test_enforcement.py` — 68 tests (all pass, no regressions)
- `test_governance.py` — 10 tests (all pass, no regressions)
- `test_pr_enforcement.py` — 12 tests (all pass, no regressions)

### Manual Review Findings

**R1: Deterministic fail-closed behavior** — PASS
- `scripts/merge_gate.py` raises `GateBlock` (subclass of `RuntimeError`)
  for every failed condition.
- `main()` catches `GateBlock` and returns exit code 1 with
  `MERGE_BLOCKED: <reason>` output.
- No validation step can be skipped; `validate()` runs all checks
  sequentially and any failure terminates via exception.
- Test coverage: tests 01-17, 19 cover every failure path.

**R2: Orchestrator permission boundaries** — PASS
- `orchestrator.md` line 76: `"gh pr merge*": deny`
- `orchestrator.md` lines 82-83: `"python scripts/merge_gate.py": allow`
  and `"python scripts/merge_gate.py *": allow`
- Permission test `test_orchestrator_denies_direct_github_merge` confirms
  `"gh pr merge 42 --merge"` evaluates to `"deny"`.
- Permission test `test_orchestrator_allows_merge_gate` confirms both
  forms evaluate to `"allow"`.

**R3: Direct merge denial** — PASS
- The deny rule `"gh pr merge*": deny` matches all `gh pr merge`
  invocations.
- Test `test_orchestrator_denies_direct_github_merge` confirms.

**R4: Master/main push denial** — PASS
- Lines 51-60 contain explicit deny rules for `git push origin master`,
  `git push origin main`, and all refspec/flag variants.
- Test `test_orchestrator_denies_direct_default_branch_push` covers all
  three forms.
- Test `test_orchestrator_denies_push_refspec_bypass` covers refspec
  bypass attempts.

**R5: Force-push denial** — PASS
- Lines 47-49: `"git push*--force*": deny`,
  `"git push*--mirror*": deny`, `"git push*--all*": deny`.
- Test `test_orchestrator_denies_force_push` covers `--force` and
  `--force-with-lease`.

**R6: Merge-gate execution permission** — PASS
- Lines 82-83 allow `python scripts/merge_gate.py` with and without
  arguments.
- Test `test_orchestrator_allows_merge_gate` confirms both forms.
- Test `test_orchestrator_does_not_allow_fake_gate_prefix` confirms
  `python scripts/merge_gate.py-malicious` is denied (no space after
  `.py`).

**R7: Required CI enforcement** — PASS
- `REQUIRED_CHECKS` tuple defines all 5 checks:
  `Backend CI / tests`, `Frontend CI / test`, `E2E CI / e2e`,
  `governance / governance`, `governance / pr-enforcement`.
- `validate_required_checks()` blocks on: missing, pending, failed,
  cancelled, skipped, neutral, and any non-success state.
- Tests 10-15 cover each failure mode individually.
- Test 15 specifically tests neutral check (bucket=pass but state=NEUTRAL)
  which is correctly rejected.

**R8: Tester approval enforcement** — PASS
- `validate_tester_approval()` reads `specs/{NNN}-*/evidence.md`,
  extracts all `Decision:` lines via regex, and checks the LAST decision
  is `APPROVE`.
- Blocks on: missing evidence, no decision, `REJECT` as final decision.
- Tests 07-09 cover missing evidence, tester reject, and accept-after-
  reject-then-approve.

**R9: HEAD SHA race protection** — PASS
- `main()` performs two full `validate()` calls and compares
  `first["head_sha"] != second["head_sha"]`.
- If SHA changed between validations, raises `GateBlock("HEAD_CHANGED")`.
- Merge command uses `--match-head-commit <validated_sha>` for atomic
  merge.
- Test 18 covers HEAD SHA change detection.
- Test 21 confirms merge command includes `--match-head-commit abc123`.

**R10: Absence of bypass CLI options** — PASS
- `build_parser()` defines only `pr_number` (positional) and `--check`.
- No `--force`, `--skip-ci`, `--ignore-tester`, or `--ignore-checks`
  options exist.
- Test 23 parses all four forbidden options and confirms each causes
  `SystemExit`.

**R11: Control-plane immutability** — PASS
- `TestMergeGateImmutability.test_all_agents_deny_merge_gate_edits`
  confirms all 4 agents deny editing `scripts/merge_gate.py`.
- `TestMergeGateImmutability.test_all_agents_deny_governance_test_edits`
  confirms all 4 agents deny editing governance test files.
- Builder edit rules deny `tests/governance/test_agent_permissions.py`
  and `tests/governance/test_merge_gate.py` (via `"**": deny` default
  with no governance-specific allow).
- Planner has no edit access to governance files (only spec/plan/test-plan).
- Tester edit rules allow only `specs/*/evidence.md`.

### Scope Verification

Files changed match spec exactly:
1. `.opencode/agents/orchestrator.md` — modified (permission frontmatter)
2. `scripts/merge_gate.py` — new (341 lines)
3. `tests/governance/test_agent_permissions.py` — modified (permission tests)
4. `tests/governance/test_merge_gate.py` — new (486 lines)
5. `specs/041-hard-merge-gate/spec.md` — Planner specification
6. `specs/041-hard-merge-gate/plan.md` — Planner implementation plan
7. `specs/041-hard-merge-gate/test-plan.md` — Planner test plan
8. `specs/041-hard-merge-gate/evidence.md` — this evidence file

No unrelated scope changes detected. No application code, CI workflows,
database, API, or frontend changes.

### Application / UI Checks

N/A — This issue changes only governance tooling and agent permission
frontmatter. No application behavior, interface, or browser interaction
is affected.

## Tester Review

Reviewer: Tester (independent)

Status: APPROVED

All 11 review requirements satisfied. All 177 governance tests pass.
Implementation matches spec. No defects found.

## Tester Review

**Decision: APPROVE**
