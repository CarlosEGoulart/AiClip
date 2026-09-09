# Test Plan: Issue #1 Governance Bootstrap

Issue: <https://github.com/CarlosEGoulart/AiClip/issues/1>

Contract: [spec.md](spec.md). Sequence and ownership: [plan.md](plan.md).

## Test approach and applicability

Use one small Python `unittest` suite with a pinned PyYAML dependency confined to governance tooling. User steering explicitly replaces the oversized validator with roughly 8-12 behavior-oriented checks, preferably about 200-300 readable lines or less. These are sizing guidelines, not acceptance quotas. Parse YAML safely with duplicate-key detection; use a straightforward last-matching-glob helper for representative permission samples. Native OpenCode is authoritative for runtime schema/loading and effective configuration. Do not reproduce its schema, policy engine, global merging or shell parsing.

Independent review covers policy meaning, English-only prose, scope/changed paths, local links, external installation documentation, skill completeness and truthful evidence. No exact prose matcher, exhaustive duplicate of root documents, self-inspection of test source or general link/path-validation framework is required. This revised approach supersedes earlier exhaustive automated-check demands while preserving issue acceptance criteria.

After Orchestrator prepares the environment with `python -m pip install -r tests/governance/requirements.txt`, the local and CI entry point is:

```sh
python -m unittest discover -s tests/governance -p 'test_*.py' -v
```

Governance unit/contract tests and real OpenCode configuration integration checks apply. Application unit/API/database/E2E tests, Playwright execution, screenshots and visual/a11y interaction are N/A for this issue because no application or interface exists or changes. Their future mandatory requirements must still be reviewed in the delivered guidance. Do not initialize an application to manufacture these tests, or claim the OpenCode configuration smoke check is Playwright coverage.

## RED requirements

The required restored-baseline RED has already been recorded by Orchestrator: 64 tests, 40 assertion failures, zero errors before rebuilding. Preserve it separately from the invalid first attempt. After simplifying the suite, run it before implementing remaining governance content. Missing required artifacts must produce explicit assertion failures rather than unhandled missing-file exceptions. Existing policy contradictions are manual-review findings, not a requirement to retain prose-matching tests.

Record actual command, resolved root, exit status, failing test names/assertions and why they show missing required behavior. Tests must start successfully with dependencies available. Syntax errors, missing libraries, network outages or broken fixtures do not qualify. Consolidating redundant tests and moving prose checks to explicit manual review is authorized; retained meaningful configuration/permission checks must not be weakened to obtain GREEN. For a newly found defect in already-written permissions, demonstrate a focused assertion or native-loader failure before correction. Do not rewind the repository again to manufacture RED.

## Lightweight automated checks

| ID | Scenario and expected result | Acceptance |
| --- | --- | --- |
| G1 | Derive the root portably from the test location; baseline files and all required scoped artifacts exist and are nonempty. One table-driven check with path diagnostics replaces many individual existence tests. | AC2-AC4 |
| G2 | Four agent frontmatters parse as mappings with descriptions, correct primary/subagent modes and tool-keyed permission maps rather than arrays; omit durable model IDs/deprecated tool switches. Leave complete schema validation to native OpenCode. | AC2 |
| G3 | Representative edit decisions allow required issue work and deny unrelated paths: Orchestrator state/evidence only, Planner planning files only, Builder scoped implementation with Planner files denied, Tester evidence only. Check deny defaults and sampled last-match outcomes rather than duplicating every artifact allowance. | AC2 |
| G4 | Representative task/shell decisions allow Orchestrator's three named delegates, deny an unknown delegate and subagent delegation, allow a needed inspection/test command where applicable, and deny subagent lifecycle/arbitrary commands. Check broad default denial; review the full maps manually. | AC2 |
| G5 | Exactly six local skills parse; names match directories and supported name format/length; descriptions are nonempty and within supported length; bodies are nonempty. Topic completeness is manual review. | AC3 |
| G6 | Project-state top-level headings equal the six specified headings in order, with content under each. One structural check is enough. | AC4 |
| G7 | Basic CI shape: pull_request and master push triggers, read-only contents permission, nonpersistent checkout credentials, Python setup and the real governance test command; dependency is pinned. Handle YAML's `on` key correctly. Review absence of services/model calls/secrets and overall scope manually; actual GitHub execution is authoritative. | AC6 |
| G8 | The same safe frontmatter loader used on real files rejects malformed YAML and duplicate keys, with a valid mapping control that passes. A minimal SafeLoader extension suffices. | AC2-AC3 |
| G9 | The same permission-check/helper path used on real files rejects an all-allow policy, with a narrow valid policy control; verify broad-first/specific-last matching using a tiny ordered example. | AC2 |

