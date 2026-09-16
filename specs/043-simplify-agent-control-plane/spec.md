# Specification: Simplify Agent Control Plane

## Issue

#43 — refactor(governance): simplify agent control plane

## Status

SPEC_READY

## Authority and Continuation Boundary

Active [Issue #43](https://github.com/CarlosEGoulart/AiClip/issues/43) remains OPEN with [PR #44](https://github.com/CarlosEGoulart/AiClip/pull/44) on the existing branch `@carlosegoulart/43/refactor/simplify-agent-control-plane`. The published baseline is `e583495a90b96350f8d92e7e732c2829a4bc5a1e`. This revision follows the issue's human-authorized **Bootstrap CI blocker exception**; it does not authorize another issue, branch, or milestone.

The original governance implementation and regression tests were human-authored before SDD as an explicit bootstrap exception. Their historical verification and approval are not approval of this continuation. Do not fabricate bootstrap RED evidence or rewrite that history. The newly authorized Media correction requires normal SDD, an actual failing regression before the production fix, GREEN, REFACTOR verification, and renewed independent Tester review.

The original governance contract below remains intact. Runtime agents must not change the already-published trusted control plane. Additional application changes are limited to:

| Owner | Authorized continuation paths and purpose |
|---|---|
| Planner | Only `specs/043-simplify-agent-control-plane/spec.md`, `plan.md`, and `test-plan.md` in the existing directory |
| Builder | `apps/web/src/features/media/hooks/index.ts` for the minimum stale-result guard; `apps/web/src/features/media/hooks/index.test.tsx` for deterministic regression coverage; `apps/web/e2e/media.spec.ts` for pre-navigation interception and the affected scenario's assertions/fixtures |
| Evidence owners | `specs/043-simplify-agent-control-plane/evidence.md` under existing role permissions; Builder records actual execution, Tester owns renewed independent review and final decision, Orchestrator maintains lifecycle/history accuracy. Planner does not edit evidence. |

No other implementation, test, configuration, or control-plane edits are authorized by this continuation. If these paths cannot satisfy the contract, stop and return to Orchestrator for clarification rather than expanding the edit surface. Orchestrator cannot repair Planner-owned files or application code.

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

## Bootstrap CI Blocker Exception: Media Request Ordering

### Observed Implementation

- `useMediaAssets` in `apps/web/src/features/media/hooks/index.ts` replaces the entire list when `getMediaAssets(projectId)` resolves. Successful upload prepends its response, but no version/project guard prevents an older GET from overwriting it. Existing hook tests cover these operations individually, not the interleaving.
- `ProjectMediaSection` starts `fetchMedia()` in an effect. `MediaList` renders only its loading status while `loading` is true. Preserving array contents alone is insufficient if a superseded GET continues to hide the uploaded asset.
- `apps/web/e2e/media.spec.ts` opens the Media screen before installing its routes. Its successful list fixture already contains the uploaded item and can mask a missing upload update; its delete flow navigates away without asserting removal first.
- Existing React StrictMode and auth generation/deferred-promise patterns provide context, not authorization to refactor auth or other features.

### Required Observable Behavior

1. Start a media-list GET for project A and keep it pending.
2. Submit an upload POST for project A and resolve it successfully with the server-returned asset.
3. The returned asset appears exactly once in current hook state and in the rendered media list, without waiting for the older GET or requiring a reload/refetch. Superseded list loading must not hide it.
4. Resolve the older GET afterward with an empty or stale list that omits the uploaded asset.
5. After the response is processed, the uploaded asset remains visible and retains its returned metadata. This is a correctness requirement, not a probabilistic timing test.

Use the smallest hook-local version/generation/invalidation guard, or an equivalent solution. A list response may replace state only while it is still current for the active project and has not been superseded by a successful mutation or a newer list request. Do not blindly merge stale arrays: that can resurrect deleted items, retain removed server items, or leak a previous project's assets. A subsequent valid, current GET remains authoritative and may replace the list with an empty result.

Required companion behavior within this same guard:

- Successful delete removes only the requested asset. An older list containing that asset cannot resurrect it; unrelated current assets remain intact. Failed upload/delete does not insert/remove an asset or claim success; preserve current error classification and retryable UI behavior.
- Project changes, including `projectId = null`, invalidate prior-project work and clear prior-project data/error/pending state. Late fetch/upload/delete success, error, or finalization must not alter the new project's list, errors, loading, or uploading state. Unmount and StrictMode effect cleanup must not permit stale completions to affect a later active view.
- Guard error and pending-state completion as well as successful responses. An invalidated GET failure must not replace a newer successful upload with a stale error. An obsolete request's `finally` must not end a newer request's loading state; conversely, invalidation must not leave loading/uploading stuck forever.
- Preserve the hook's public interface and existing API calls. Keep current-project mutations correct; do not solve read ordering by dropping otherwise valid same-project mutation results.

### Integration, Validation, and UX

Keep Laravel authoritative for ownership, authentication, validation, and persistence. This is a React state-ordering correction, not a backend/storage or security-policy change. Preserve the existing cookie/CSRF API client, project-scoped GET/upload URLs, media-ID deletion URL, error mapping, and escaped filename rendering. No new requests to workers, providers, or storage are introduced.

In `media.spec.ts`, install every Media route needed by a scenario **before clicking Open / entering the Media screen**, after real registration/project creation as appropriate. Use explicit request/deferred-response coordination to exercise the ordering above; do not wait for the initial list to finish before allowing the upload. Handle all initial requests, including StrictMode duplicates, without unhandled route promises. Assert the uploaded list item both before and after releasing stale responses and assert its removal/empty state after confirmed deletion, before navigating back. Preserve the non-video rejection scenario, with its list route prepared before navigation and the expected validation failure distinguished from unexpected network errors.

Fixtures for the corrected scenario must use the actual public `MediaAsset` fields: `id`, `project_id`, `original_name`, `mime_type`, `size_bytes`, `status: 'stored'`, `created_at`, and `updated_at`. Associate them with the created project; do not substitute private storage fields. Successful upload is 201 with a data envelope; list is 200 with a data array; successful media deletion is 204 with an empty body. These fixture corrections stay in the authorized E2E file and do not change API contracts. Mocked storage coverage is not proof of real object-storage behavior.

No visual redesign or component/style changes are needed. Existing loading, uploading, empty, success, error, confirmation/cancellation, disabled controls, and keyboard behavior must remain usable at 390x844, 768x1024, and 1440x900. Independent Tester must interact with the running application, inspect screenshots and responsive layout, and review console, network, API responses, and accessibility fundamentals. Application checks are no longer globally N/A.

## Out of Scope

- No M3 product work, media processing, Python worker, FFmpeg, or FFprobe
- No application behavior changes beyond the narrow Media stale-fetch correction above
- No historical PR rewriting belongs to this issue
- No new application features
- No backend, database, storage, infrastructure, dependency, or CI configuration changes
- No additional runtime-agent edits to `.opencode/**`, `tests/governance/**`, `scripts/merge_gate.py`, or other governance/control-plane files
- No tests beyond the existing governance verification and narrowly scoped Media hook/E2E regression work
- No unrelated UI changes, refactors, application bugs, or historical E2E repairs
- No timeout inflation, arbitrary sleeps, race-hiding retries, skipped/disabled tests, weakened assertions, validator edits, or removed mandatory checks

## Security Considerations

- All agents deny `.env` reads (root, nested, and all variants) except `.env.example`
- Trusted control-plane files are immutable to all runtime agents, including Planner (whose only writes are the planning bundle)
- Orchestrator cannot force-push, push default branches, or merge directly
- Merge gate enforces SHA stability to prevent race conditions
- Merge gate has no bypass or force options
- `persist-credentials: false` is enforced in governance CI workflow
- Media results are scoped to their active project; stale metadata must not be shown in another project's view
- No real `.env` inspection, credential logging, private storage fields, or permission bypass is allowed during verification

## Dependencies

- PyYAML must be available for governance tests (`tests/governance/requirements.txt` specifies `PyYAML==6.0.3`)
- `gh` CLI must be available for merge gate operations
- Python 3 must be available for merge gate execution
- Existing frontend dependencies and compatible Node.js (CI uses Node 24) must be installed; no dependency changes are required
- Playwright Chromium/system libraries, PHP 8.3 with existing Composer dependencies, a prepared disposable PostgreSQL database with migrations and Laravel app key, and free ports 5173/8000 are required for managed E2E servers
- Media success paths remain intercepted; this correction does not require a worker or real media-storage provisioning. Real auth/projects and invalid-file validation still require the API/database
- Respect runtime permissions for dependency installation, container/service startup, browser installation, network access, and browser artifact capture. A human/authorized environment owner must supply missing prerequisites or resolve denied operations without agent permission edits. Setup failures are blockers, never valid RED or passing verification
- Authenticated `gh` access and all five required CI checks on the new PR HEAD are prerequisites for Orchestrator's deterministic merge readiness/execution; historical approval must not be reused

## Acceptance Criteria

- [ ] All four agent frontmatter files implement role-based permission patterns
- [ ] Planner can only edit `specs/*/spec.md`, `specs/*/plan.md`, `specs/*/test-plan.md`
- [ ] Builder can broadly perform development work but cannot own Git/GitHub lifecycle
- [ ] Tester can execute broad verification but can only edit `evidence.md`
- [ ] Orchestrator owns Git/GitHub lifecycle but cannot merge directly or push default branches
- [ ] `scripts/merge_gate.py` enforces all 14 verification requirements
- [ ] Governance tests validate security/role invariants
- [ ] No additional trusted control-plane changes are made by runtime agents; the original governance contract remains intact
- [ ] Application changes are restricted to the three authorized Media paths and the stated exception
- [ ] No M3 product work is included
- [ ] A deterministic hook regression actually fails against the unchanged Media hook for the required GET-pending -> successful-upload -> stale-GET ordering, before the production fix
- [ ] The uploaded asset is visible immediately after successful upload and remains after the older GET settles; current fetch replacement, project transitions, deletion, errors, pending state, and StrictMode safety satisfy the guard contract
- [ ] Media E2E routes are installed before navigation can start the list GET; the scenario demonstrates upload visibility and actual deletion without timing workarounds or weakened coverage
- [ ] Relevant/full frontend tests, lint, build, and Media Playwright scenarios pass; run all configured mobile/tablet/desktop E2E locally when practical, recording any environment limitation without claiming a pass
- [ ] Full governance discovery (historical baseline: 170) and the merge-gate suite (24, included in discovery) pass again without modifying them
- [ ] Evidence retains truthful `### RED`, `### GREEN`, and `### REFACTOR` sections distinguishing historical bootstrap verification from new Media TDD; renewed independent Tester approval covers the running-app/browser/security/scope review
- [ ] All five required CI checks succeed on the new PR HEAD before Orchestrator uses the unchanged merge gate; any required failure or blocked mandatory verification prevents completion
