# Specification: Project Management Closeout Maintenance

## Issue

- [Issue #32](https://github.com/CarlosEGoulart/AiClip/issues/32)
- Title: chore(projects): reconcile final project-management evidence and remove unused request
- Branch: `@carlosegoulart/32/chore/reconcile-project-management-closeout`

## Description

Perform one behavior-neutral maintenance cycle for completed Issue #30 and merged PR #31. Remove the unused update-request stub, reconcile the final historical evidence, and correct project-state. Preserve and incorporate the existing scoped local documentation edits. Do not reopen #30, re-audit its feature, or start M2.

The branch was created from locally available final PR head `8bd43cee1bb08cacfa28baa2bdd3ec176fc2c0a1`. The merge object is not locally available. This does not change the verified remote merge identity: `364733af595016f0a0d40f7dac5f106601ee3063`. Do not describe the branch as created from that merge object.

## Exact File Scope

Only these seven paths may change:

1. `apps/api/app/Http/Requests/UpdateProjectRequest.php` — delete only, after checking consumers.
2. `specs/030-project-management/evidence.md` — reconcile final historical evidence.
3. `docs/project-state.md` — concise state correction.
4. `specs/032-reconcile-project-management-closeout/spec.md` — Planner specification.
5. `specs/032-reconcile-project-management-closeout/plan.md` — Planner implementation plan.
6. `specs/032-reconcile-project-management-closeout/test-plan.md` — Planner manual acceptance tests and validation plan.
7. `specs/032-reconcile-project-management-closeout/evidence.md` — executing roles' maintenance evidence and independent Tester decision.

## Technical Tasks

- [ ] Complete the four-file SDD bundle with honest pending status before execution.
- [ ] Execute the written manual artifact tests before editing the three cleanup targets and record actual assertion-based RED.
- [ ] Confirm the empty request stub has no consumers and delete only that file.
- [ ] Rewrite #30 evidence against the final implementation and final-head CI sources.
- [ ] Correct project-state using the exact six headings and storage-first next goal.
- [ ] Repeat the artifact tests, run unchanged governance checks, and obtain independent maintenance Tester approval.

## Required Historical Evidence

### Final Status and Implementation Contract

The rewritten #30 evidence must identify Issue #30 closed/completed, PR #31 merged, the final PR head, and the merge commit given above. It must accurately summarize the existing implementation, not propose changes:

- Laravel Sanctum SPA session/cookie authentication and CSRF protection; all project endpoints use `auth:sanctum`.
- `GET /api/v1/projects`, `POST /api/v1/projects`, `GET /api/v1/projects/{project}`, and `DELETE /api/v1/projects/{project}`; no update endpoint.
- Required string name, maximum 255 characters; optional nullable string description, maximum 1000 characters.
- Ownership derives from the authenticated session, not client-supplied `user_id`. List/create use the user's project relationship; show/delete perform an explicit ownership check returning 404 for non-owners.
- Project creation is throttled. Do not change its configuration.
- Public `ProjectResource` fields are `id`, `name`, `description`, `created_at`, and `updated_at`; `user_id` is not exposed.
- Existing frontend provides project list/create/delete, optional description, delete confirmation, and loading/empty/error/pending states within the authenticated shell.
- Describe the existing responsive frontend and configured viewport coverage without claiming independent visual validation. Qualify accessibility observations as source/code review unless actual interaction evidence exists.
- The original four SDD documents exist. Remove stale missing-bundle and blocked-governance claims.

Do not imply the maintenance deletion was part of PR #31. Do not retain an obsolete implementation inventory merely to preserve previous wording.

### Final Tester Decision Versus CI

Record the final **Tester APPROVE on 2026-09-14** from the supplied conversation review as **code/CI review, with no independent browser inspection**. The obsolete September 10 approval must not be presented as the final decision. Do not invent a reviewer identity, session details, actions, or a public review URL absent from the source record.

Present final **Backend CI PASS**, **Frontend CI PASS**, **E2E CI PASS**, and **Governance PASS** separately from the Tester decision. Distinguish four workflows from five successful checks at the final PR head:

| Workflow / check | Run ID | Job ID |
| --- | --- | --- |
| Backend CI / tests | 34859619119 | 104028067409 |
| Frontend CI / test | 34859619142 | 104028067767 |
| E2E CI / e2e | 34859619246 | 104028067347 |
| governance / governance | 34859619288 | 104028069051 |
| governance / pr-enforcement | 34859619288 | 104028069525 |

The supplied official backend job log establishes **89 passed, 905 assertions, including 14 project cases**. Cite the corresponding job source. Every additional numerical test result must be checked against its final-head log/report. Distinguish whole-suite totals, project-only cases, and scenarios versus viewport executions. Source declarations are not execution results. Never label these final-head results as tests executed on the merge commit unless separately verified.

### Evidence Limits

Remove stale pre-fix summaries, future-execution instructions presented as final results, hypothetical RED, and code-review-only test PASS claims. Historical assertion-based RED is not established by statements that tests "would fail" or encounter missing modules. Do not reconstruct historical TDD using present-day code or invent past commands/results.

CI success and screenshot artifact existence do not establish independent project visual review, keyboard interaction, accessibility validation, or console/network inspection. Explicitly retain these evidence bounds rather than converting them into PASS claims. Do not re-audit the feature to fill historical gaps.

## Project State Contract

Use exactly these six headings, in this order, with concise English content and no additional headings:

```markdown
# Current Architecture
# Completed Capabilities
# Important Decisions
# Known Limitations
# Current Milestone
# Next Architectural Goal
```

Record M1 Sanctum SPA authentication and authenticated project management complete, Issue #30 complete, and PR #31 merged. Preserve relevant architecture, decisions, and limitations without duplicating detailed evidence or volatile test totals. Describe supported operations explicitly rather than implying project editing exists. Do not prematurely mark maintenance Issue #32 complete.

The next architectural goal is **M2 Media Storage: project-scoped upload and storage before transcoding, scene detection, clip analysis, AI ranking, rendering, or social publishing**. Explicitly state that M2 implementation is not authorized by this issue.

## Acceptance Criteria

- [ ] The seven-file scope is respected and the unused stub is absent with no consumers or behavior changes.
- [ ] #30 evidence satisfies the final status, implementation, provenance, CI counts, and verification-limit contracts above.
- [ ] September 14 final Tester APPROVE is distinct from CI PASS and does not imply independent browser inspection.
- [ ] Project-state satisfies the exact headings, completion status, concise content, and unauthorized storage-first M2 goal.
- [ ] Manual artifact RED, GREEN, and final rerun are recorded as manual tests, not automated tests.
- [ ] Relevant existing governance unit/integration checks pass without test or validator modifications.
- [ ] New application unit/API/E2E/browser checks are N/A for this behavior-neutral diff, with an explicit reason; existing required CI remains unchanged and passes.
- [ ] Independent maintenance Tester approves the final artifacts before Orchestrator proceeds with commit/PR; CI is green before merge.
- [ ] Orchestrator verifies closure of #32 and returns to NO_ACTIVE_ISSUE without starting M2.

## Security and UX Considerations

Confirm the stub has no consumers. Preserve authentication, CSRF, session ownership, isolation, and throttling behavior. Cite public sources without copying credentials or sensitive logs. No UI changes are authorized; distinguish implemented UI, code review, actual CI execution, and independent visual/accessibility evidence.

## Out of Scope

Product features, M2 implementation, feature re-audit, working project tests, auth or other production behavior, CI, permissions, dependencies, new executable governance tests, scripts, and unrelated cleanup or documentation.

## Dependencies and Ownership

Dependencies are the verified closure/merge of #30/#31, final-head CI logs accessible to Orchestrator, and the supplied September 14 review record. No permission changes or new regression module are needed.

Planner owns the three planning documents. Builder owns the scoped deletion and historical evidence correction. Orchestrator owns project-state and lifecycle coordination. Executing roles record maintenance results in the new evidence file; independent Tester owns its current approval. The original approval is not approval of #32.
