# Specification — Issue #125: Governance Preflight Hooks

## Title

Governance Preflight Hooks

## Description

Add lightweight preflight validation that catches common workflow and governance violations before expensive tests or pull-request review. A single command runs deterministic checks for branch/issue association, required spec bundle, working-tree hygiene, generated artifacts, and required metadata. CI remains authoritative; local hooks are optional and documented.

## Problem

Developers can push branches and open PRs that violate repository governance conventions (branch naming, missing spec bundle, uncommitted working-tree changes, build artifacts, non-conventional commits). These violations are only caught late, during CI governance enforcement or merge gate review, wasting time and requiring rework. There is no fast, local, deterministic preflight check that tells a contributor exactly which rule is violated and how to fix it before running expensive test suites.

## Goal

Implement a deterministic preflight validation command that:

1. Validates branch name against `@carlosegoulart/{issue}/{type}/{desc}` and associates it with the active issue.
2. Verifies the required spec bundle (spec.md, plan.md, test-plan.md) exists for the active issue.
3. Verifies working-tree hygiene (no unexpected uncommitted changes).
4. Detects generated artifacts that must not be committed.
5. Verifies required metadata (conventional commit messages).
6. Returns non-zero with concise remediation on any violation.
7. Modifies no files automatically.
8. Is CI-authoritative; local usage is optional and documented.

## Preflight Contract

### Active issue context

The preflight command resolves the active issue from the current branch name (`@carlosegoulart/{n}/...`). When no such branch exists, it reports the missing association as a violation.

### Rule P1 — Branch/issue association

- Branch name must match `^@carlosegoulart/(\d+)/(feat|fix|chore|refactor|docs|test)/([a-z0-9][a-z0-9-]*)$`.
- The issue number extracted from the branch must correspond to an existing issue directory `specs/{n:03d}-*/`.
- Remediation: rename the branch or create the missing issue directory.

### Rule P2 — Required spec bundle

For the active issue, the directory `specs/{n:03d}-*/` must contain:
- `spec.md` (non-empty regular file)
- `plan.md` (non-empty regular file)
- `test-plan.md` (non-empty regular file)

`evidence.md` is intentionally NOT required at preflight time; it is produced by Builder/Tester during the issue lifecycle.
- Remediation: create the missing spec bundle files per AGENTS.md.

### Rule P3 — Working-tree hygiene

- `git status --porcelain` must report no changes relative to HEAD for tracked files outside the active issue's spec bundle files (spec.md, plan.md, test-plan.md), which are Planner-authored.
- Remediation: commit or stash unrelated changes.

### Rule P4 — Generated artifacts

- No generated/build artifacts may be present or tracked: `node_modules/`, `dist/`, `build/`, `__pycache__/`, `*.pyc`, `.pytest_cache/`, `.env`, `*.log`, etc.
- Remediation: remove or gitignore the artifact.

### Rule P5 — Required metadata

- Commit messages on the branch must follow Conventional Commits `<type>(<scope>): <subject>` with allowed types from AGENTS.md.
- Remediation: reword commits.

## Single Command

Command: `python scripts/preflight.py`

Behavior:
- Exits 0 when all rules pass; prints "Preflight checks passed".
- Exits non-zero when any rule fails; prints each failing rule with its remediation line.
- Modifies no files.
- Reads no secrets.
- Works in local developer environments and CI.

CI:
- The governance workflow runs `python scripts/preflight.py` as an authoritative step.
- A local pre-commit hook is optional and documented (sample `.git/hooks/pre-commit`), never enforced by this repo.

## Deliverables

### New Files

- `scripts/preflight.py` — Deterministic preflight checks (pure functions, no side effects, no network, no secrets).
- `tests/governance/test_preflight.py` — Unit tests for all preflight checks (success, failure, edge cases).
- `specs/125-governance-preflight-hooks/spec.md`
- `specs/125-governance-preflight-hooks/plan.md`
- `specs/125-governance-preflight-hooks/test-plan.md`

### Modified Files

- `.github/workflows/governance.yml` — Add preflight step as authoritative CI check.
- `docs/project-state.md` — Add preflight hooks capability.

## Acceptance Criteria

- [ ] The preflight contract is documented and does not conflict with AGENTS.md or CI.
- [ ] A single command (`python scripts/preflight.py`) runs the checks consistently in supported environments.
- [ ] Missing required files, invalid branch/issue context, and generated artifacts are detected.
- [ ] Valid state passes; failures identify the exact rule and fix.
- [ ] Tests cover success, failure, and edge cases.
- [ ] No secrets are read or printed unnecessarily; CI remains the final authority.

## Out of Scope

- Rewriting historical commits or branches.
- Automatic file modification (preflight never writes to the working tree).
- External policy engines or paid services.
- Altering agent permissions or governance policy (explicitly constrained by the issue).
- Application-layer code changes.
- Enforcing local pre-commit hooks (CI is authoritative; local hooks optional).

## Security Considerations

- No `.env`, secret, token, or credential files are read or printed.
- The command only inspects file names, branch names, commit subjects, and git status output.
- CI remains the final authority; local preflight is advisory.

## Test Scenarios

- T1: Valid branch name with matching issue directory → pass.
- T2: Invalid branch name format → fail, names rule P1.
- T3: Branch issue number with no matching specs directory → fail, names rule P1.
- T4: Complete spec bundle (spec.md, plan.md, test-plan.md) → pass rule P2.
- T5: Missing spec.md → fail rule P2, names missing file.
- T6: Working tree clean → pass rule P3.
- T7: Unrelated uncommitted changes → fail rule P3, names the file.
- T8: Generated artifact present (node_modules, __pycache__) → fail rule P4.
- T9: Conventional commit message → pass rule P5.
- T10: Non-conventional commit message → fail rule P5.
- T11: No secrets read or printed.
- T12: Exit code 0 on all-pass; non-zero on any violation.

## Dependencies

- Python 3.12 (existing CI setup).
- PyYAML 6.0.3 (already present in tests/governance/requirements.txt).
- Existing governance validators in `tests/governance/validators.py` (for commit-message patterns).
- Existing `.github/workflows/governance.yml` (will be extended).
- Existing `scripts/merge_gate.py` (not modified; remains authoritative for merge).
