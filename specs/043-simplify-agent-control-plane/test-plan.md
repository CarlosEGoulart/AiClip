# Test Plan: Simplify Agent Control Plane

## Issue

#43 — refactor(governance): simplify agent-control-plane

## Test Strategy

Issue #43 / PR #44 continues on `@carlosegoulart/43/refactor/simplify-agent-control-plane` from published baseline `e583495a90b96350f8d92e7e732c2829a4bc5a1e`. Preserve the original governance regression contract and additionally verify only the human-authorized Media stale-fetch correction/pre-navigation E2E setup.

The original human-authored control-plane implementation/tests predate SDD; no test-first bootstrap RED is claimed. Its historical 170-test governance PASS, 24-test merge-gate PASS, and original approval are not renewed verification. The new Media behavior requires a genuine deterministic assertion-based RED against the unchanged hook **before** production edits, GREEN, refactor reruns, and independent Tester interaction with the running app. Application/browser checks are no longer globally N/A.

Builder's only application edit paths are `apps/web/src/features/media/hooks/index.test.tsx`, `apps/web/src/features/media/hooks/index.ts`, and `apps/web/e2e/media.spec.ts`. Planner edits only this bundle's `spec.md`, `plan.md`, and `test-plan.md`; evidence is separate and Tester owns the final independent decision. All `.opencode/**`, `tests/governance/**`, and `scripts/merge_gate.py` remain immutable to every runtime agent. No changes to governance validators/tests, CI, permission rules, dependency manifests/lockfiles, or timeout/retry configuration are authorized.

## Test Environment

- Python 3 with PyYAML (`tests/governance/requirements.txt`)
- `unittest` for governance; merge-gate unit tests mock GitHub operations and require no live merge or authenticated `gh`
- Existing frontend dependencies and Node.js compatible with the checked-in stack (Node 24 in CI); Vitest/jsdom/React Testing Library and existing `src/test/setup.ts`
- E2E/browser review: PHP 8.3/extensions and Composer dependencies, disposable PostgreSQL with migrations, Laravel app key/session environment, Chromium/system dependencies, browser tooling/artifact access, and free ports 5173/8000
- `playwright.config.ts` manages Laravel/Vite servers with `reuseExistingServer: false`; leave its mobile (390x844), tablet (768x1024), desktop (1440x900), timeouts, and `retries: 0` unchanged
- Real auth/projects and invalid-file validation require the API/database. Successful Media storage requests remain intercepted; no worker, FFmpeg/FFprobe, new storage infrastructure, or model downloads

Builder/Tester use only commands permitted for their role. Tester executes Python governance commands and OpenCode permission inspection; Planner executes none. Missing installations, service access, network permissions, browser access, or a prepared Laravel environment require authorized provisioning/human help through Orchestrator. Do not inspect real `.env`, change permissions, or route a denied operation through another tool. Setup failure is neither RED nor PASS, and blocked mandatory verification prevents approval.

## Test Execution — Existing Governance (Tester, Repository Root)

```bash
python -m unittest discover -s tests/governance -p 'test_*.py' -v
python -m unittest tests/governance/test_merge_gate.py -v
```

Reinspect role boundaries using the existing permitted `opencode debug agent planner`, `opencode debug agent builder`, `opencode debug agent tester`, and `opencode debug agent orchestrator` commands when available. Never test dangerous commands by actually pushing, merging, reading secrets, or editing protected files. Use the existing invariant tests and parsed permissions. Any failed or unavailable mandatory boundary verification is escalated, not repaired by a runtime agent.

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

### Required Media RED and Unit/Integration Regressions

Extend the existing hook test file using API-module mocks (`getMediaAssets`, `uploadMediaAsset`, `deleteMediaAsset`), typed deferred promises, RTL render/renderHook/rerender, and `act`. Test the real hook. Existing auth hook tests provide a deferred-promise/StrictMode pattern; do not edit or refactor them. Preserve existing isolated-operation/error assertions.

**M1 — Required assertion-based RED chronology:**

1. Render for project A. Start `fetchMedia()` and prove the API call began with A while its promise remains unresolved; verify loading is active.
2. Call `uploadFile(file)`, resolve its response with a valid A asset, and await the upload completion. Assert success and that the returned asset is present exactly once, with unchanged metadata.
3. Resolve the **already-started** GET with `data: []` only after step 2's state assertion. Await the original fetch and React updates inside `act`.
4. Assert that the uploaded asset is still present. Against the current unmodified hook this must fail because the GET replaces the array with `[]`. Run and record this failure before editing `index.ts`; tests merely written or failures caused by imports/dependencies/fixtures are not RED.
5. Add/assert immediate display eligibility (`loading` no longer hides the uploaded list) while the superseded GET is still pending. Keep M1's stale-overwrite assertion independently runnable so an earlier loading failure cannot substitute for proof of the requested ordering regression.

**Focused guard coverage, all within the same hook test file:**

