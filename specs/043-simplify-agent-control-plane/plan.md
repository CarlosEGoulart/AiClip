# Implementation Plan: Simplify Agent Control Plane

## Issue

#43 — refactor(governance): simplify agent control-plane

## Context

Issue #43 / PR #44 continues on `@carlosegoulart/43/refactor/simplify-agent-control-plane`, published baseline `e583495a90b96350f8d92e7e732c2829a4bc5a1e`. The original control-plane implementation and governance regression tests were **human-authored** before SDD as an explicit bootstrap exception because runtime agents cannot safely modify their own control plane.

The updated OPEN issue explicitly adds only the minimum Media stale-fetch correction and deterministic pre-navigation E2E setup required by the hard E2E gate. This plan preserves the original governance verification and defines normal test-first implementation for that narrow addition. Historical bootstrap approval does not approve the Media continuation. No new issue, branch, milestone, or permission change is authorized.

## Bootstrap Exception

The following artifacts already exist as published human-authored work; they are **read/verify only** for runtime agents:

- `.opencode/agents/planner.md` — role-based permission frontmatter
- `.opencode/agents/builder.md` — role-based permission frontmatter
- `.opencode/agents/tester.md` — role-based permission frontmatter
- `.opencode/agents/orchestrator.md` — role-based permission frontmatter
- `scripts/merge_gate.py` — deterministic fail-closed merge gate
- `tests/governance/test_agent_permissions.py` — role-invariant regression tests
- `tests/governance/test_governance.py` — governance contract checks
- `tests/governance/test_merge_gate.py` — merge gate regression tests

No test-first RED is claimed for those artifacts. Do not recreate their history, repair them, or roll back permission files through a runtime agent. The bootstrap exception does not apply to the new Media work.

## Authorized Continuation Files

- Planner edits only `specs/043-simplify-agent-control-plane/spec.md`, `plan.md`, and `test-plan.md` in the existing directory.
- Builder edits only `apps/web/src/features/media/hooks/index.test.tsx`, `apps/web/src/features/media/hooks/index.ts`, and `apps/web/e2e/media.spec.ts` for the application correction.
- Evidence is recorded separately in this bundle's `evidence.md` by existing authorized roles; Planner cannot edit it. Tester owns the renewed independent decision. Orchestrator owns PR/lifecycle metadata, not production or planning repairs.
- All trusted control-plane paths, governance tests/validators/workflows, other application code/tests, dependencies, configuration, and infrastructure remain unchanged. Stop for clarification if another source path appears necessary.

## Relevant Code Findings

`useMediaAssets.fetchMedia` unconditionally calls `setMediaAssets(response.data)` after GET, while `uploadFile` prepends the returned asset and `deleteMedia` filters it out. There is no stale-response or project-generation guard. Existing tests use API-module mocks and cover isolated operations; auth hook tests demonstrate deterministic deferred promises and React `act` usage that can be followed locally without editing auth.

`ProjectMediaSection` fetches in an effect and `MediaList` hides items while `loading` is true. The fix must make a successful upload visible even while an invalidated GET is unresolved, not merely preserve the array until it finishes. The application uses StrictMode. Current Media E2E interception occurs after opening the project, its list fixture pre-populates the upload, and it omits an assertion that deletion removed the item before navigation.

The frontend types and Laravel `MediaAssetResource` agree on the public asset shape and the controller returns 204 for deletion. Keep these interfaces unchanged; correct the affected E2E fixtures in place. Broader PRD/architecture/roadmap capabilities and historical Issue #35 requirements are context, not permission to reopen storage or processing work.

## Implementation Strategy

Steps 1–3 remain independent verification of the original human-authored governance contract, not instructions to reimplement it. Steps 4 onward cover environment preparation, genuine Media TDD, and renewed combined verification. Planner executes no commands; commands in this bundle are instructions for roles with the relevant existing permission.

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

### Step 4: Verify Environment and Permission Prerequisites

Orchestrator confirms the existing issue/branch/PR and routes execution to Builder and independent Tester. Preserve the already-reconciled evidence format and historical bootstrap results without representing them as a current approval. PR #44's published description still describes the bootstrap-only snapshot; Orchestrator must reconcile it with the authorized Media scope and fresh results before final readiness, without rewriting historical PRs.

