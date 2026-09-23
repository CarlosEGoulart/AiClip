# Test Plan: Post-M4 Closeout State

- Issue: #62
- Branch: `@carlosegoulart/62/docs/post-m4-closeout-state`
- Base: `1143a3063c5be3b3dfd751c83cf3b84d1d6eada2`

## Reproducible documentation assertion protocol

Use existing permitted read/search tools to read the four documents in full for each phase. Evaluate the predicates below; treat line wrapping as whitespace and inspect contextual meaning, not token presence alone. For every ID record phase, file/lines, expected result, observed quotation and PASS/FAIL in Orchestrator-owned `evidence.md`. Manual checks have no command exit code: identify the read mechanism and outcome, not a fabricated automated run. Tester independently repeats the same predicates.

RED is not yet executed for this restoration. Previous uncommitted evidence is lost and must not be credited. Before document edits execute all predicates and record genuine D1/D2 failures. Baseline observations below are planning expectations, not executed RED evidence. Repeat all IDs for GREEN and after refactor review. Missing claims, contradictions and unverifiable predicates fail. Historical quotations in this bundle/evidence are not current-state claims and are not prohibited stale product prose.

| ID | Assertion and exact evaluation scope | Expected baseline |
|---|---|---|
| D1 | Each of README, roadmap, project state and architecture explicitly identifies Issue #60 closed/completed corrective closeout and PR #61 merged. Do not imply new recommendation/rendering/UI capabilities. | FAIL: explicit completed #60/merged #61 status missing. |
| D2 | Read all current-state prose in all four: none describes #60 as active/current work, awaiting review/CI/merge, not closed or not yet merged. Deleting every #60 reference cannot pass D1. | FAIL at stale locations below. |
| D3 | README retains Completed for M0–M4 rows; roadmap retains completed M0–M4 headings; project state explicitly says M0–M4 complete; architecture retains completed M4 current topology. No scoped document contradicts these facts. | PASS. |
| D4 | All four retain #58 / PR #59 credit for deterministic candidate implementation; none attributes #60 corrective verification to #58. Roadmap, project state and architecture explicitly preserve separate evidence/provenance without conditioning separation on a future #60 merge. | FAIL: architecture conditions separation on merge. |
| D5 | README, roadmap and project state each explicitly describe M5 AI Clip Recommendation as next, future, not active and not implemented. Architecture retains future/not active and does not claim implementation. Roadmap/project state retain separate explicit authorization before M5; no document authorizes it. | FAIL: not every explicit qualifier is present. |
| D6 | All four retain scene_timing_baseline as deterministic timing-based scores/ranks, not AI recommendation. Existing v1.0.0 and non-semantic-relevance descriptions are preserved wherever present. No AI/semantic ranking is claimed as shipped. | PASS. |
| D7 | README, project state and architecture each preserve the entire chain: Laravel queue → ProcessMediaAsset → ProcessMediaAction → Python CLI subprocess → Laravel validation → Laravel-owned PostgreSQL persistence. Each retains future standalone queue-consuming Python wording; none says current Python consumes Laravel jobs or writes application rows. Roadmap does not contradict this. | PASS. |
| D8 | Project-state level-one headings exactly match the ordered list below with no additions, omissions or duplicates. | PASS. |
| D9 | No scoped document describes #62 as already completed/merged/closed or presently asserts no active issue while #62 is open. Any return to no active implementation is conditional on actual closure and never authorizes M5. | PASS; preserve during edits. |
| D10 | Final diff is English-only and restricted to allowed source/deliverable paths and closeout content. Architecture diff changes only directly related closeout prose, not topology, target architecture or runtime semantics. Historical #58/#60 artifacts and protected source/tooling remain untouched. Apply only the verified runtime-output exclusion below. | Evaluate actual diff and path checks. |

### Baseline D2 failure locations

- `README.md:22`: “Issue #60 is the current corrective concurrency/validation closeout”.
- `docs/roadmap.md:49–51`: “Issue #60 is the active corrective closeout”.
- `docs/project-state.md:60–63`: “Issue #60 is the active M4 corrective closeout” and “does not claim #60 merged or closed”.
- `docs/project-state.md:70–71`: instruction to complete #60 review, CI and merge before a new lifecycle.
- `docs/architecture.md:40–41`: “until #60 is merged”. Related status at lines 14–15 lacks explicit completed #60/merged #61 facts for D1.

