# Implementation Plan: Issue #1

Issue: <https://github.com/CarlosEGoulart/AiClip/issues/1>

Branch: `@carlosegoulart/01/chore/init-opencode-agent-architecture`

Specification: [spec.md](spec.md). Verification: [test-plan.md](test-plan.md).

## Ownership and scope

Planner owns only these three planning documents. Builder owns the scoped governance implementation and tests. Tester independently reviews and writes review evidence, never repairs implementation. Orchestrator alone manages lifecycle operations, environment preparation, runtime context launch and final status. All work belongs to issue #1; ambiguity returns to Planner through Orchestrator.

Preserve existing useful governance sections and README roadmap/architecture intent. Make targeted corrections and concise new artifacts. Use one small `unittest` file, one governance-only dependency file for a pinned supported PyYAML release, and one CI workflow. Aim for roughly 8-12 cohesive checks and about 200-300 readable lines or less as guidelines, not acceptance quotas. A straightforward last-match glob helper for representative permission decisions is enough. Native OpenCode loading remains the authoritative runtime schema check. Do not add a validator package, custom YAML parser, policy engine, exhaustive prose matcher, CLI wrapper framework or application dependencies.

## 1. Orchestrator preflight and bootstrap contexts

1. Recheck active issue/PR state and working tree, confirm canonical branch and issue/branch chronology. Preserve existing work.
2. Provide Builder the actual issue, the three planning files and relevant existing governance. Do not preload unrelated history or all skills.
3. Use installed CLI help to select supported invocation/configuration inspection commands. Discover current free model candidates and verify a bounded minimal real call, recording provider/model metadata and outcome before assigning Builder where possible.
4. Prefer a CLI free-model Builder context. Before custom roles exist, an explicitly assigned isolated harness `general` context is permitted for bootstrap. Record effective model and selection limitations; use a separate Tester context and preferably a different model. Do not claim that harness general already has the future custom role's tool restrictions.
5. Orchestrator prepares the small test environment. Builder receives narrow test/inspection commands rather than general dependency installation or shell permissions.

## 2. Builder establishes RED before content

1. Create only `tests/governance/test_governance.py` and its small dependency declaration first, with a minimal ignore entry only if generated tooling artifacts require it.
2. Write the small configuration/artifact checks in `test-plan.md`: required files, frontmatter and modes, exact state headings, representative narrow permissions and basic CI configuration. Assign policy meaning, English, links, scope and documentation/skill completeness to independent review.
3. Run `python -m unittest discover -s tests/governance -p 'test_*.py' -v` from repository root after environment preparation.
4. Verify tests fail through assertions because required artifacts/configuration behavior are absent. Missing test dependencies, unhandled file exceptions and syntax errors are invalid RED. Record existing running-app, application-initialization and automatic-continuation contradictions for independent content review; exact prose assertions are not required.
5. Record concise actual RED command, failing assertions, exit status and timing in `evidence.md` after observing the run. It is an execution record, not a fabricated historical transcript. Do not create agents, skills or corrected governance before RED.

## 3. Builder implements the minimum GREEN increment

1. Correct the existing governance contradictions identified in the specification, including four-role terminology, application-free scope, applicability of runtime/browser QA and closure stopping point. Preserve the strict lifecycle and existing useful constraints.
2. Create the four agent Markdown files with supported frontmatter and the role matrix. Omit committed `model` fields. Use broad denies before narrow allows, exact active specification edit paths and scoped implementation/evidence paths. Do not grant generic shell/Git/GitHub access to subagents.
3. Explicitly document issue #1's Builder self-configuration exception and the limits of static permissions. Editing the specified policy is allowed; widening current runtime privileges or bypassing denied tools is not. Orchestrator arranges restarts and session configuration review.
4. Create the six small, actionable local skills. Use the requirements table in `spec.md` as the checklist, especially media temporary-file lifecycle, social confirmation/idempotency/partial failure retries, and complete future visual-state review.
5. Create concise project-state, PRD, target architecture, roadmap and governance ADR. Distinguish observations, current decisions, future intent and pending verification. Avoid duplicating the full AGENTS policy into every document.
6. Update README links, actual-versus-planned structure and validation instructions. Add external installation and runtime operation instructions to bootstrap using the verified sources in `spec.md`.
7. Document external skills rather than installing/vendoring them in this issue. The manual procedure must map upstream Taste's `skills/taste-skill` directory to its `design-taste-frontend` frontmatter name, preserve required resources/license, use a reviewed revision, restart, and verify discovery. Explain current applicability and project-rule precedence.
8. Add `.github/workflows/governance.yml`: pull requests and pushes to `master`, read-only `contents` permissions, checkout without persisted credentials, Python setup, the pinned governance dependency and the same local unittest command. No model calls, browser/service setup, external skill installation, secret requirements or large downloads. Verify workflow/action syntax against current official documentation when implementing.
9. Run the suite to GREEN. Improve test diagnostics or content organization only where useful; retain meaningful behavior coverage and rerun after refactoring. Authorized consolidation of redundant/prose tests follows the current simplification handoff below. Record actual GREEN/refactor evidence.

