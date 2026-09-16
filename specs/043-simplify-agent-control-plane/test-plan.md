# Test Plan: Simplify Agent Control Plane

## Issue

#43 — refactor(governance): simplify agent-control-plane

## Test Strategy

This issue is a governance-only change. All tests are governance regression tests that validate security/role invariants. There is no application behavior to test, no UI to validate, and no API endpoints to exercise. Application unit, API, E2E, Playwright, visual, and accessibility interaction checks are **N/A** with explicit reason: governance control-plane migration.

## Test Environment

- Python 3 with PyYAML (`tests/governance/requirements.txt`)
- `unittest` test runner
- No application server required
- No database required
- No browser required

## Test Execution

```bash
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

## Test Suites

### 1. Agent Permission Invariants (`test_agent_permissions.py`)

**Purpose:** Validate that each agent's parsed YAML frontmatter enforces correct role boundaries via last-match glob evaluation.

**Builder Tests:**
- `test_builder_denies_governance_test_edits` — Builder cannot edit `tests/governance/test_governance.py` or `tests/governance/test_agent_permissions.py`
- `test_builder_allows_application_test_edits` — Builder can edit `apps/api/tests/Feature/ExampleTest.php` and `apps/web/src/__tests__/App.test.tsx`
- `test_builder_denies_planner_files` — Builder cannot edit `specs/*/spec.md`, `specs/*/plan.md`, `specs/*/test-plan.md`
- `test_builder_allows_evidence` — Builder can edit `specs/*/evidence.md`
- `test_builder_allows_application_code` — Builder can edit `apps/api/app/**/*.php` and `apps/web/src/**/*.tsx`
- `test_builder_denies_control_plane_files` — Builder cannot edit `apps/api/AGENTS.md`, `apps/api/opencode.json`, `apps/api/boost.json`, `apps/api/.agents/**`, `apps/api/.claude/**`
- `test_builder_denies_agent_config` — Builder cannot edit `.opencode/agents/builder.md` or `.opencode/agents/planner.md`
- `test_builder_denies_env_secrets` — Builder cannot read `.env`, `apps/api/.env`, `.env.local`, `.env.staging`
- `test_builder_allows_env_example` — Builder can read `.env.example` and `apps/api/.env.example`
- `test_builder_bash_allows_laravel_tests` — Builder can execute `php artisan test` and `vendor/bin/pest`
- `test_builder_bash_denies_git` — Builder cannot execute `git commit` or `git push`
- `test_bash_requires_command_prefix` — Builder bash rules start with `**` deny

**Planner Tests:**
- `test_planner_allows_spec_files` — Planner can edit `specs/*/spec.md`, `specs/*/plan.md`, `specs/*/test-plan.md`
- `test_planner_denies_evidence` — Planner cannot edit `specs/*/evidence.md`
- `test_planner_denies_production_code` — Planner cannot edit application source files
- `test_planner_bash_denied` — Planner bash is flat `"deny"`
- `test_planner_denies_env_secrets` — Planner cannot read `.env` variants
- `test_planner_allows_env_example` — Planner can read `.env.example`

**Tester Tests:**
- `test_tester_allows_evidence_only` — Tester can edit `specs/*/evidence.md`
- `test_tester_denies_production_code` — Tester cannot edit application source files
- `test_tester_denies_planner_files` — Tester cannot edit `specs/*/spec.md`, `specs/*/plan.md`, `specs/*/test-plan.md`
- `test_tester_bash_allows_test_commands` — Tester can execute test commands and governance test discovery
- `test_tester_bash_denies_git_mutation` — Tester cannot execute `git commit` or `git push`
- `test_tester_bash_allows_git_readonly` — Tester can execute `git status`, `git diff`, `git log`
- `test_tester_bash_denies_lifecycle` — Tester cannot execute `gh pr merge` or `gh issue close`
- `test_tester_denies_env_secrets` — Tester cannot read `.env` variants
- `test_tester_allows_env_example` — Tester can read `.env.example`

**Orchestrator Tests:**
- `test_orchestrator_allows_normal_git_lifecycle` — Orchestrator can execute `git commit`, `git add`, `git branch`, `git fetch`, `git rebase`, `git cherry-pick`
- `test_orchestrator_blocks_dangerous_git_lifecycle` — Orchestrator cannot push `master`/`main`, cannot force-push
- `test_orchestrator_allows_github_lifecycle_metadata` — Orchestrator can execute `gh pr create`, `gh pr edit`, `gh pr checks`, `gh issue create`, `gh issue close`, `gh issue reopen`
- `test_orchestrator_direct_merge_is_denied` — Orchestrator cannot execute `gh pr merge`
- `test_orchestrator_merge_gate_is_allowed` — Orchestrator can execute `python scripts/merge_gate.py`
- `test_orchestrator_delegates_to_known_agents` — Orchestrator task allows `planner`, `builder`, `tester`
- `test_orchestrator_denies_unknown_agents` — Orchestrator task denies `explorer`, `unknown-agent`
- `test_orchestrator_cannot_edit_production_code` — Orchestrator cannot edit application source files
- `test_orchestrator_allows_project_state` — Orchestrator can edit `docs/project-state.md`
- `test_orchestrator_denies_env_secrets` — Orchestrator cannot read `.env` variants
- `test_orchestrator_allows_env_example` — Orchestrator can read `.env.example`

**Cross-Agent Tests:**
- `TestEnvProtection` — All 4 agents deny `.env`, `.env.*`, nested `.env.*` variants; all allow `.env.example`
- `TestNoIssueSpecificDependencies` — No agent references specific issue numbers

**Readme Structure Tests:**
- `TestReadmeStructure` — Root README retains product identity, tech stack, repo structure, TDD, agent system, testing policy, local development, definition of done

### 2. Governance Contract (`test_governance.py`)

**Purpose:** Validate that governance artifacts, frontmatter, CI workflow, and project state meet structural requirements.

**Tests:**
- `test_g1_required_artifacts_exist_nonempty` — All 26 required files exist and are non-empty
- `test_g2_agent_frontmatter_modes_permissions_shape` — Agent frontmatter has correct description, mode, permission structure, valid tools, valid actions
- `test_g3_representative_edit_decisions` — Representative edit decisions match role boundaries for all 4 agents
- `test_g4_representative_task_shell_decisions` — Representative task and bash decisions match role boundaries
- `test_g5_six_skills_parse` — All 6 skill directories exist with valid frontmatter
- `test_g6_project_state_six_headings` — `docs/project-state.md` has exactly 6 required headings with content
- `test_g7_basic_ci_shape` — Governance CI workflow has correct triggers, permissions, checkout, python setup, test command
- `test_g8_loader_rejects_malformed_and_duplicate_keys` — YAML loader rejects malformed YAML and duplicate keys
- `test_g9_permission_helper_rejects_all_allow` — `decide()` helper correctly evaluates last-match glob semantics
- `test_g10_historical_issue_bundles_complete` — All issue directories have complete SDD bundles

### 3. Merge Gate Regression (`test_merge_gate.py`)

**Purpose:** Validate that `scripts/merge_gate.py` blocks merge for every defined failure mode and succeeds only when all conditions are met.

**Failure Mode Tests:**
- `test_draft_blocks` — PR_DRAFT
- `test_wrong_base_blocks` — WRONG_BASE_BRANCH
- `test_invalid_branch_blocks` — INVALID_HEAD_BRANCH
- `test_branch_issue_mismatch_blocks` — BRANCH_ISSUE_MISMATCH
- `test_missing_closes_blocks` — ISSUE_REFERENCE_INVALID
- `test_multiple_closes_blocks` — ISSUE_REFERENCE_INVALID
- `test_closed_issue_blocks` — ISSUE_NOT_OPEN
- `test_missing_evidence_blocks` — EVIDENCE_NOT_FOUND
- `test_missing_tester_decision_blocks` — TESTER_NOT_APPROVED
- `test_tester_reject_blocks` — TESTER_REJECTED
- `test_missing_required_check_blocks` — CHECK_MISSING
- `test_pending_check_blocks` — CHECK_PENDING
- `test_failed_check_blocks` — CHECK_FAILED
- `test_cancelled_check_blocks` — CHECK_CANCELLED
- `test_skipped_check_blocks` — CHECK_SKIPPED
- `test_neutral_check_blocks` — CHECK_NEUTRAL
- `test_unknown_state_blocks` — CHECK_NOT_SUCCESS
- `test_closed_pr_blocks` — PR_NOT_OPEN
- `test_conflicting_pr_blocks` — PR_NOT_MERGEABLE
- `test_head_change_blocks_merge` — HEAD_CHANGED

**Success Path Tests:**
- `test_latest_approve_wins` — Multiple decisions; last APPROVE is accepted
- `test_check_mode_does_not_merge` — `--check` validates without merging
- `test_green_path_merges_once_with_sha` — Full green path: validate, double-validate, merge with `--match-head-commit`, confirm `mergedAt`

**Security Tests:**
- `test_no_bypass_options_exist` — CLI parser rejects `--force`, `--skip-ci`, `--ignore-ci`, `--ignore-tester`, `--ignore-checks`

## Application Checks

N/A — This is a governance-only change. No application behavior is affected.

- **Unit tests:** N/A (no application code changes)
- **Integration tests:** N/A (no application behavior changes)
- **E2E tests:** N/A (no user-facing workflow changes)
- **Playwright validation:** N/A (no UI changes)
- **Visual review:** N/A (no UI changes)
- **Accessibility review:** N/A (no UI changes)

## Expected Results

All 50+ governance tests must pass. Any single failure blocks merge because the entire value of this issue is that governance invariants hold.

## Evidence Requirements

After test execution, record in `specs/043-simplify-agent-control-plane/evidence.md`:

1. Full test output showing all tests pass
2. Confirmation that no application code was modified
3. Confirmation that no M3 product work exists in changeset
4. Final decision: `Decision: APPROVE` (only if all tests pass)