| ID | Deterministic ordering | Expected behavior |
|---|---|---|
| M2 | Repeat M1 with an older non-empty snapshot omitting the new asset | Uploaded asset/metadata remain; no blind stale-array replacement or merge |
| M3 | Load A's assets, start another GET, complete deletion of one asset, then resolve the GET with the old list containing it | Deleted asset stays absent; unrelated assets survive; deletion failure instead retains the item and exposes the existing error |
| M4 | Start A operations, rerender for B (also cover A -> null), then settle A fetch/upload/delete successes and failures | A data/errors/finalizers cannot affect B/null; prior-project state clears; B can fetch/upload normally; A's completion cannot clear B's pending flags |
| M5 | Start two GETs, resolve the newer first, then the older; separately start a fresh GET after a successful mutation | Latest valid GET wins; a fresh authoritative empty response clears the list, proving the fix has not disabled fetching or made all lists append-only |
| M6 | Complete upload then reject its invalidated earlier GET; separately settle an obsolete GET while a newer GET is pending | No stale error replaces success; obsolete finalization cannot end newer loading; all active pending flags eventually settle correctly |
| M7 | Reject current upload/delete, and exercise current fetch error/clearErrors and null-project no-op | No fabricated insert/removal/success; existing safe messages and boolean contracts remain; no new project-scoped request for null |
| M8 | Exercise mount cleanup/unmount and StrictMode effect replay with controlled pending responses | Obsolete work cannot affect a later active view; valid replay/current requests still apply; no stuck loading/uploading or unhandled rejection |

Demonstrate RED for missing behavior before implementing its correction. GREEN requires M1–M8 and existing tests to pass without skips or weakened assertions. Keep the implementation small; these tests specify the same request-ordering/project-safety contract, not a general fetching-framework rewrite.

### Frontend Execution (Builder, Then Independently Tester)

Run from `apps/web`:

```bash
npm test -- src/features/media/hooks/index.test.tsx
npm test -- src/features/media
npm test
npm run lint
npm run build
```

The first command supplies targeted RED then GREEN; the Media and full suites retain API/component integration and unrelated frontend regression coverage. Record the actual selection/counts and exit statuses. Do not alter lint/build/test configuration or dependencies to hide a failure. After any scoped refactor, rerun the relevant commands; record no-refactor verification explicitly if no refactor was needed.

### Deterministic Media Playwright Scenario

In `apps/web/e2e/media.spec.ts`, keep real registration/project creation, then install all scenario Media intercepts **before** clicking the project's Open button. `ProjectMediaSection` issues its GET on mount; installing routes after the click is invalid even if a run happens to pass. Prepare the rejection scenario's list interception before its Open action as well; retain its real non-video 422 validation rather than turning the invalid upload into a fake success.

For the upload/list/delete scenario:

1. Register the GET/upload/delete handlers (and any scenario CSRF interception after real auth/project creation) before Media navigation. Use method/path-specific handling so unrelated requests continue normally.
2. Enter Media and explicitly observe initial GET arrival, holding its stale empty response with deferred coordination. Account for all initial StrictMode requests without relying on an arbitrary sleep or assuming exactly one GET.
3. Upload using the existing file-input workflow. Fulfill POST with 201 and a current-project asset in the real public shape (`size_bytes`, `status: 'stored'`, both timestamps, etc.), not `filename`/`size`/`disk` substitutes. The old GET must not already contain that asset.
4. Observe the successful POST and assert a visible `Media: <original_name>` list item exactly once while the GET is still pending. A filename in the file chooser is not a list assertion.
5. Release the older GET response(s), observe completion, and assert the item remains after processing. No reload, route reentry, follow-up refetch dependency, timeout increase, or retry may mask disappearance. The hook regression supplies the exact post-settlement state assertion; browser review also verifies actual continued display.
6. Exercise delete confirmation (and cancellation during independent review), fulfill confirmed DELETE with 204, and assert removal plus the empty state **before** Back to projects. Preserve project cleanup/empty-project assertions.
7. Retain invalid-file rejection: observe its expected 422 and understandable alert, with no item inserted. Do not blanket-ignore 4xx/5xx or accept unrelated request failures as validation.

Use existing public endpoint/data contracts, not production mocks. Record that success-path media storage is mocked while auth/projects and rejection validation are real. Do not claim storage/backend coverage from intercepted requests.

Run from `apps/web` with the prepared environment:

```bash
npm run test:e2e -- e2e/media.spec.ts
npm run test:e2e
```

The targeted command runs Media at all three configured projects (including the previously blocking tablet scenario). Full E2E runs auth/projects/health/media at mobile/tablet/desktop and is required locally when practical. If the full local run is impractical, record the precise environment/resource limitation and unexecuted scope; do not call it PASS or drop CI coverage. Required Media/browser checks must still execute before Tester approval, and full new-HEAD E2E CI remains mandatory. Unexpected unrelated failures are reported to Orchestrator, not repaired in this exception or retried blindly.