- Builder needs installed frontend dependencies (Node 24 as in CI), Vitest/RTL/jsdom, lint/build tooling, and the existing lockfiles. Use normal permitted installation only if required; no dependency edits.
- E2E needs PHP 8.3/extensions and installed Composer dependencies, a disposable PostgreSQL database with migrations, a prepared Laravel key/session environment, Chromium/system dependencies, free ports 5173/8000, and permitted browser/artifact access. The Playwright config starts both servers with `reuseExistingServer: false`; do not prestart conflicting servers or change the config.
- Valid uploads/deletes are mocked at the existing browser boundary; no new MinIO/worker/FFmpeg provisioning is needed for this correction. Registration, projects, health readiness, and the invalid-file rejection still use Laravel/PostgreSQL.
- Tester needs Python 3 with the existing pinned PyYAML and OpenCode runtime inspection access. Builder is not assigned Python governance commands outside its permission; Tester executes them.
- Do not inspect real `.env` files or attempt denied setup indirectly. Report the exact blocked operation/error to Orchestrator. Human/authorized environment provisioning is required for any unavailable or permission-denied prerequisite; neither a permission rewrite nor an alternate-tool bypass is authorized. Environment failures cannot supply RED evidence.

### Step 5: RED — Deterministic Media Regressions Before Production Edits

In `apps/web/src/features/media/hooks/index.test.tsx`, retain existing tests and use typed API-module mocks with manually controlled promises. Render the real hook with RTL and settle actions inside `act`; do not mock the hook/state setters or use sleeps. Start and observe a pending GET, complete an upload, assert the response asset is present, then resolve the old GET with `data: []` and assert that asset remains. The persistence assertion must fail against the unchanged hook. Record the command, exit status, failing assertion, and actual pre-fix behavior before editing production code.

Add focused guard coverage described in `test-plan.md`: deletion versus stale GET, project changes/null/unmount, stale success/error/finalization, valid latest GET replacement, and non-optimistic failed mutations. For each missing behavior being corrected, verify an actual assertion failure first rather than treating syntax, fixtures, or setup errors as RED. Include loading-state assertions so retaining array data while hiding it does not satisfy GREEN.

Update the affected E2E scenario test-first in `apps/web/e2e/media.spec.ts`: register/create the real project, then install scenario-specific media routes **before** its Open action. Hold the initial GET response(s), observe request arrival, fulfill upload 201 with a correctly shaped returned asset, assert its list item is visible while the older GET remains pending, then release the stale empty list and reassert visibility. Observe network completion and DOM outcomes, not elapsed time. Handle StrictMode duplicates explicitly; no request may escape because a route was registered too late. The primary mandatory RED is the hook assertion; also record any observed pre-fix E2E failure truthfully, without claiming a browser run that could not execute.

Do not pre-populate the uploaded asset in the older GET fixture. Keep real invalid-file rejection coverage with the list interception installed before its navigation. Use actual public asset fields and 204 deletion. Assert item removal and the empty state on the Media screen before returning to projects; preserve confirmation/cancellation and existing project cleanup assertions.

### Step 6: GREEN — Minimum Hook-Local Ordering Guard

Only after valid RED, change `apps/web/src/features/media/hooks/index.ts`. Keep its public signature and API client unchanged. A small project/request generation plus successful-mutation invalidation (or equivalent) is sufficient; no state-management dependency or generic fetching framework is needed.

- Accept GET results only for the current project/current request and a still-valid mutation version. Successful upload/delete invalidates older list snapshots before applying the returned asset/removal.
- Maintain project-scoped state and invalidate on project change/null/unmount. Guard success, error, and pending-state finalization; do not let obsolete operations mutate another project or finish a newer operation's pending state.
- Make the upload visible immediately: because `MediaList` hides items during loading, settle superseded list loading at the relevant invalidation boundary. Do not leave a spinner waiting for an obsolete GET; also do not let an old `finally` clear a newer legitimate GET's loading flag.
- Preserve failed-mutation error/boolean semantics and valid current-project mutation results. A fresh valid GET may replace state, including clearing it; no blind stale-array merge or permanently disabled fetching.

Run the targeted hook and Media frontend tests, then the full frontend suite, lint, build, and Media E2E. Keep all configured timeout/retry values unchanged. Additional components/styles/API implementations are not authorized; if the contract cannot be met hook-locally, stop for clarification.