Locations can shift; read actual content. Missing files, inaccessible tools, syntax errors and dependency failures are not valid RED.

### D8 exact heading list

1. Current Architecture
2. Completed Capabilities
3. Important Decisions
4. Known Limitations
5. Current Milestone
6. Next Architectural Goal

## Exact changed-path guard

The final source/deliverable changed-file set must equal these eight paths, with no renames/deletions or other deliverable additions:

```text
README.md
docs/roadmap.md
docs/project-state.md
docs/architecture.md
specs/062-post-m4-closeout-state/spec.md
specs/062-post-m4-closeout-state/plan.md
specs/062-post-m4-closeout-state/test-plan.md
specs/062-post-m4-closeout-state/evidence.md
```

Intermediate phases may contain a subset; outside source/deliverable paths fail immediately. Only inspected untracked runtime outputs defined below may be excluded. At final review all eight must be present. Inspect changes from pinned base through HEAD, staged/unstaged changes and untracked paths via permitted read-only Git. Suitable requests, where permitted:

```text
git diff --name-status 1143a3063c5be3b3dfd751c83cf3b84d1d6eada2 HEAD
git diff --name-status
git diff --cached --name-status
git ls-files --others --exclude-standard
git status --short
```

Compare the union manually with the exact list after separately inventorying/classifying only permitted runtime outputs. Inspect full corresponding diffs and read untracked issue artifacts. Check the final net base-to-result diff as well if paths were changed and reverted. Governance tests alone do not prove this guard. No shell pipeline, inline interpreter or wrapper is needed. Unavailable Git inspection blocks verification. Do not erase unexpected changes to make the guard pass.

### Narrow governance runtime-output classification

The unchanged mandatory governance command may create bytecode. D10 distinguishes incidental runtime output from authored source/deliverable changes. Exclusion requires all of the following:

1. Files are untracked, unstaged `.pyc` outputs directly inside `scripts/__pycache__/` or `tests/governance/__pycache__/`, attributable to importing existing governance modules during the required command. A suffix alone is insufficient: correlate inventory with command evidence and existing module names.
2. Permitted read/glob inspection inventories actual entries. No source file, nested directory, other extension, unrelated module or unexplained artifact is covered. Empty cache directories contain no Git deliverables. Record command/result, exact paths and inventory discrepancies transparently.
3. Read-only Git confirms no tracked source/tooling changes outside the eight paths and no cache staged or included in commit/PR diffs. Tester independently repeats inventory/scope checks. Recheck after subsequent governance runs and before commit/PR handoff. Never stage caches or broadly stage them by accident.
4. Leave outputs untouched. No deletion, cleanup wrapper, ignore-rule/tooling/configuration/permission change or arbitrary untracked exclusion is authorized. Unexpected source files immediately block. Staged/tracked caches, unresolved provenance/inventory discrepancies or unavailable verification block D10 and require Orchestrator/human clarification under existing permissions.

Verified caches may remain untracked without cleanup or weakening the exact eight-path deliverable guard. No previous cache inventory is current evidence. Empty glob output alone is not proof of absence when directory/Git observations differ; reconcile actual current observations before approval.

## Existing governance and independent factual checks

- Run `python -m unittest discover -s tests/governance` through the existing permitted mechanism; require exit code 0 and no failed required assertions. Record actual current command/result, not the previous lost run. Do not edit tests/tooling. Existing six-heading governance coverage supplements, but does not replace, manual prose and path checks.
- Independent Tester confirms live GitHub PR #61 is MERGED with merge commit `1143a3063c5be3b3dfd751c83cf3b84d1d6eada2`, and Issue #60 is CLOSED with state reason COMPLETED. Record sources and observations, not just Orchestrator's report. Inaccessible facts block verification.
- Tester reads actual final artifacts, repeats D1–D10 and governance validation, checks separate #58/#59 and #60/#61 attribution, and records substantive excerpts, scope observations, command results and approval/rejection through the authorized evidence workflow. A bare verdict is insufficient.
- Application unit, integration/API, E2E, Playwright, visual and accessibility interaction checks are N/A: this changes documentation only, with no application runtime/interface behavior affected. No application stack, database, browser, provider or model download is required.
- Required CI remains mandatory and unchanged. Green checks do not authorize merge. Stop at `CI_GREEN_WAITING_HUMAN_MERGE`, with #62 open pending human-authorized merge/closure; no automatic M5 lifecycle.
