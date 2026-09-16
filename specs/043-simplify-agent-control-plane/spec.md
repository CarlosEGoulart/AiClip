# Specification: Simplify Agent Control Plane

## Issue

#43 — refactor(governance): simplify agent control plane

## Status

SPEC_READY

## Motivation

The previous permission model was excessively command-specific. Every routine development operation — running `composer install`, executing `npm test`, or pushing a feature branch — required an explicit allow rule in the agent frontmatter. This caused repeated human intervention for normal development operations and produced brittle configurations that broke whenever a new legitimate tool or command was introduced.

The previous approach enumerated individual shell commands for every tool an agent might need, creating a maintenance burden where adding a new testing framework or build tool required modifying agent configuration files. This contradicted the goal of autonomous agent operation within defined role boundaries.

## Design

### Core Principle: Role-Based Permissions

The new model is **role-based rather than command-by-command**. Each agent has a defined role with broad permission categories. The system trusts agents to operate within their role boundary, and security is enforced by **what roles cannot do** (negative constraints) rather than listing every permitted command.

### The Four Roles

The four roles remain unchanged:

| Role | Primary Responsibility |
|---|---|
| **Orchestrator** | Lifecycle coordination, Git/GitHub maintenance, merge gate execution |
| **Planner** | Specification and planning artifacts |
| **Builder** | Implementation and application tests |
| **Tester** | Independent verification and evidence recording |

### Role Boundaries

#### Planner

**Owns:** `specs/*/spec.md`, `specs/*/plan.md`, `specs/*/test-plan.md`

**Cannot:** execute shell commands, edit production code, edit evidence, modify `.opencode/**`, modify `tests/governance/**`, modify `scripts/merge_gate.py`, create or close issues, create or modify branches, commit or push, create or modify Pull Requests.

**Rationale:** Planner is a pure design role. It produces structured artifacts and has no need for runtime tooling.

#### Builder

**Can:** perform broad normal development work:
- PHP / Laravel commands (`php artisan *`, `vendor/bin/*`)
- Composer commands (`composer *`)
- npm commands (`npm *`)
- npx commands (`npx *`)
- Docker Compose commands (`docker compose *`)
- Read-only Git inspection (`git status`, `git diff`, `git log`)

**Cannot:**
- Own Git lifecycle (`git add`, `git commit`, `git push`, `git reset`, `git rebase`, `git cherry-pick`)
- Execute any `gh` command
- Modify trusted governance/control-plane files (`.opencode/**`, `tests/governance/**`, `scripts/merge_gate.py`)
- Modify Planner-owned files (`specs/*/spec.md`, `specs/*/plan.md`, `specs/*/test-plan.md`)
- Modify `.github/workflows/governance.yml`
- Modify application-local agent/control-plane files

**Rationale:** Builder implements features. It needs broad tool access to write code, run tests, and install dependencies. It must not own Git lifecycle or modify trust boundaries.

#### Tester

**Can:** execute broad verification:
- All PHP/Laravel test commands
- All npm/npx test commands
- Docker Compose commands
- Python governance test commands
- Read-only Git inspection
- Read-only `gh` inspection (`gh pr view`, `gh pr checks`, `gh issue view`, etc.)
- OpenCode debug commands

**Cannot:**
- Edit any file except `specs/*/evidence.md`
- Modify production implementation
- Modify application tests
- Execute Git lifecycle commands (`git commit`, `git push`)
- Execute `gh pr merge` or any issue/PR mutation command
- Repair defects it discovers

**Rationale:** Tester validates independently. It can run anything needed for verification but can only record its decision in evidence.md. It never repairs implementation.

#### Orchestrator

**Can:**
- Execute normal Git lifecycle: `fetch`, `checkout`/`switch`, `add`, `commit`, `rebase`, `cherry-pick`, `reset`
- Execute broad GitHub issue and PR metadata management: `gh issue create`, `gh issue edit`, `gh issue close`, `gh issue reopen`, `gh pr create`, `gh pr edit`, `gh pr checks`, `gh pr close`, `gh pr reopen`, `gh pr ready`
- Push only to named feature branches matching `@carlosegoulart/*` pattern
- Execute `python scripts/merge_gate.py` and `python scripts/merge_gate.py --check`
- Delegate to Planner, Builder, and Tester via `task`

**Cannot:**
- Execute direct `gh pr merge`
- Push `master` or `main`
- Force push (any `--force`, `--force-with-lease`, `--mirror`, `--all`, `:*` patterns)
- Edit production implementation
- Edit Planner-owned files (`specs/*/spec.md`, `specs/*/plan.md`, `specs/*/test-plan.md`)
- Edit trusted control-plane files (`.opencode/**`, `tests/governance/**`, `scripts/merge_gate.py`)
- Bypass Tester
- Bypass CI

