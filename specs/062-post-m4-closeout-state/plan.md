# Plan: Post-M4 Closeout State

- Issue: #62
- Branch: `@carlosegoulart/62/docs/post-m4-closeout-state`
- Base: `1143a3063c5be3b3dfd751c83cf3b84d1d6eada2`

## Execution sequence

1. **Confirm prerequisites.** Continue existing #62, not a new lifecycle. Confirm issue/branch/base and prior live #60/#61 facts. Read this bundle and four scoped documents. Prior uncommitted evidence is lost: no previous RED/GREEN/review result counts for this restored work. Preserve unrelated work; unexpected source/deliverable paths block review, not trigger cleanup.
2. **Fresh RED before document edits.** Using permitted read tools, execute every test-plan predicate against existing content. Record expected versus observed excerpts, locations and PASS/FAIL per ID in new evidence. D1/D2 must fail because required completion status is missing or stale. This is an explicit manual documentation assertion protocol, not an automated test claim. Already-correct invariants should pass; setup failures are not RED.
3. **Minimal correction.** Orchestrator edits only the four authorized documents: replace active/pending #60 wording with closed/completed #60 and merged PR #61, preserving #58/#59 provenance. Make M5 next/future/not-active/not-implemented explicit in status documents. Preserve headings, deterministic terminology and current/future topology. Architecture edits stay within directly related closeout prose, especially the obsolete “until #60 is merged” condition.
4. **GREEN.** Repeat the same predicates without weakening them and record observed excerpts/results. Run `python -m unittest discover -s tests/governance` through an existing permitted mechanism; record actual exit status and summary. No new test-suite files or validator changes.
5. **REFACTOR review.** Review English, concision and cross-document agreement without unrelated cleanup. Rerun all predicates after final wording/evidence adjustments and governance validation after refactor. A no-change refactor review may be recorded, but final checks still run.
6. **Independent Tester.** Independently read actual documents, repeat predicates and unchanged governance validation, confirm live #61 merge/hash and #60 CLOSED/COMPLETED, and inspect full diff/path scope. Record substantive observed evidence, not a bare approval. Reject failed/unverified requirements; route corrections to Orchestrator. Tester does not repair content; Orchestrator owns evidence updates.
7. **Lifecycle handoff.** Orchestrator alone handles subsequent commits/PR/CI under existing policy. Recheck final paths before handoff. Stop at `CI_GREEN_WAITING_HUMAN_MERGE`; do not merge, close #62 or start M5 without required separate authorization.

## Tool and role boundaries

Planner edits only the three planning files. Orchestrator owns documentation and evidence. Optional Builder review is read-only documentation checking. No application implementation or tests are needed.

Use permitted read/search tools for text predicates and permitted read-only Git inspection for scope. No inline Python, shell wrapper, custom script, runtime probe, permission edit or model/configuration change is required or authorized. Existing governance validation checks general contracts, including the six project-state headings; it does not prove all Issue #62 prose or its exact path allowlist. Explicit checks remain mandatory. If necessary read/Git/governance execution is unavailable or denied, report a blocker rather than route around it.

After governance execution, inventory incidental caches through permitted read/glob tools and record command provenance. Apply the test-plan exclusion only to verified untracked bytecode, never to source or staged/tracked changes. Leave caches untouched and out of commits; no deletion, shell cleanup, ignore/configuration changes or permission bypass. Tester independently rechecks classification and the exact eight deliverables. Unresolved inventory discrepancies require clarification or human assistance, not silent exclusion.

## Completion evidence

Orchestrator records fresh baseline sources, RED/GREEN/refactor results with excerpts, governance command/results, changed-path review, independent Tester findings/decision, application-check N/A reasons and the human merge gate. Never label planned checks as executed or #62 as closed prematurely. No prior execution evidence or cache inventory is restored as a current observation. This plan matches the approved issue and operational clarification without adding product scope or test-suite files.