Use loops/subtests and a few small shared helpers. The table groups behaviors, not a mandatory number of test methods. G8-G9 are sufficient focused negative-case obligations; no negative fixture is required for every documentation statement, heading variation or skill topic. Fixtures must exercise actual helpers rather than a parallel validator that real-file tests never use. No parallel fixture repository, exhaustive schema/path snapshot, mutation-testing framework or sentence-level policy checker is required.

## Real-runtime integration checks

Orchestrator supplies a fresh process and environment; Tester independently exercises and reviews results. Consult `opencode --help` and subcommand help for the installed version rather than assuming all current website features exist in 1.18.30.

| ID | Procedure | Required evidence |
| --- | --- | --- |
| R1 | Run `opencode --version` and `opencode agent list` after configuration creation/restart; inspect available supported configuration diagnostics without exposing secrets. | Actual version, four project roles, modes and effective permission observations; configuration errors fail. |
| R2 | Use native skill discovery/loading to verify the six local names and descriptions, with role-appropriate access. Inspect duplicate names/global overrides. | Actual discovery/tool results, not just a directory listing. External skills may remain uninstalled under the documentation-only choice. |
| R3 | Discover free candidates using `opencode models` and current provider metadata/documentation. Perform a bounded minimal actual call using a runtime-selected candidate. | Actual provider/model identifier, response and call outcome; verify effective selection from runtime metadata. Catalogue presence alone is insufficient. No ephemeral ID is committed as a configuration default. |
| R4 | Start the configured Orchestrator and request read-only handoffs to Planner, Builder and Tester through named task delegation. Confirm separate contexts. | Actual invoked role/model/tool events. Detect CLI subagent fallback to primary and use the supported task path. A successful unrelated default-agent answer fails this check. |
| R5 | Verify a representative allowed read and approved governance test command; check Planner out-of-scope edit, Tester implementation edit, subagent lifecycle command, and subagent task denial using non-destructive probes. | Observed tool permission decisions and unchanged protected content/state. A verbal refusal is behavioral evidence, not proof of tool denial. |
| R6 | Review bootstrap implementation/review context identity and configuration limitations. | Separate Builder/Tester sessions, effective models, free-call result or explicit bootstrap limitation, and inherited/global permissions relevant to results. |

For R5, never run a real forbidden lifecycle mutation. If the runtime cannot expose a denial without risking mutation, inspect its effective policy and use a disposable scratch fixture for an actual harmless denied action. Document which decisions were inspected versus exercised. Do not widen permissions to make the check run. Orchestrator launches runtime checks that Tester is not permitted to launch directly; Tester must independently interact with the configured behavior rather than relying solely on a Builder transcript.

Shell allowlists constrain ordinary tool use but do not sandbox hostile agents or arbitrary code inside allowed tests. Inspect representative command composition/interpreter escape risks and require explicit no-bypass instructions. Do not build an elaborate adversarial sandbox test harness or claim exhaustive security. Manual user mentions can bypass task visibility restrictions, and inherited settings can affect effective behavior; document both.

If a free provider call fails, record its actual error and bounded retry/other verified candidate result. The explicitly permitted isolated harness-general bootstrap path may be used with model limitations recorded; it does not prove a free model was used. Required custom-role discovery/invocation and independent review remain gates. Unavailable required runtime checks are BLOCKED and return to Orchestrator, not passed through omission.

## Independent content and scope review

Tester reviews actual acceptance criteria and the final diff, with these focused scenarios:

