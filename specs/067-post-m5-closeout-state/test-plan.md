# Test Plan: Post-M5 Closeout State

- Issue: #67
- Branch: `@carlosegoulart/67/docs/post-m5-closeout-state`
- Base: `460a334888a01053a689cfe3b069bc6dd99972d4`

## Reproducible documentation assertion protocol

Use existing permitted read/search tools to read the five documents in full for each phase. Evaluate the predicates below; treat line wrapping as whitespace and inspect contextual meaning, not token presence alone. For every ID record phase, file/lines, expected result, observed quotation and PASS/FAIL in Orchestrator-owned `evidence.md`. Manual checks have no command exit code: identify the read mechanism and outcome, not a fabricated automated run. The read-only Builder documentation checker independently repeats the same predicates for GREEN; independent Tester repeats them again for approval.

RED is not yet executed. Baseline observations below are planning expectations, not executed RED evidence. Before document edits, execute all predicates and record genuine failures where the expected baseline is FAIL. Repeat all IDs for GREEN and after refactor review. Missing claims, contradictions and unverifiable predicates fail. Historical quotations in this bundle/evidence are not current-state claims and are not prohibited stale product prose.

| ID | Assertion and exact evaluation scope | Expected baseline |
|---|---|---|
| D1 | README, roadmap and project state each explicitly identify Issue #64 as closed/completed via the merge of PR #66, and PR #66 as merged. Architecture identifies the M5 slice as merged wherever it is referenced. Do not imply new recommendation/UI capabilities beyond shipped M5. | FAIL: explicit closed #64/merged #66 status missing. |
| D2 | Read all current-state prose in all five documents: none describes M5 or Issue #64 as active, awaiting CI, awaiting review/merge, unmerged-only, or not shipped, and none describes a pending recovery lifecycle. | FAIL at stale locations below. |
| D3 | Milestone status wherever presented shows M0–M5 completed: README milestone rows; roadmap completed M0–M5 headings with no planned-uncompleted M5 row; project state explicitly says M0–M5 complete; architecture mainline heading reflects M0–M5. No scoped document contradicts these facts. | FAIL: README M5 row active; roadmap M5 planned row; project state lacks M5 completion. |
| D4 | M4 vs M5 separation preserved: `scene_timing_baseline` v1.0.0 remains deterministic timing-based scores/ranks, not AI recommendation or semantic relevance; `rank_clips` v1.0.0 remains model-backed semantic ranking, separate from M4 deterministic scoring. #58 / PR #59 provenance for deterministic candidate implementation and #60 / PR #61 corrective-closeout attribution are retained without retrospective reattribution. | PASS; preserve during edits. |
| D5 | Where PR #65 is referenced historically, it is described as superseded and closed without merge, with both Issue #64 branches preserved and nothing deleted or rewritten. No document describes PR #65 as open, as the active PR, or as pending closure. | FAIL: project state describes PR #65 in future tense ("will be closed unmerged") and its replacement PR as the one active PR. |
| D6 | README, roadmap and project state each explicitly identify M6 Vertical Clip Rendering as the next milestone — future, not active, not authorized and not implemented, requiring separate explicit authorization. No scoped document claims any M6 feature, implementation or capability as shipped or in progress. | FAIL: M6 is not named as next in any status document. |
| D7 | `docs/prd.md` Dependencies records "M1 — Application Foundation: Completed". No scoped document presents an incorrect old milestone status. | FAIL: prd.md line 216 says "In progress (foundation established, authentication next)". |
| D8 | Project-state level-one headings exactly match the ordered list below with no additions, omissions or duplicates. | PASS; preserve during edits. |
| D9 | `docs/architecture.md` current execution topology records the semantic ranking stage in the mainline: the current job-chain enumeration includes model-backed semantic clip ranking (`rank_clips` v1.0.0) alongside probe, scene detection, audio extraction, transcription and deterministic clip analysis. Topology diagram, target sections and runtime semantics remain unchanged. | FAIL: current chain lists only deterministic clip analysis. |
| D10 | Semantic clip ranking is recorded as a shipped mainline capability wherever current capabilities are described (README development-status prose, roadmap M5 record, project state Completed Capabilities and Known Limitations). No scoped document describes semantic recommendation as candidate-only, unmerged-only, or absent from the mainline. | FAIL: README says "nothing is shipped"; project state says "exists only as the unmerged Issue #64 candidate". |
| D11 | Final diff is English-only and restricted to the nine authorized paths with closeout content only. Architecture diff changes only directly related closeout prose, not topology, target architecture or runtime semantics. Historical `specs/058-*`, `060-*`, `062-*`, `064-*` artifacts and protected source/tooling remain untouched. Apply only the verified runtime-output exclusion below. | Evaluate actual diff and path checks. |

### Baseline failure locations (verified 2026-09-28; locations can shift — read actual content)