## 4. Real OpenCode checks and independent Tester gate

1. Orchestrator starts a fresh OpenCode 1.18.30 process after files exist. Inspect role discovery/effective permissions and skill discovery using supported CLI capabilities, without logging credentials or complete global configuration.
2. Exercise Orchestrator-to-subagent invocation with read-only prompts and inspect actual role/model/tool events. A CLI warning/fallback to another agent does not satisfy the role check. Verify the configured primary/task route instead of changing subagents to primary for convenience.
3. Verify representative allowed reads/test commands and denied edits, lifecycle mutations and delegation. Use harmless probes or an isolated scratch fixture; never perform a real forbidden commit/push/issue mutation to test a denial. Distinguish model refusal from an observed tool-level denial.
4. Tester independently reads acceptance criteria, targeted diff and evidence, reruns the suite, exercises configuration, reviews all required skill topics and checks English-only content and absence of application initialization. Builder confidence is not approval evidence.
5. Record effective models, bootstrap mechanism, runtime results, observed limitations and explicit application/API/E2E/Playwright/visual N/A reasons. A provider outage or unavailable runtime check is blocked, not passed. Orchestrator resolves required blocked checks; no silent waiver.
6. Tester reports APPROVE or REJECT with concrete findings. Rejection goes through Orchestrator to Builder for this same issue; Tester does not repair files. Rerun affected checks after corrections and refresh approval for the final diff.

## 5. Orchestrator completes this issue and stops

1. After independent approval, inspect intended diff/status/history, commit only issue #1 artifacts using Conventional Commits with `Refs #1`, push the canonical branch and create one PR with `Closes #1` and all required review/TDD sections.
2. Wait for actual CI success; route failures through Builder and Tester. Do not infer CI success from a local run or invent branch protection settings.
3. Keep tracked project-state/evidence accurate as of the reviewed commit. Prepare capability documentation before merge; do not claim merge/closure already occurred. Verify final merge and closure using GitHub and report those results in the PR/issue or final handoff, avoiding an issue-less post-closure commit.
4. Merge only after all applicable checks and authorization, verify issue #1 is closed, return to `NO_ACTIVE_ISSUE`, and stop. Do not create or plan a second implementation issue.

## Evidence handoff

Use `evidence.md` for concise chronological sections owned by their actual actors: preflight/model selection, RED, GREEN/refactor, runtime checks, independent Tester decision, and lifecycle references available at recording time. Include commands, exit statuses, relevant assertions, actual roles/models and limitations. Preserve earlier evidence when adding corrections. Full logs, credentials, invented test results, unsupported completion claims and application placeholders are excluded.

## Same-issue rework after the 2026-09-09 Tester rejection

The independent REJECT recorded in `evidence.md` from line 80 invalidates the original claimed RED: `parents[3]` addressed the parent of AiClip, and implementation started after changing to `parents[2]` without rerunning tests. The first attempt remains a failed TDD attempt. This remediation establishes a new, explicitly identified rework cycle; it cannot retroactively validate that history or relax any acceptance criterion.

### Orchestrator-only restoration manifest

This manifest describes the first-rejection restoration already recorded in evidence. It is retained for traceability, not an instruction to repeat the reset after the valid rework RED or the interrupted second Builder run. Continue using the current handoff below.

Before changes, archive the failed snapshot, tests, planning documents, evidence and relevant raw logs outside the repository under `/tmp/opencode`, with a path manifest and content hashes. Verify the archive is readable. User confirms all failed generated work is agent-owned; recheck the working tree against this manifest and stop to investigate any unexpected change. Preserve the issue branch/index/history; no commit is authorized before fresh Tester approval. Do not use a repository-wide hard reset, clean, directory deletion or blanket checkout.

Restore only these existing files to their contents at `9366ef0`, retaining the files themselves:

```text
AGENTS.md
docs/bootstrap.md
```

Remove only these newly generated failed implementation files after archiving:

```text
.github/workflows/governance.yml
.opencode/agents/orchestrator.md
.opencode/agents/planner.md
.opencode/agents/builder.md
.opencode/agents/tester.md
.opencode/skills/issue-linearity/SKILL.md
.opencode/skills/tdd-enforcer/SKILL.md
.opencode/skills/token-efficient-context/SKILL.md
.opencode/skills/playwright-visual-qa/SKILL.md
.opencode/skills/media-pipeline/SKILL.md
.opencode/skills/social-publishing/SKILL.md
docs/project-state.md
docs/prd.md
docs/architecture.md
docs/roadmap.md
docs/adr/0001-governance-bootstrap.md
```