1. **Lifecycle:** Orchestrator verifies GitHub issue creation before the canonical branch, one active implementation issue, Conventional Commits/PR linkage, Tester/CI gates, same-issue rejection and stop after closure. Do not infer chronology from a filename or require the local issue branch name inside CI's detached checkout.
2. **Role boundaries:** all four bodies agree with effective permissions; Planner writes only planning documents; Builder cannot perform lifecycle operations; Tester cannot silently repair; Orchestrator does not implement Builder artifacts. No route through alternate tools/CLI sessions/global overrides is authorized. Issue #1's self-configuration exception is explicit, scoped and not a permanent authority grant.
3. **TDD/context:** preserve valid rework RED before rebuilt content and fresh focused RED before new corrections; review authorized test consolidation without losing meaningful configuration checks; applicable future unit/integration/E2E requirements preserved; isolated handoffs, targeted reads, no bulk context loading.
4. **Visual skill:** verify every viewport, layout/overflow/spacing/alignment/typography item, dialogs/navigation/media, loading/empty/error/success/disabled/hover/active/focus states, destructive controls, keyboard/focus/labels/contrast, screenshots, console/network/API/uncaught errors/resources/redirects, and rejection on unexpected errors or poor visual quality. Application-free exception must not waive future UI review.
5. **Media skill:** async Laravel-to-worker boundary; no heavy HTTP inference; safe argument-list FFmpeg/FFprobe commands and input/path validation; timeout/resource/exit/cancellation handling; job-scoped temporary ownership, success/failure/cancellation cleanup and abandoned-job recovery; protect originals; retry/idempotency and deterministic provider tests.
6. **Social skill:** official APIs/docs, user confirmation of destinations/content, server-side secret handling, per-platform idempotency and state, partial failures, successful destinations preserved across retries, rate limits/backoff, timeout reconciliation, revoked access and deterministic fakes.
7. **External skills:** verify upstream source names and installation documentation, reviewed-revision/resource/license instructions, OpenCode global destination/name matching, duplicate detection, restart/discovery steps, and truthful uninstalled status. Taste applicability and repository/accessibility precedence must be explicit; no invented source path or unverified installer options.
8. **Documentation:** concise truthful state; proposed stack/roadmap clearly planned; ADR reflects this decision rather than fabricated history; English-only content, working local documentation links and useful existing governance preserved. Explicitly review the governance-only running-app exception, absolute application-initialization prohibition and authorization required after closure across affected documents. No credentials, realistic example secrets, placeholder completion or unsupported approval claims. Evaluate meaning, not mandated sentences.
9. **Scope:** inspect all changed paths and content for application initialization, database schema, media/social implementation, UI, deployment/infrastructure, unrelated dependencies/refactors or additional issues.

## CI and approval evidence

CI runs the small automated suite on pull requests and pushes to `master`. Local success does not establish CI success, and CI does not establish live model availability, independent approval or historical RED. External source verification and runtime calls are local/manual gates, not nondeterministic CI tests.

In `evidence.md`, distinguish PASS, FAIL, BLOCKED and N/A with reasons. Record concise preflight references, RED/GREEN/refactor command outcomes, runtime role/model observations, permission limitations, external documentation verification and an independent Tester APPROVE/REJECT decision tied to the reviewed diff. Orchestrator records later CI/PR/merge/closure references only when actually observed, using GitHub/final handoff after closure rather than an issue-less commit.

Reject missing required behavior, inconsistent permissions/docs, invalid RED, unexpected scope, failed required checks or unverified completion claims. Route corrections through Orchestrator to Builder for issue #1, then rerun affected checks and obtain fresh independent approval. Completion requires all applicable gates, actual green CI, authorized merge and verified issue closure; then stop.

## Rework acceptance after the first Tester REJECT

The first restoration is complete and its valid RED is recorded at `evidence.md:131-133`; retain the rejected original history from line 80 and the correction from line 123. The second Builder run was reported interrupted after three agent files were written. Continue that work under the simplified G1-G9 scope above. This replaces the earlier exhaustive rework-test/negative-fixture obligations; all original issue deliverables and independent-review requirements remain.

1. Preserve current artifacts, spec/plan/test-plan and complete evidence. Do not repeat the restoration or claim the interrupted run completed. Document the authorized test simplification and the transfer of prose/link/scope checks to independent review.
2. Confirm portable root discovery (currently derived from `parents[2]`) and passing baseline/valid-helper controls. The evidence's portability follow-up must be resolved with an actual recorded run, not an assumed historical result. No hardcoded workstation path belongs in tests.
3. Run the small suite before remaining implementation. Missing content must still yield meaningful assertion RED without harness errors; any already-present defective permissions get a focused assertion/native-loader RED before correction. No artificial failure is needed for working content. Preserve the existing valid restored-baseline RED as its own historical event.
4. Reach GREEN with the retained meaningful checks; complete all outstanding configuration, documentation, skill and CI findings through automation or the explicit manual review as appropriate. Fresh OpenCode 1.18.30 agent/skill loading, effective policies and named task behavior must work. Parser success and test count alone cannot establish acceptance.
5. Independent Tester reviews actual chronology, final diff, native runtime evidence and all issue criteria, including manual checks. Approval, actual CI success, authorized merge, verified closure and stopping after this issue remain mandatory.

Run from the verified root with `PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/governance -p 'test_*.py' -v`. Record actual outcomes and append-only evidence corrections. Authorized removal of redundant/prose assertions is not a retrospective RED waiver or permission to conceal a failing important check. No pre-approval commit or scope expansion is permitted.