### Independent Running-App, Visual, API, and Accessibility Review

Tester must review the final application through the browser in addition to automated results. Use the Media scenario's controlled network boundary at each required viewport and inspect actual screenshots, not just artifact existence. Capture safe screenshots/diagnostics through permitted browser/test tooling (existing `test-results/screenshots/`/Playwright artifacts are suitable); only evidence is a Tester-authored repository edit. If artifact/tool access is blocked, escalate without changing permissions.

- Inspect pending list/upload, immediate uploaded item, stale-response completion, empty list, validation error, delete confirmation/cancellation/pending/removal, and Back navigation. Verify project isolation with the hook regressions and navigation between project views.
- Check 390x844, 768x1024, and 1440x900 for overflow, spacing, legibility, coherent controls, and stable loading/success/error layout. No visual redesign is authorized.
- Verify associated file-input label, accessible button/list-item names, keyboard navigation, visible focus, confirmation/cancellation usability, disabled pending controls, status/alert semantics, and usable focus after actions.
- Review console errors, uncaught exceptions, failed resources, unexpected redirects, and actual request method/URL/status/payload ordering. Observe the pending GET before upload and its stale completion afterward. No secrets or private storage fields may leak.
- Unexpected console errors or application 4xx/5xx fail review. Only explicitly exercised responses, such as guest-session 401 during registration bootstrap or non-video 422, may be documented as expected with their exact endpoint/reason; no blanket suppression.
- Confirm no backend/API/security-policy changes: existing Media API tests pass, requests keep the cookie/CSRF client, public asset fields stay unchanged, and the hook does not show another project's results. No new backend tests/storage integration infrastructure are needed for this frontend-only correction; `Backend CI / tests` is still a mandatory gate, not N/A.

Tester does not repair implementation/tests or Planner files. Missing required verification or a defect results in REJECT to Orchestrator -> Builder -> Tester.

## Scope and CI Gate Review

Compare follow-up changes with the published baseline. Only the three authorized Media files and this Issue #43 planning/evidence bundle may change. Original human-authored agent/merge-gate/governance artifacts remain untouched by runtime agents. Reject M3/worker/FFmpeg/FFprobe, unrelated UI/refactors/bugs, new configuration/dependencies, timeout inflation, sleeps, hidden retries, assertion weakening, or validator changes.

Orchestrator must reconcile PR #44's bootstrap-only description/applicability claims and obtain new-HEAD SUCCESS for `Backend CI / tests`, `Frontend CI / test`, `E2E CI / e2e`, `governance / governance`, and `governance / pr-enforcement`. The historical evidence approval must not authorize readiness. Only after renewed review and all checks pass may Orchestrator invoke `python scripts/merge_gate.py 44 --check` and then the unchanged deterministic merge path. Tester/Planner perform no live merge; no new issue/branch is started.

## Expected Results

All discovered governance tests must pass unchanged (historical total 170), including the separately executed 24 merge-gate tests. Record actual fresh counts, not unverified category arithmetic. The deterministic Media regression must show the requested pre-fix failure and post-fix pass, with frontend/lint/build/Media E2E and independent browser review green. Refactor verification must remain green. Any required failure, missing test, blocked mandatory review, or non-success required CI check blocks completion; historical approval and bootstrap TDD exemption do not apply to the new work.

## Evidence Requirements

After test execution, record in `specs/043-simplify-agent-control-plane/evidence.md`:

1. Preserve literal `### RED`, `### GREEN`, and `### REFACTOR` headings. Clearly separate the original human-authored bootstrap chronology (RED not applicable; no fabricated failure) from new Media TDD.
2. Under RED, record the new failing hook command/working directory/exit status/assertion and the exact pending-GET -> upload-success -> stale-GET ordering before production edits. Include any actual E2E RED separately; do not invent execution or count setup failures.
3. Under GREEN, record fresh frontend/Media/lint/build/governance/merge-gate/E2E commands, counts, statuses, viewport coverage, and actual outcomes. Label historical 170/24 results historical and list blocked/unexecuted checks with their prerequisites.
4. Under REFACTOR, identify only scoped cleanup or explicitly no refactor, then record final reruns. No control-plane refactor is authorized in the continuation.
5. Record running-app interaction, screenshot paths and visual findings at every viewport, keyboard/accessibility review, console/network/API findings, and the real versus mocked boundaries without secrets.
6. Confirm only authorized Media changes beyond the baseline, unchanged trusted governance artifacts, no M3/unrelated work, and preserved validation/error/deletion/project behavior. Evidence format is repaired in evidence by an authorized owner, never via validators.
7. Record a renewed independent `Decision: APPROVE` only after required verification succeeds; otherwise `Decision: REJECT`. Label original approval historical/pending renewed review until then. Orchestrator separately records new-HEAD CI/lifecycle status; Planner neither edits evidence nor claims test execution.