### Step 7: REFACTOR and Renewed Independent Verification

Refactor only the new guard/tests if needed; otherwise record that no refactor was necessary. Rerun verification after the final change. Follow `test-plan.md` for exact frontend/Playwright commands and independent browser review. Run full configured mobile/tablet/desktop E2E locally when practical; record blocked local scope accurately and require full E2E CI on the new HEAD regardless.

Tester independently reruns the frontend/Media checks, inspects the actual rendered workflow/screenshots and console/network/API/accessibility, verifies governance boundaries, and runs from the repository root:

```bash
python -m unittest discover -s tests/governance -p 'test_*.py' -v
python -m unittest tests/governance/test_merge_gate.py -v
```

Historical results were 170 full-suite tests, including the 24 merge-gate tests. Record fresh discovery counts/results; do not copy historical PASS as new execution or reduce coverage. Missing tests, skipped mandatory verification, unexpected failures, or changed trusted artifacts block approval. Report unrelated failures without fixing them under this exception. Tester rejects defects to Orchestrator -> Builder and never repairs code/tests/planning.

### Step 8: Scope and Lifecycle Handoff

Compare the continuation with the published baseline: only the three Media source/test paths and Issue #43 planning/evidence changes may be added. The original agent frontmatter, merge gate, and governance regression changes remain the same human-authored artifacts, not new runtime edits. No M3/processing/worker/FFmpeg/FFprobe, unrelated UI/refactors/bugs, test weakening, timeout inflation, or historical PR rewriting.

Renewed Tester approval is required before Orchestrator publishes the follow-up. Orchestrator reconciles PR scope/TDD/test/applicability metadata, waits for all five required CI checks on the new PR HEAD, and only then uses `python scripts/merge_gate.py 44 --check` for readiness and the unchanged gate for merge execution. The historical `Decision: APPROVE` is not authority to advance. Stop on any CI/gate blocker; no direct merge or trusted-file repair. This planning handoff itself performs no lifecycle operations.

## Dependencies

- PyYAML (`tests/governance/requirements.txt`: `PyYAML==6.0.3`)
- Python 3
- `gh` CLI (for merge gate runtime, not needed for unit tests)
- Existing frontend/PHP/Playwright/PostgreSQL prerequisites and permission/human gates from Step 4; no new packages or infrastructure changes

## Risk Assessment

- **Bootstrap trust boundary:** Original governance artifacts remain immutable to runtime agents. A defect or failed invariant requires escalation, not restoration/editing of agent configurations.
- **Small but behavior-affecting addition:** Ignoring only data while mishandling loading, project identity, errors, or deletes can still lose or hide state. Deferred regressions plus running-app review are mandatory.
- **False-positive test risk:** Pre-filled upload fixtures, late routing, or navigating away before asserting deletion can hide the bug. Use deterministic pending GETs and assertions on actual list items.
- **Verification is critical:** Any required CI, permission, environment, or independent-review blocker remains a blocker. Do not disguise it with retries or a narrower test selection.

## Evidence Collection

After verification, evidence should be recorded in `specs/043-simplify-agent-control-plane/evidence.md` documenting:

1. Preserve literal `### RED`, `### GREEN`, and `### REFACTOR` sections. Separate the original human-authored bootstrap exception (no fabricated RED) and historical 170/24 results from newly executed Media RED/GREEN/refactor results.
2. Record exact commands, working directories, exit statuses, failing assertions before production edits, subsequent passing counts, and final reruns; list blocked checks honestly.
3. Record renewed governance/merge-gate results, role/permission verification, unchanged trusted files, and scope comparison with the published baseline.
4. Record frontend/lint/build, Media and full E2E scope/results by viewport, screenshots reviewed, responsive/accessibility findings, console/network/API observations, and mocked versus real boundaries.
5. Tester records a new independent `Decision: APPROVE` only when mandatory checks and running-app review are satisfied, otherwise `Decision: REJECT`. Historical approval remains labeled historical; it cannot approve this follow-up.
6. Orchestrator records new-HEAD CI/lifecycle readiness separately. Evidence-format problems are repaired only in evidence by an authorized owner, never by changing validators. No M3 or unrelated work is authorized.
