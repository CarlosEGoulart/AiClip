# Test Plan: Project Management Closeout Maintenance

## Issue and Strategy

[Issue #32](https://github.com/CarlosEGoulart/AiClip/issues/32) is behavior-neutral documentation/dead-stub maintenance. Use the written manual artifact acceptance tests below and the unchanged governance suite. Do not add executable tests, scripts, test modules in alternative locations, or dependencies.

Manual artifact assertions satisfy this issue's pre-change RED and post-change GREEN by explicit expected-versus-observed comparison. They must be defined before target edits and actually executed. They are not automated unit tests. Running an already-green governance suite alone does not establish maintenance RED.

## Execution Record

Record each A1-A7 execution in this issue's `evidence.md`:

```text
Test ID:
Phase: RED / GREEN / final rerun / independent review
Inspection performed:
Expected:
Observed:
Result: PASS / FAIL
Supporting path/excerpt or source reference:
```

Use permitted read/glob/search tools for the same paths and assertions in every phase. For tool inspections, shell exit status is N/A because no shell command ran. For executable governance commands, record their actual exit status and test count. Do not describe planned or inferred results as execution.

## A1 — Unused Request Absence and No Consumers

Procedure:

1. Glob the exact path `apps/api/app/Http/Requests/UpdateProjectRequest.php`.
2. If present, read the file and identify whether it contains executable implementation or only the namespace/comments.
3. Search for `UpdateProjectRequest` in first-party files under `apps/api/app`, `apps/api/routes`, `apps/api/config`, `apps/api/bootstrap`, and `apps/api/tests`, plus `apps/api/composer.json`. Exclude generated caches, dependencies, and secrets. Inspect each match for a consumer rather than counting documentary mentions as calls.

Expected: the target file is absent and no consumers exist. Before deletion, record its presence as FAIL for the absence assertion and separately report the consumer check. If a consumer or behavior impact is found, stop for clarification rather than altering it.

## A2 — Final Status and Implementation Contract

Read `specs/030-project-management/evidence.md` in full. For narrowly targeted consistency checks, use the original `spec.md`, `apps/api/routes/api.php`, `ProjectController.php`, `StoreProjectRequest.php`, and `ProjectResource.php` in their existing first-party directories. Read relevant project frontend files only as needed to confirm a documented statement; do not execute a new feature audit.

Expected:

- #30 is closed/completed; PR #31 is merged; final head is `8bd43cee1bb08cacfa28baa2bdd3ec176fc2c0a1`; merge is `364733af595016f0a0d40f7dac5f106601ee3063`.
- Operations are GET list, POST create, GET show, and DELETE; no update endpoint.
- Name is required/string/max 255; description is optional/nullable/string/max 1000.
- `auth:sanctum`, session/cookie authentication, CSRF, session-derived ownership, non-owner 404, and creation throttling are described accurately.
- List/create relationship scoping is distinguished from show/delete ownership checks. Client-supplied ownership is not trusted.
- Public resource has id/name/description/timestamps and no `user_id`.
- Frontend description support, list/create/delete, confirmation, relevant states, and responsive implementation are summarized without inventing independent interaction evidence.
- All original SDD files exist; no implemented update request, absent-description, or missing-bundle claim remains.

Record any mismatch as FAIL with an excerpt. Do not change application files to make the documentation true.

## A3 — Final Tester Provenance

Read the final Tester section and compare it with the supplied September 14 conversation review record.

Expected: final **APPROVE on 2026-09-14** is clearly a code/CI review, with **no independent browser inspection**. The September 10 obsolete approval is not the final decision. Do not invent reviewer identity, runtime actions, or public review links. The review is separate from final CI PASS and from #32's independent maintenance decision.

## A4 — Source-Backed Final CI

Read the final CI section and compare it with the following final-head jobs. These job URLs are source references, not evidence that an executing role has already read their logs:

| Workflow / check | Source |
| --- | --- |
| Backend CI / tests | https://github.com/CarlosEGoulart/AiClip/actions/runs/34859619119/job/104028067409 |
| Frontend CI / test | https://github.com/CarlosEGoulart/AiClip/actions/runs/34859619142/job/104028067767 |
| E2E CI / e2e | https://github.com/CarlosEGoulart/AiClip/actions/runs/34859619246/job/104028067347 |
| governance / governance | https://github.com/CarlosEGoulart/AiClip/actions/runs/34859619288/job/104028069051 |
| governance / pr-enforcement | https://github.com/CarlosEGoulart/AiClip/actions/runs/34859619288/job/104028069525 |

Expected: four workflows, five successful checks, and distinct Backend CI PASS, Frontend CI PASS, E2E CI PASS, and Governance PASS. The supplied official backend log establishes 89 passed, 905 assertions, including 14 project cases. Verify and cite other claimed numerical totals using their final-head logs/reports. Separate suite totals from project-only cases and scenarios from viewport executions; never infer execution totals solely from source declarations or code review.

Orchestrator may retrieve the logs using its permitted read-only commands:

```sh
gh run view 34859619119 --repo CarlosEGoulart/AiClip --job 104028067409 --log
gh run view 34859619142 --repo CarlosEGoulart/AiClip --job 104028067767 --log
gh run view 34859619246 --repo CarlosEGoulart/AiClip --job 104028067347 --log
gh run view 34859619288 --repo CarlosEGoulart/AiClip --job 104028069051 --log
gh run view 34859619288 --repo CarlosEGoulart/AiClip --job 104028069525 --log
```

Record provenance honestly when another role supplies a verified log excerpt. Unavailable required sources are a reported limitation, not permission to invent totals or to claim the assertion passed.

## A5 — No Stale or Invented Evidence

Read all of #30 evidence, including TDD and verification limits. Search for `would fail`, `requires execution verification`, `Description field not implemented`, and `missing` to locate potentially stale claims; inspect matches in context. Keyword absence alone is not a semantic test.

Expected: stale pre-fix, missing-SDD, blocked-governance, future-execution, hypothetical RED, and code-review-only test PASS claims are removed. The document does not claim historical assertion-based RED without actual sources and does not reconstruct it using current code.

Independent browser, project visual, keyboard, accessibility, and console/network checks remain explicitly unverified where no actual evidence exists. ARIA/source observations are not interaction validation. E2E success and screenshot artifact existence are not independent visual approval. Do not re-audit the feature to fill these gaps. #32's manual RED/GREEN must not be presented as #30's historical TDD.

## A6 — Exact Project-State Structure and Next Goal

Read `docs/project-state.md` in full and enumerate every Markdown heading.

Expected: exactly these six headings in order, with no additional headings:

```markdown
# Current Architecture
# Completed Capabilities
# Important Decisions
# Known Limitations
# Current Milestone
# Next Architectural Goal
```

Content must concisely record M1 Sanctum SPA authentication and authenticated project management complete, #30 complete, and PR #31 merged. Preserve relevant architectural decisions and known limitations; do not imply project editing exists or prematurely mark #32 closed. Prefer evidence references to volatile test-count inventories.

The next goal must be M2 Media Storage, explicitly project-scoped upload/storage **before transcoding, scene detection, clip analysis, AI ranking, rendering, or social publishing**. M2 implementation must explicitly remain unauthorized.

During RED, headings already correct remain PASS while an incorrect next goal is FAIL. Do not manufacture a heading failure.

## A7 — Seven-File Scope and Complete SDD

Orchestrator or Tester inspects status and the complete scoped diff using permitted read-only commands:

```sh
git status --short
git diff -- docs/project-state.md specs/030-project-management/evidence.md
git diff --check
git diff --name-status 8bd43cee1bb08cacfa28baa2bdd3ec176fc2c0a1
git diff 8bd43cee1bb08cacfa28baa2bdd3ec176fc2c0a1 -- apps/api/app/Http/Requests/UpdateProjectRequest.php docs/project-state.md specs/030-project-management/evidence.md specs/032-reconcile-project-management-closeout/
```

The local baseline is the available final PR head, not the unavailable merge object. Also read every untracked file identified by status: Git diff does not show untracked contents.

Expected: only the seven paths in `spec.md` change; the request is deletion-only; no working application tests, behavior, auth, CI, permissions, or unrelated artifacts change. Verify all four new SDD documents are regular nonempty files and existing local documentation edits were reconciled rather than discarded. Pending execution/review is honest until actual results exist. #32's approval must be independent, not inherited from #30.

This scope/bundle check may already pass before target edits. A missing new bundle is not sufficient maintenance RED.

## RED, GREEN, and Final Rerun

Before target edits, executing roles perform A1-A7 and record actual expected-versus-observed failures attributable to the requested maintenance. Previously observed stale content is a baseline lead, not a substitute for execution. Some assertions may pass initially.

After corrections, repeat the same assertions for GREEN. Orchestrator runs the unchanged governance suite from repository root; independent Tester reruns it for the maintenance gate:

```sh
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Record actual test count, exit status, and failures. This suite validates existing governance unit/integration contracts, including complete SDD bundles; it does not automatically verify prose meaning. No test/validator changes or test-writing permission workarounds are allowed. Builder must not run a command denied to its role.

After any wording/refactor changes, rerun A1-A7 and the governance command without weakening assertions. If no refactor is needed, say so and record the actual final rerun. Tester independently reviews the artifacts and sources, executes the checks, and records a current #32 decision before Orchestrator proceeds with commit/PR.

The existing PR environment continues to run `python tests/governance/pr_enforcement.py`. Do not fabricate local GitHub event context or credentials to execute it outside its approved environment. Existing required CI must pass unchanged before merge.

## Applicability and Evidence Bounds

New application unit/API/E2E tests and new browser/visual/accessibility interaction are **N/A for this maintenance diff because it changes no application behavior or interface**. This does not retroactively waive #30's original feature verification requirements or establish missing independent browser evidence. No feature re-audit is authorized.

Record maintenance results in `specs/032-reconcile-project-management-closeout/evidence.md`. Keep #30's final historical account separate. Do not claim execution, Tester approval, or CI outcomes before they occur.