Preserve `README.md` (already unchanged from baseline), all current Planner documents including this clarification and `spec.md`, and the entire `evidence.md`, including the rejection and superseded claims. Preserve `tests/governance/test_governance.py`, `requirements.txt` and `__init__.py`, including any subsequent test-only corrections. The observed generated `tests/governance/__pycache__/test_governance.cpython-312.pyc` may be removed as the only listed disposable test cache; preserve test source. Empty directories need no cleanup. This manifest authorizes restoration only, not Orchestrator implementation.

### Ordered rebuild handoff

1. Orchestrator appends an evidence correction explicitly identifying the earlier RED certification and inaccurate completion claims as superseded by the rejection; retain their original text. Record archive location/hashes, restoration manifest/outcome and the start of the new rework cycle. Do not invent missing timestamps or successful checks.
2. Delegate again to a separate free-model CLI Builder, preferring a more capable currently available candidate if a bounded actual call verifies availability and effective model metadata. Record the selected model and outcome in execution evidence, not durable defaults. Use the existing explicit isolated-context bootstrap allowance only with its limitations recorded. Invalid project configuration is removed by the authorized restoration, not bypassed with permission overrides.
3. Builder first performs test-only repair: retain the correct repository root, keep meaningful checks for the rejection's configuration defects, add only the small negative cases in the current test plan, and pin its governance dependency. Policy/documentation findings go to independent review. Orchestrator prepares the environment. For the initial restoration, no governance artifact may be recreated before verified RED; that RED is now recorded.
4. Builder executes the corrected suite against the restored baseline plus preserved planning/tests/evidence. Orchestrator verifies assertion-based failures at the correct root and records genuine rework RED before releasing the governance rebuild. If a harness defect is corrected, rerun and verify RED again before implementation.
5. Builder rebuilds under the existing specification and plan, addressing all eight Tester findings. Use supported tool-keyed permission maps, not arrays; complete the missing runtime/external-skill documentation and targeted lifecycle/README/skill/CI corrections. No bulk restoration of the failed implementation before RED.
6. Require retained meaningful contract coverage to reach GREEN, actual fresh OpenCode discovery/effective-policy/task checks, and new independent Tester review of the final diff and chronology. The current simplification expressly permits replacing redundant/prose assertions with consolidated checks and manual review. Preserve the first rejection. Commit/PR/CI/merge/closure gates remain unchanged, with no second issue.

## Current handoff: simplify validation and finish the interrupted rebuild

User steering supersedes earlier overbroad automated-test obligations, not issue #1's deliverables. `evidence.md` records a genuine restored-baseline RED: 64 tests, 40 assertion failures, zero errors before rebuilding. The current handoff reports the second CLI Builder was interrupted with `User aborted command`; three agent files now exist, while Tester, skills and required documentation remain incomplete. Preserve this work, planning and evidence; no new reset or issue is needed.

1. Orchestrator records the interruption and this revised verification approach without claiming the aborted run completed. Preserve both the rejected first attempt and valid rework RED. The evidence's portability follow-up needs an actual rerun record; current test source derives the root from `parents[2]`. Verify portability in the simplified suite rather than certifying an unobserved earlier rerun.
2. Delegate a short test-only simplification pass to an isolated runtime-verified free CLI Builder. Consolidate the existing suite into the current test plan's small checks, reuse helpers for real files and negative inputs, and remove prose matching, self-inspection of test source, redundant per-file tests and exhaustive path/link/schema machinery. Briefly map retained checks and manual-review responsibilities in evidence; this is authorized test maintenance, not permission to hide defects.
3. Run the simplified suite at the correct root before further implementation. Known-valid helper controls must pass, while missing Tester/skills/docs/CI artifacts yield genuine assertion RED with zero harness errors. Any detected broken permissions in the three existing agent files need a focused failing check or native loader failure before correction. Record actual results; do not manufacture a failure for already-correct behavior.
4. Continue Builder implementation of remaining scoped artifacts and corrections. Preserve all required skill topics, external installation/runtime procedures, least-privilege/no-bypass/self-configuration rules and lifecycle corrections. Reach GREEN and run fresh native OpenCode loading promptly, correcting real defects instead of expanding the validator.
5. Independent Tester reruns the small suite, exercises native discovery/role/permission behavior and reviews documentation, English, scope, links, installation instructions and all original acceptance criteria. Finish the existing approval/CI/lifecycle gates and stop after issue #1 closure. No numeric test-count or line-count target replaces these gates.
