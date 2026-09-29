# Plan: Post-M5 Closeout State

- Issue: #67
- Branch: `@carlosegoulart/67/docs/post-m5-closeout-state`
- Base: `460a334888a01053a689cfe3b069bc6dd99972d4`

## Execution sequence

1. **Fresh RED before document edits.** Confirm issue/branch/base and the live #64/#65/#66 facts. Orchestrator then executes every test-plan predicate D1–D11 against existing content using permitted read tools and read-only Git inspection, recording expected versus observed excerpts, locations and PASS/FAIL per ID in Orchestrator-owned `evidence.md`. D1–D3, D5–D7, D9 and D10 must fail at the verified stale locations; D4 and D8 are expected to pass and be preserved; D11 is evaluated on the actual diff and path state. This is an explicit manual documentation assertion protocol, not an automated test claim. Already-correct invariants pass; setup failures are not RED.
2. **Minimal five-document correction.** Orchestrator edits only the five authorized documents, per the stale-fact inventory:
   - `README.md`: M5 milestone row (line 14) → Completed (Issue #64 closed via the PR #66 merge); M5 paragraph (lines 25–30) → shipped in the mainline, PR #65 superseded/closed unmerged as history; future-capabilities sentence (lines 55–56) → rendering and image generation remain future, semantic ranking shipped; optionally add "Semantic clip ranking" to the worker capability tree (line 46).
   - `docs/roadmap.md`: M5 paragraph (lines 53–59) → M5 concluded via Issue #64 / PR #66, PR #65 superseded/closed unmerged (historical), further M5 slices and M6 require separate authorization; add `## M5 — AI Clip Recommendation (completed)` consistent with the M0–M4 pattern and remove (or explicitly complete) the M5 planned row (line 65); indicate M6 Vertical Clip Rendering as next, not active and not authorized.
   - `docs/project-state.md`: Known Limitations semantic-ranking sentence (lines 55–58) → shipped mainline, retaining the true "no recommendation UI or public recommendation API"; rewrite Current Milestone (lines 63–85) → M0–M5 completed, Issue #64 closed as completed via the PR #66 merge, PR #65 superseded/closed unmerged (historical, branches preserved), no active implementation issue; rewrite Next Architectural Goal (lines 87–101) → M6 Vertical Clip Rendering next, not active/authorized/implemented, standalone queue-consuming Python service remains future; add the missing M5 entry to Completed Capabilities (after the M4 paragraph, line 34); keep exactly the six level-one headings in order.
   - `docs/prd.md`: M1 status line (line 216) → "- M1 — Application Foundation: Completed". No other prd.md change.
   - `docs/architecture.md`: mainline heading (line 9) → M0–M5 mainline; M5 status wording (lines 13–17) → merged via PR #66, mainline execution includes the semantic ranking stage; current job chain (lines 33–34) → includes model-backed semantic clip ranking (`rank_clips` v1.0.0). All other sections unchanged.
3. **GREEN.** The read-only Builder documentation checker reruns the same predicates D1–D11 without weakening them and records observed excerpts/results. No new test-suite files or validator changes.
4. **REFACTOR review.** Review English, concision and cross-document agreement without unrelated cleanup. Rerun all predicates after final wording/evidence adjustments. A no-change refactor review may be recorded, but final checks still run.
5. **Governance regression.** Run `python -m unittest discover -s tests/governance` through an existing permitted mechanism; record the actual exit status and summary (require exit 0). Inventory incidental `__pycache__` outputs per the test-plan exclusion; never stage or delete caches.
6. **Independent Tester.** Independently read actual documents, repeat predicates D1–D11 and the unchanged governance validation, confirm live #66 merge/merge-commit hash and #64 CLOSED (plus PR #65 closed unmerged and both #64 branches preserved), and inspect the full diff/path scope. Record substantive observed evidence, not a bare approval. Reject failed/unverified requirements; route corrections back through the plan sequence. Tester does not repair content; Orchestrator owns evidence updates.
7. **Lifecycle handoff.** Orchestrator alone commits (Conventional Commits with `Refs #67`), pushes the branch with an ordinary `git push -u` (no force, no force-with-lease, no rewriting or deletion of published refs), opens exactly one PR referencing `Closes #67`, and monitors required CI. Recheck final paths before handoff. Stop at `CI_GREEN_WAITING_HUMAN_MERGE`; do not merge, close #67 or start M6 without required separate authorization.

## Exact authorized paths

The final source/deliverable changed-file set must equal these nine paths, with no renames, deletions or other deliverable additions:

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

Intermediate phases may contain a subset; outside source/deliverable paths fail immediately. Only inspected untracked runtime outputs defined in the test plan may be excluded.

## Tool and role boundaries

Planner edits only the three planning files. Orchestrator owns documentation and evidence edits. Builder review is read-only documentation checking (its permissions deny `docs/**` edits), per the approved precedent from merged Issue #62 / PR #63. No application implementation or tests are needed.

Use permitted read/search tools for text predicates and permitted read-only Git inspection for scope. No inline Python, shell wrapper, custom script, runtime probe, permission edit or model/configuration change is required or authorized. Existing governance validation checks general contracts, including the six project-state headings; it does not prove all Issue #67 prose or its exact path allowlist. Explicit checks remain mandatory. If necessary read/Git/governance execution is unavailable or denied, report a blocker rather than route around it.

After governance execution, inventory incidental caches through permitted read/glob tools and record command provenance. Apply the test-plan exclusion only to verified untracked bytecode, never to source or staged/tracked changes. Leave caches untouched and out of commits; no deletion, shell cleanup, ignore/configuration changes or permission bypass. Tester independently rechecks classification and the exact nine deliverables. Unresolved inventory discrepancies require clarification or human assistance, not silent exclusion.

## Completion evidence

Orchestrator records fresh baseline sources, RED/GREEN/refactor results with excerpts, governance command/results, changed-path review, independent Tester findings/decision, application-check N/A reasons and the human merge gate. Never label planned checks as executed or #67 as closed prematurely. No session transcripts or large logs; keep evidence concise and auditable. This plan matches the approved issue #67 without adding product scope or test-suite files.