- `README.md:14`: “Active (Issue #64): implemented, Tester-approved candidate; unmerged, final CI pending on recovery branch”.
- `README.md:25–30`: “implemented on the issue branch … nothing is shipped … the replacement PR … is the single active PR, unmerged until its five CI checks are green”.
- `README.md:55–56`: “AI recommendation, rendering, and image generation remain future capabilities”.
- `docs/roadmap.md:53–59`: “Issue #64, is the single active implementation issue … unmerged … its replacement PR is the one active PR. No M5 capability ships until that PR merges”.
- `docs/roadmap.md:65`: M5 listed under “## Planned Milestones”.
- `docs/project-state.md:55–58`: “Semantic clip ranking exists only as the unmerged Issue #64 candidate … mainline still carries deterministic `scene_timing_baseline` metadata only”.
- `docs/project-state.md:63–85`: Current Milestone describes the single active implementation issue #64, pending CI, and “Nothing is merged or shipped and Issue #64 remains open”.
- `docs/project-state.md:89–101`: obsolete recovery instructions (“Finish Issue #64 through the approved recovery …”).
- `docs/prd.md:216`: “M1 — Application Foundation: In progress (foundation established, authentication next)”.
- `docs/architecture.md:9`: “Current Execution Topology (M0–M4 mainline)”.
- `docs/architecture.md:13–17`: “it is implemented and Tester-approved but unmerged, so mainline execution and behavior are unchanged”.
- `docs/architecture.md:33–34`: current job chain omits semantic clip ranking.

Missing files, inaccessible tools, syntax errors and dependency failures are not valid RED.

### D8 exact heading list

1. Current Architecture
2. Completed Capabilities
3. Important Decisions
4. Known Limitations
5. Current Milestone
6. Next Architectural Goal

## Exact changed-path guard

The final source/deliverable changed-file set must equal these nine paths, with no renames/deletions or other deliverable additions:

```text
README.md
docs/roadmap.md
docs/project-state.md
docs/prd.md
docs/architecture.md
specs/067-post-m5-closeout-state/spec.md
specs/067-post-m5-closeout-state/plan.md
specs/067-post-m5-closeout-state/test-plan.md
specs/067-post-m5-closeout-state/evidence.md
```

Intermediate phases may contain a subset; outside source/deliverable paths fail immediately. Only inspected untracked runtime outputs defined below may be excluded. At final review all nine must be present. Inspect changes from pinned base through HEAD, staged/unstaged changes and untracked paths via permitted read-only Git. Suitable requests, where permitted:

```text
git diff --name-status 460a334888a01053a689cfe3b069bc6dd99972d4 HEAD
git diff --name-status
git diff --cached --name-status
git ls-files --others --exclude-standard
git status --short
```

Compare the union manually with the exact list after separately inventorying/classifying only permitted runtime outputs. Inspect full corresponding diffs and read untracked issue artifacts. Check the final net base-to-result diff as well if paths were changed and reverted. Governance tests alone do not prove this guard. No shell pipeline, inline interpreter or wrapper is needed. Unavailable Git inspection blocks verification. Do not erase unexpected changes to make the guard pass.

### Narrow governance runtime-output classification

The unchanged mandatory governance command may create bytecode. D11 distinguishes incidental runtime output from authored source/deliverable changes. Exclusion requires all of the following:

1. Files are untracked, unstaged `.pyc` outputs directly inside `scripts/__pycache__/` or `tests/governance/__pycache__/`, attributable to importing existing governance modules during the required command. A suffix alone is insufficient: correlate inventory with command evidence and existing module names.
2. Permitted read/glob inspection inventories actual entries. No source file, nested directory, other extension, unrelated module or unexplained artifact is covered. Empty cache directories contain no Git deliverables. Record command/result, exact paths and inventory discrepancies transparently.
3. Read-only Git confirms no tracked source/tooling changes outside the nine paths and no cache staged or included in commit/PR diffs. Tester independently repeats inventory/scope checks. Recheck after subsequent governance runs and before commit/PR handoff. Never stage caches or broadly stage them by accident.
4. Leave outputs untouched. No deletion, cleanup wrapper, ignore-rule/tooling/configuration/permission change or arbitrary untracked exclusion is authorized. Unexpected source files immediately block. Staged/tracked caches, unresolved provenance/inventory discrepancies or unavailable verification block D11 and require Orchestrator/human clarification under existing permissions.

Verified caches may remain untracked without cleanup or weakening the exact nine-path deliverable guard. Empty glob output alone is not proof of absence when directory/Git observations differ; reconcile actual current observations before approval.

## Existing governance and independent factual checks

- Run `python -m unittest discover -s tests/governance` through the existing permitted mechanism; require exit code 0 and no failed required assertions. Record the actual current command/result. Do not edit tests/tooling. Existing six-heading governance coverage supplements, but does not replace, manual prose and path checks.
- Independent Tester confirms live GitHub facts: PR #66 is MERGED with merge commit `460a334888a01053a689cfe3b069bc6dd99972d4` (merged 2026-09-28T20:47:38Z); Issue #64 is CLOSED (closed 2026-09-28T20:47:39Z); PR #65 is CLOSED unmerged (mergedAt null; superseded without merge), with `@carlosegoulart/64/feat/semantic-clip-recommendation` and `@carlosegoulart/64/feat/semantic-clip-recommendation-recovery` preserved local + remote. Record sources and observations, not just Orchestrator's report. Inaccessible facts block verification.
- Tester reads actual final artifacts, repeats D1–D11 and governance validation, checks M4/M5 separation and #58/#59, #60/#61, #64/#65/#66 attribution, and records substantive excerpts, scope observations, command results and approval/rejection through the authorized evidence workflow. A bare verdict is insufficient.
- Application unit, integration/API, E2E, Playwright, visual and accessibility interaction checks are N/A: this changes documentation only, with no application runtime/interface behavior affected. No application stack, database, browser, provider or model download is required.
- Required CI remains mandatory and unchanged. Green checks do not authorize merge. Stop at `CI_GREEN_WAITING_HUMAN_MERGE`, with #67 open pending human-authorized merge/closure; no automatic M6 or further-milestone lifecycle.
