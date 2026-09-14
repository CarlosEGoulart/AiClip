# Implementation Plan: Project Management Closeout Maintenance

## Issue and Scope

[Issue #32](https://github.com/CarlosEGoulart/AiClip/issues/32) authorizes only the seven files listed in `spec.md`. No executable tests, scripts, dependencies, permissions, application behavior, or CI changes are permitted.

## 1. Preserve the Baseline and Complete SDD

Orchestrator has created `@carlosegoulart/32/chore/reconcile-project-management-closeout` from final PR #31 head `8bd43cee1bb08cacfa28baa2bdd3ec176fc2c0a1`. The remote merge commit is verified but its object is not local. Do not require that unavailable object for local diff commands or claim the branch was based on it.

The pre-existing edits to `docs/project-state.md` and `specs/030-project-management/evidence.md` were carried unchanged. Inspect and preserve these edits. Incorporate verified useful content and correct unsupported claims; do not discard either document or restore it wholesale from Git.

Planner creates only `spec.md`, `plan.md`, and `test-plan.md`. Before maintenance execution, an authorized executing role creates a nonempty `evidence.md` with honest pending execution/review status. Do not copy #30's approval as the maintenance decision. Do not fabricate a Tester decision to satisfy a validator.

## 2. Execute Written Manual Artifact RED

Execute A1-A7 from `test-plan.md` before editing any of the three cleanup targets. Use permitted read, glob, search, and read-only diff inspection. These are written manual acceptance tests, not automated tests or new test code.

For every assertion, record the procedure, expected result, actual observation, supporting path/excerpt, and PASS/FAIL. At least the relevant missing maintenance outcomes must yield actual failures before correction. Assertions already satisfied remain PASS; do not manufacture failures. Existing green governance checks and an incomplete new bundle do not substitute for maintenance RED.

If A1 finds a consumer or a behavior impact, stop and return to Orchestrator for clarification. Do not change consumers or broaden the deletion.

## 3. Reconcile Sources Without Re-auditing the Feature

Use the original issue/spec, the identified implementation files, final-head CI jobs, and the supplied September 14 final Tester conversation record. Source inspection is limited to checking documentation accuracy and deletion safety; it is not a fresh feature acceptance review.

The final review is APPROVE for code/CI review on 2026-09-14, without independent browser inspection. Remove obsolete September 10 final-approval framing. Do not invent additional review actions or provenance.

Orchestrator provides the final-head logs to the executing roles as needed. Backend source: run `34859619119`, job `104028067409`, with 89 passed, 905 assertions, including 14 project cases. Verify all other numerical results against their actual logs/reports before including them. If a required source cannot be accessed, report the limitation; do not substitute declarations, stale prose, or guessed totals.

## 4. Apply Only the Maintenance Corrections

Builder deletes `apps/api/app/Http/Requests/UpdateProjectRequest.php` after verifying that it is an unused empty stub. No other API file changes are allowed.

Builder rewrites `specs/030-project-management/evidence.md` as a concise final merged account:

- Correct final issue/PR status, final head, and remote merge identity.
- Four authenticated operations; name and nullable description validation; public resource fields; session-derived ownership; non-owner 404; creation throttling; CSRF/session-cookie authentication.
- Existing frontend capabilities, responsive implementation, and honestly bounded accessibility observations.
- Separate September 14 final Tester code/CI APPROVE from four workflow PASS summaries and five successful checks.
- Source-backed final CI totals and explicit historical TDD/independent browser evidence limitations.
- No stale update implementation, absent-description, missing-SDD, blocked-governance, future-execution, or hypothetical RED claims.

Orchestrator corrects `docs/project-state.md` with exactly the six headings, concise completed capabilities, #30 complete/#31 merged, and M2 project-scoped upload/storage before processing, AI, rendering, or social work. Explicitly retain that M2 implementation is not authorized. Do not prematurely claim #32 closure.

## 5. Execute GREEN and Final Rerun

Repeat A1-A7 unchanged against the corrected artifacts. Record actual results in #32 evidence, separately from #30's historical account.

Orchestrator runs the unchanged governance suite from repository root:

```sh
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Record actual test counts, exit status, and any failures. Do not change tests, validators, CI, or permissions to obtain GREEN. Route unexpected failures to Orchestrator rather than fixing unrelated work.

Review concision, source attribution, and scope after GREEN. If wording is refined, repeat all artifact checks and the governance command. If no refactor is needed, record that fact and the actual final rerun; do not invent refactor work.

## 6. Independent Maintenance Testing and Handoff

Tester independently executes the same manual artifact tests and unchanged governance suite, reviews source-backed evidence and the seven-file diff including untracked files, and records APPROVE or REJECT for #32 only. The original feature's approval and CI results do not satisfy this maintenance gate.

New application unit/API/E2E/browser interaction is N/A for this behavior-neutral maintenance diff, not retroactively N/A for #30. Do not run a new visual feature audit to manufacture historical evidence. Existing required CI remains active and unchanged for the eventual maintenance PR.

Report implementation and independent review results to Orchestrator. Only Orchestrator performs subsequent commit, push, PR, CI monitoring, merge, and closure operations. CI must be green before merge. After verified closure, return to NO_ACTIVE_ISSUE; do not start M2.

## Permission Boundaries

Use permitted tools directly. Builder does not run governance commands denied to it; Orchestrator and Tester use their existing approved entry point. No new executable test files, alternate-path test modules, inline scripts, interpreter workarounds, or permission changes are part of this plan. Report any denied required operation rather than bypassing it.
