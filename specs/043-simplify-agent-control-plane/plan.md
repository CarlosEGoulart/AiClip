# Implementation Plan: Simplify Agent Control Plane

## Issue

#43 — refactor(governance): simplify agent control-plane

## Context

Issue #43 is a bootstrap/control-plane migration. The implementation and governance regression tests were **human-authored** before this planning session as an explicit bootstrap exception because previous runtime agents could not safely modify their own control plane.

This plan formalizes what already exists in the working tree and defines the verification steps required before merge.

## Bootstrap Exception

The following artifacts already exist in the working tree as human-authored work:

- `.opencode/agents/planner.md` — role-based permission frontmatter
- `.opencode/agents/builder.md` — role-based permission frontmatter
- `.opencode/agents/tester.md` — role-based permission frontmatter
- `.opencode/agents/orchestrator.md` — role-based permission frontmatter
- `scripts/merge_gate.py` — deterministic fail-closed merge gate
- `tests/governance/test_agent_permissions.py` — role-invariant regression tests
- `tests/governance/test_governance.py` — governance contract checks
- `tests/governance/test_merge_gate.py` — merge gate regression tests

## Implementation Strategy

Since the implementation already exists, this plan defines the independent verification required to confirm the working tree matches the specification and to validate all governance invariants hold.

### Step 1: Verify Agent Permission Frontmatter

Verify each agent file contains correct role-based permission patterns:

| Agent | Key Permission Pattern |
|---|---|
| `planner.md` | bash: deny (flat string); edit: only `specs/*/{spec,plan,test-plan}.md` |
| `builder.md` | bash: default deny with broad tool prefixes allowed; edit: `apps/**`, `services/**`, `packages/**`, dependency manifests, evidence; deny: `.opencode/**`, `tests/governance/**`, `scripts/merge_gate.py`, planner files |
| `tester.md` | bash: default deny with verification tools allowed; edit: only `specs/*/evidence.md` |
| `orchestrator.md` | bash: default deny with lifecycle commands allowed; deny: `gh pr merge`, push master/main, force push; edit: only `README.md`, `docs/**`, `specs/*/evidence.md`; deny: `.opencode/**`, `tests/governance/**`, `scripts/merge_gate.py`, planner files |

### Step 2: Verify merge_gate.py Verification Chain

Confirm `merge_gate.py` implements all 14 required verification steps:

1. `fetch_pr()` — fetches PR data via `gh pr view --json`
2. `validate_pr()` — checks PR OPEN, not draft, base=master, mergeable, HEAD SHA present, branch naming policy, branch-issue match
3. `extract_issue_number()` — finds exactly one `Closes #N`
4. `validate_issue()` — checks referenced issue is OPEN
5. `validate_tester_approval()` — finds `evidence.md`, reads last `Decision:` line, must be APPROVE
6. `validate_required_checks()` — all 5 mandatory checks exist and are SUCCESS
7. `validate()` — orchestrates all validations
8. Double validation before merge — HEAD SHA must match between first and second pass
9. `perform_merge()` — uses `--match-head-commit` with validated SHA
10. Post-merge confirmation — verifies `mergedAt` is set
11. `--check` flag — validates without merging
12. No bypass options in `argparse` parser
13. Fail-closed — any `GateBlock` exception returns `MERGE_BLOCKED` with non-zero exit
14. Required checks tuple: `Backend CI / tests`, `Frontend CI / test`, `E2E CI / e2e`, `governance / governance`, `governance / pr-enforcement`

### Step 3: Verify Governance Tests

Confirm governance tests validate security invariants:

**`test_agent_permissions.py`:**
- Builder denies governance test edits, planner files, control-plane files, env secrets
- Builder allows application code, evidence, application test edits
- Planner allows spec files, denies evidence, production code, bash
- Tester allows only evidence, denies production code, planner files, git mutation, lifecycle
- Orchestrator allows normal git lifecycle, GitHub metadata, merge gate; denies direct merge, default branch push, force push, production code
- All agents deny `.env` variants and allow `.env.example`
- No agent depends on specific issue numbers

**`test_governance.py`:**
- Required artifacts exist and are non-empty
- Agent frontmatter modes and permissions shape are valid
- Representative edit/task/bash decisions match role boundaries
- Six skills parse correctly
- Project state has six required headings
- CI workflow shape is correct
- YAML loader rejects malformed and duplicate keys
- Permission helper rejects all-allow policies
- Historical issue bundles are complete

**`test_merge_gate.py`:**
- Draft PR blocks
- Wrong base branch blocks
- Invalid branch naming blocks
- Branch-issue mismatch blocks
- Missing/multiple Closes reference blocks
- Closed issue blocks
- Missing evidence blocks
- Missing tester decision blocks
- Tester reject blocks
- Latest approve wins
- Missing/pending/failed/cancelled/skipped/neutral/unknown checks block
- Closed/conflicting PR blocks
- Check mode does not merge
- HEAD change blocks merge
- Green path merges once with SHA
- No bypass options exist

### Step 4: Execute Governance Tests

```bash
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

All tests must pass. Any failure blocks merge.

### Step 5: Confirm No Scope Expansion

Verify that no M3 product work, application behavior, or historical PR rewriting exists in the changeset. This issue contains only:

- Agent permission frontmatter changes
- merge_gate.py implementation
- Governance regression tests

## Dependencies

- PyYAML (`tests/governance/requirements.txt`: `PyYAML==6.0.3`)
- Python 3
- `gh` CLI (for merge gate runtime, not needed for unit tests)

## Risk Assessment

- **Low risk:** This is a governance-only change. No application behavior is affected.
- **Verification is critical:** The entire value of this issue is that governance invariants hold. Verification failure means the control plane is not trustworthy.
- **Rollback is straightforward:** If governance tests fail, the previous agent configurations can be restored.

## Evidence Collection

After verification, evidence should be recorded in `specs/043-simplify-agent-control-plane/evidence.md` documenting:

1. All governance tests pass
2. Agent permission patterns match specification
3. merge_gate.py verification chain is complete
4. No application behavior changes exist
5. No M3 product work exists
