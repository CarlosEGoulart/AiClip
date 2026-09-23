# Specification: Post-M4 Closeout State

- Issue: #62 — docs: reconcile post-M4 state after Issue #60 closeout
- URL: https://github.com/CarlosEGoulart/AiClip/issues/62
- Branch: `@carlosegoulart/62/docs/post-m4-closeout-state`
- Base: `1143a3063c5be3b3dfd751c83cf3b84d1d6eada2`
- Scope: documentation only; no application behavior changes.

## Goal and baseline

Reconcile current documentation with completed M4 corrective closeout. Orchestrator reports live verification that PR #61 merged at the base hash, Issue #60 closed with state reason COMPLETED, and #62 remains the sole active issue with no open PR. Independent Tester must confirm the prior merge/closure facts. Issue #62 is not already completed.

Issue #58 / PR #59 delivered deterministic candidate implementation. Issue #60 / PR #61 completed corrective concurrency, failure-boundary, validation and documentation work. Their evidence remains separate after merge. M0–M4 are complete. M5 AI Clip Recommendation is next, future, not active or implemented, and requires separate explicit authorization.

This bundle restores the approved plan after loss of uncommitted artifacts; no cause is inferred. Prior RED, GREEN, governance results, cache observations and review do not constitute current evidence. Orchestrator must execute and record fresh RED before document edits, then all subsequent checks.

## Authorized scope and ownership

Only these content documents may change:

- `README.md`
- `docs/roadmap.md`
- `docs/project-state.md`
- `docs/architecture.md`: directly related closeout wording only. Current lines 40–41 say evidence remains distinct “until #60 is merged”; that stale condition authorizes this narrow correction. Evidence must remain distinct unconditionally. Related closeout status at lines 14–15 may be updated; architecture behavior and target sections remain unchanged.

Required issue artifacts are limited to `spec.md`, `plan.md`, `test-plan.md`, and `evidence.md` inside `specs/062-post-m4-closeout-state/`. Planner owns the first three; Orchestrator owns content-document edits and evidence. Optional Builder involvement is read-only documentation checking, not production implementation. Independent Tester approval is mandatory.

## Acceptance criteria

1. Each scoped document identifies Issue #60 as closed/completed and PR #61 as merged corrective closeout, without implying new product capabilities. No current-state prose describes #60 as active, pending review/merge, or not yet closed.
2. README milestone rows and roadmap headings retain M0–M4 completion. Project state explicitly records M0–M4 completion; architecture retains completed M4 current topology, consistent with the other documents.
3. #58 / PR #59 remains credited for completed deterministic candidate implementation. Roadmap, project state and architecture retain separate #58 implementation provenance and #60 corrective verification; no historical evidence is rewritten or reattributed.
4. README, roadmap and project state explicitly identify M5 as next, future, not active and not implemented. Architecture retains its future/not-active M5 statement without implying implementation. Starting M5 requires separate explicit authorization.
5. Existing `scene_timing_baseline` v1.0.0 descriptions remain deterministic timing-based scores/ranks, not AI recommendation or semantic relevance. No semantic/model-backed capability is claimed as shipped.
6. Current execution remains Laravel queue → `ProcessMediaAsset` → `ProcessMediaAction` → Python CLI subprocess → strict Laravel result validation → Laravel-owned PostgreSQL persistence. Python neither consumes Laravel-native queue jobs nor writes application rows directly. A standalone queue-consuming Python service remains future. Preserve existing topology, not speculative designs.
7. Project state retains exactly its six required level-one headings in order. Remove its obsolete instruction to complete #60 review/CI/merge before a new lifecycle. Do not claim #62 closed or no issue active while #62 remains open. Any post-closure return to no active implementation is conditional; M5 is not automatically authorized.
8. All source/deliverable changes are restricted to the exact eight paths in the test plan. Only narrowly verified, untracked governance bytecode may be classified as runtime output, never staged or committed. No other outside paths are exempt. Historical `specs/058-*` and `specs/060-*` remain read-only. All authored content remains English.
9. Fresh documented assertions demonstrate content-based RED before document edits, GREEN afterward and a passing refactor review. Existing governance validation passes unchanged. Independent Tester confirms facts, assertions, actual artifacts and scope with substantive evidence.

## Failure behavior and exclusions

Reject stale status, unsupported merge/closure claims, provenance conflation, topology drift, active/implemented M5 claims, unauthorized paths or missing evidence. Missing tools, inaccessible GitHub facts and setup failures are blockers, not RED or permission to bypass controls.

No production/application/test-suite/UI/API/migration/schema/backend/frontend/worker/scoring/provider/rendering/dependency/infrastructure changes. No governance, agent, skill, runtime, permission, configuration or CI changes, including `tests/governance/**` and `scripts/merge_gate.py`. No broad architecture cleanup, speculative M5 design, historical artifact edits or next issue.

The mandatory unchanged governance command may produce incidental bytecode. Classifying verified untracked runtime outputs under the test-plan rule does not authorize editing/deleting protected files, ignore-rule changes, suppressed validation or permission bypass. Unexpected source files, staged/tracked caches and unverified outputs remain blockers.

## Security, UX and dependencies

No runtime authorization, ownership, integration, deployment or storage changes. Preserve Laravel validation and persistence ownership. Never inspect real `.env` files or record secrets. Reader-facing UX is accurate status and shipped/future distinctions. Application unit, integration/API, E2E, Playwright, visual and accessibility interaction checks are N/A: documentation only, with no runtime or interface behavior affected.

The existing issue and branch remain in use; Orchestrator recreated the directory. No new dependency or infrastructure is needed. Use already permitted tools only. Independent Tester and required CI remain mandatory. Stop at `CI_GREEN_WAITING_HUMAN_MERGE`; human authorization is required before merge/closure. Only after actual closure may the lifecycle return to no active implementation, with separate authorization required for M5.