**Rationale:** Orchestrator coordinates the lifecycle. It owns Git and GitHub metadata but cannot merge directly — it must go through the deterministic merge gate.

### Trusted Control-Plane Files

The following paths are **trusted control-plane files** and must not be writable by normal runtime agents:

- `.opencode/**` — agent definitions, skill definitions
- `tests/governance/**` — governance regression tests
- `scripts/merge_gate.py` — deterministic merge gate

Orchestrator, Builder, and Tester all have explicit deny rules for these paths. Planner is denied bash entirely and only writes spec/plan/test-plan files.

### Merge Execution Model

The **only merge execution path** is:

```
python scripts/merge_gate.py <PR_NUMBER>
```

Readiness can be checked without merging:

```
python scripts/merge_gate.py <PR_NUMBER> --check
```

Direct `gh pr merge` is explicitly forbidden for Orchestrator. The merge gate enforces all preconditions deterministically.

### merge_gate.py Verification Requirements

The merge gate must fail closed and verify at minimum:

1. PR exists (GH command succeeds with valid JSON)
2. PR is OPEN (not CLOSED, not MERGED)
3. PR is not draft (`isDraft` is false)
4. Base branch is `master` (`baseRefName` matches `DEFAULT_BASE_BRANCH`)
5. Head branch matches project naming policy (`@carlosegoulart/{issue}/{type}/{description}` with regex)
6. Exactly one matching `Closes #N` in PR body
7. Referenced issue is OPEN
8. Tester final Decision is APPROVE (last `Decision:` line in `evidence.md`)
9. Mandatory CI checks exist (all checks in `REQUIRED_CHECKS` tuple are present)
10. Mandatory CI checks are SUCCESS (bucket=pass, state=SUCCESS; pending, fail, cancel, skipping, skipped, neutral all block)
11. PR is MERGEABLE (mergeable state is `MERGEABLE`)
12. HEAD SHA is stable (validated twice before merge to detect mid-flight changes)
13. Actual merge uses `--match-head-commit` with the validated SHA
14. No bypass/force options exist in the CLI parser

### Governance Test Strategy

Governance tests validate **security/role invariants** rather than enumerating every possible routine shell command. This means:

- Tests verify that each agent's permission frontmatter contains the correct deny/allow patterns for critical boundary paths
- Tests verify that Planner cannot edit production code or execute commands
- Tests verify that Builder cannot modify governance files or own Git lifecycle
- Tests verify that Tester can only edit evidence.md and cannot repair implementation
- Tests verify that Orchestrator owns lifecycle but cannot merge directly or push default branches
- Tests verify that `.env` secrets are protected across all agents
- Tests verify that no agent configuration depends on a specific issue number
- Tests verify merge gate behavior for every failure mode

This approach is maintainable: adding a new tool or command does not require updating governance tests, because tests check security invariants rather than individual command permissions.

## Out of Scope

- No M3 product work belongs to this issue
- No application behavior belongs to this issue
- No historical PR rewriting belongs to this issue
- No new application features
- No database changes
- No new tests beyond governance regression tests
- No UI changes

## Security Considerations

- All agents deny `.env` reads (root, nested, and all variants) except `.env.example`
- Trusted control-plane files are denied across all agents except Planner (who is denied bash entirely)
- Orchestrator cannot force-push, push default branches, or merge directly
- Merge gate enforces SHA stability to prevent race conditions
- Merge gate has no bypass or force options
- `persist-credentials: false` is enforced in governance CI workflow

## Dependencies

- PyYAML must be available for governance tests (`tests/governance/requirements.txt` specifies `PyYAML==6.0.3`)
- `gh` CLI must be available for merge gate operations
- Python 3 must be available for merge gate execution

## Acceptance Criteria

- [ ] All four agent frontmatter files implement role-based permission patterns
- [ ] Planner can only edit `specs/*/spec.md`, `specs/*/plan.md`, `specs/*/test-plan.md`
- [ ] Builder can broadly perform development work but cannot own Git/GitHub lifecycle
- [ ] Tester can execute broad verification but can only edit `evidence.md`
- [ ] Orchestrator owns Git/GitHub lifecycle but cannot merge directly or push default branches
- [ ] `scripts/merge_gate.py` enforces all 14 verification requirements
- [ ] Governance tests validate security/role invariants
- [ ] No application behavior changes are included
- [ ] No M3 product work is included
- [ ] All governance tests pass
