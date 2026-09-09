# Specification: Initialize OpenCode agent architecture and repository governance

- Issue: [#1](https://github.com/CarlosEGoulart/AiClip/issues/1)
- Type: `chore`
- Branch: `@carlosegoulart/01/chore/init-opencode-agent-architecture`
- Status: specified; implementation and verification are pending.

## Goal and verified baseline

Establish the minimum usable governance foundation for future development, preserving useful existing guidance. This issue delivers agent definitions, engineering skills, documentation, and lightweight governance validation. It implements no application behavior.

Planning inspected `AGENTS.md`, `README.md`, `docs/bootstrap.md`, and the actual GitHub issue. GitHub reports issue creation at `2026-09-09T21:34:51Z`; local HEAD reflog reports checkout from `master` at `2026-09-09T21:34:58+00:00`, at commit `9366ef0`. The working tree was clean on the required branch before these planning files. OpenCode reports version `1.18.30`. These observations are not evidence of implementation completion. Orchestrator must verify issue linearity throughout execution.

## Scope and artifacts

Normalize the three existing governance documents with targeted edits rather than replacing useful content. Create:

- `.opencode/agents/{orchestrator,planner,builder,tester}.md`.
- `.opencode/skills/issue-linearity/SKILL.md`.
- `.opencode/skills/tdd-enforcer/SKILL.md`.
- `.opencode/skills/token-efficient-context/SKILL.md`.
- `.opencode/skills/playwright-visual-qa/SKILL.md`.
- `.opencode/skills/media-pipeline/SKILL.md`.
- `.opencode/skills/social-publishing/SKILL.md`.
- `docs/project-state.md`, `docs/prd.md`, `docs/architecture.md`, `docs/roadmap.md`, and `docs/adr/0001-governance-bootstrap.md`.
- This directory's `spec.md`, `plan.md`, `test-plan.md`, and execution-owned `evidence.md`.
- `tests/governance/test_governance.py`, `tests/governance/requirements.txt`, and `.github/workflows/governance.yml`.

Put runtime operation and external skill installation instructions in `docs/bootstrap.md`; avoid additional documentation layers. A minimal `.gitignore` is allowed only if needed for actual governance tooling artifacts. No new project configuration, plugin, MCP server, application directory, or generalized validation framework is required.

## Governance contract

1. Exactly one implementation issue is active. Preserve the documented state machine and rejection loop. Only Orchestrator performs issue creation, branch operations, commits, pushes, PR creation, merge, and closure. Every lifecycle mutation requires the appropriate authorization and active issue.
2. Follow issue creation, branch creation, specification, verified RED, GREEN, REFACTOR, independent Tester approval, commit, push, issue-linked PR, green CI, merge, and verified closure. Preserve Conventional Commits, real issue branch naming, required PR sections, and explicit acceptance criteria.
3. After closure, return to `NO_ACTIVE_ISSUE`, report completion, and stop. Do not invoke Planner for another issue without explicit authorization. Correct automatic continuation language in `AGENTS.md` and `docs/bootstrap.md`.
4. Correct the hierarchy to four roles: Orchestrator is `primary`; Planner, Builder, and Tester are `subagent`. No silent role switching or combined Builder/Tester review.
5. Amend the unconditional running-application rule in `AGENTS.md` to apply when application behavior is affected. For governance-only changes, independent Tester must exercise the relevant tooling and review the actual artifacts. Application/API/E2E/Playwright/visual checks are N/A with a reason here, not passed or silently skipped. Align README and bootstrap merge/Done language. Future user-facing workflows still require running-app interaction and Playwright visual review.
6. Remove bootstrap's exception allowing application initialization when Planner considers it small. The prohibition covers all application initialization, not merely the complete product. Align `AGENTS.md` first-cycle scope.
7. All new and edited repository artifacts are English-only. Preserve context economy, provider abstractions, authoritative Laravel backend boundaries, secret handling, deterministic fakes, and the no-placeholder-completion rule.

## Agent configuration and role boundaries

Use supported Markdown YAML frontmatter: nonempty `description`, explicit `mode`, and `permission`. Keep instructions in the body. Omit `model` from committed agent definitions; describe runtime selection instead. Use supported permission fields, not deprecated tool switches or invented configuration keys.

Permissions must deny unneeded tools by default, then narrowly allow required operations. In ordered pattern maps, broad defaults precede specific exceptions because the last matching rule wins. `edit` governs `apply_patch`, `write`, and `edit`; `task` patterns target agent names. Enumerate useful read/search/skill access and shell commands rather than granting all tools or all shell access.

| Role | Authorized scope | Explicit prohibitions |
| --- | --- | --- |
| Orchestrator | Relevant inspection; named Planner/Builder/Tester delegation; issue-linked Git/GitHub lifecycle; project-state and evidence updates | Implementing artifacts assigned to Builder; bypassing Tester or CI; starting a second implementation issue |
| Planner | Relevant reads and official documentation; editing only this active issue's `spec.md`, `plan.md`, `test-plan.md` | Production or governance implementation; lifecycle mutations; delegation |
| Builder | Only the implementation artifacts enumerated above, including tests/CI and its implementation evidence | Editing Planner-owned files; issues, branches, commits, pushes, PRs, merges, closure; delegation; weakening assertions or expanding scope |
| Tester | Relevant reads; approved test/runtime inspection commands; its review section in `evidence.md` | Repairing implementation or tests; modifying agent permissions; lifecycle mutations; delegation; self-approval of Builder work |

For shell access, allow documented test entry points and narrowly selected read-only inspection commands. Only Orchestrator receives necessary mutating Git/GitHub commands. Do not use blanket `git *`, `gh *`, `python *`, arbitrary shell execution, or recursive OpenCode execution as subagent allowances. Orchestrator owns environment preparation and runtime session launch when needed; report denied required operations to Orchestrator.

Every role must explicitly prohibit bypass through shell redirection, command composition, interpreters, scripts, alternate tools, nested agents/CLI sessions, environment overrides, or global configuration changes. Allowed test commands execute code: command pattern checks are workflow controls, not a sandbox against hostile agents. Document this limitation, inherited configuration effects, and the fact that manual user invocation is not prevented by `task` rules. Review effective runtime permissions; do not claim exhaustive isolation from a few probes.

### Issue #1 self-configuration exception

Builder must create and test `.opencode/agents/builder.md` and the other scoped governance/permission artifacts to complete this issue. Explicitly permit these exact implementation paths for issue #1 only, after verified RED and under this specification. Builder may implement the specified policy, but may not broaden its active session permissions, override a denial, change global configuration, or alter the specification to authorize itself. Orchestrator reviews configuration changes and launches/restarts contexts; independent Tester validates the result. Document that static path allowances cannot themselves check GitHub issue state. Reuse in future issues requires explicit scope review; this exception grants no continuing self-authorization.

## Skill requirements

Each of the six local skills has a nonempty, narrowly applicable description, matching lowercase hyphenated directory/name, supported YAML frontmatter, and a substantive workflow with expected evidence or handoff. Skills are loaded only when relevant, never all at bootstrap by default.

| Skill | Required guidance |
| --- | --- |
| `issue-linearity` | Inspect active issue/PR/state; exactly one implementation issue; real issue before branch; legal lifecycle and forbidden transitions; issue-linked scope; rejection returns to the same issue; verified closure then explicit stop |
| `tdd-enforcer` | Spec and acceptance criteria before tests; write/run a failing behavior or governance contract test before implementation content; valid assertion-based RED versus environment failure; minimal GREEN; optional refactor and rerun; concise evidence; no weakened, disabled, or skipped required assertions; applicable unit/integration/E2E coverage and independent Tester |
| `token-efficient-context` | Start with project-state, active issue/spec/plan/test plan and relevant changes; search before reading; small targeted excerpts; concise handoffs/evidence; isolated role contexts; exclude dependencies/build output, full history, unrelated docs and prior conversations unless necessary |
| `playwright-visual-qa` | Running application for user-facing workflows; `390x844`, `768x1024`, `1440x900`; layout, overflow, spacing, alignment, typography, visual hierarchy and coherent mobile design; dialogs, navigation, media sizing, loading, empty, error, success, disabled, hover, active and focus states; destructive controls; keyboard operation, focus management, labels, contrast and accessibility fundamentals; inspect screenshots critically, console, network, API responses, uncaught exceptions, failed resources and redirects; unexpected console errors or application 4xx/5xx fail unless explicitly expected; visual defects can reject automated GREEN; evidence and same-issue rejection loop; explicit governance-only applicability rule |
| `media-pipeline` | Laravel orchestrates asynchronous jobs; heavy ML/FFmpeg stays outside HTTP requests in the worker; FFprobe validation; argument-list subprocess execution without shell interpolation; validate paths/inputs/options, bound execution time/resources, handle exit codes and cancellation; job-isolated temporary paths, ownership, cleanup on success/failure/cancellation, bounded recovery for abandoned files, protect original inputs; retries/idempotent outputs; provider abstractions and deterministic fixtures/fakes; no large model downloads in CI; future implementation guidance only |
| `social-publishing` | Current official platform/OAuth documentation; provider abstraction; authorization and explicit user confirmation of content, accounts and selected platforms before publishing; encrypted server-side tokens, least scopes and refresh-token secrecy; idempotency per destination/content operation; record per-platform state and external identifiers; expose partial success/failure; bounded retries/backoff/rate-limit handling only for eligible failed destinations, preserve successes and prevent duplicates; reconcile ambiguous timeout outcomes before retry; handle revoked access and actionable errors; deterministic provider fakes; no unofficial browser publishing automation |

## External design skill documentation

Upstream sources were fetched during planning on 2026-09-09:

- OpenCode discovery and format: <https://opencode.ai/docs/skills/>.
- Taste installation documentation: <https://github.com/Leonxlnx/taste-skill#installing>.
- Taste source: <https://raw.githubusercontent.com/Leonxlnx/taste-skill/main/skills/taste-skill/SKILL.md>; frontmatter name is `design-taste-frontend`.
- Vercel source: <https://raw.githubusercontent.com/vercel-labs/agent-skills/main/skills/web-design-guidelines/SKILL.md>; frontmatter name is `web-design-guidelines`.
- Guidelines fetched by the Vercel skill: <https://raw.githubusercontent.com/vercel-labs/web-interface-guidelines/main/command.md>.

Document a concrete manual installation procedure: obtain the selected upstream skill at a reviewed revision, preserve required companion resources and attribution/license, install under `~/.config/opencode/skills/<frontmatter-name>/SKILL.md`, check name/directory agreement and duplicate discoveries, restart OpenCode, and verify native skill discovery/loading. Record the reviewed revision when actually installing. Source inspection during planning does not mean installed or runtime-verified. This issue chooses documented installation; keep exactly six project-local skill definitions and do not vendor external skills.

Taste's current upstream default is v2 experimental and describes landing pages, portfolios and redesigns, explicitly excluding dashboards/data tables/multi-step product UI. Document that applicability limit. External guidance cannot authorize Next.js adoption, dependencies, placeholders, scope expansion, or accessibility regressions contrary to project rules. Load applicable design guidance before UI implementation, review with `web-design-guidelines`, then independent Playwright QA. No UI is implemented here.

## Documentation requirements

- `docs/project-state.md` contains only these top-level headings, exactly in order: `Current Architecture`, `Completed Capabilities`, `Important Decisions`, `Known Limitations`, `Current Milestone`, `Next Architectural Goal`. Keep it concise and truthful. The current milestone is M0 governance; the next architectural goal is descriptive and does not authorize another issue.
- PRD summarizes product intent, intended users/workflows and boundaries from existing README, clearly as planned. Architecture documents the existing stack and responsibilities as a target; no invented deployed services, schemas or implementation decisions.
- Roadmap preserves milestone planning without creating additional implementation issues. ADR records this issue's governance-only choice, testing applicability, runtime model strategy and limitations as current decisions, without invented historical approvals.
- README distinguishes actual governance files from planned application structure, gives the real validation command and links to existing documentation. Bootstrap explains role launch, permissions, TDD, restart, external installation and the final stopping point.

## Runtime and model policy

Prefer GPT-6 Astra for Planner. Builder uses an appropriate currently available free OpenCode model, selected at execution time. Discover candidates using installed CLI help and `opencode models`; verify current free availability and perform a bounded minimal actual call. A catalogue entry or a model's self-description does not prove a successful call or the effective model. Capture actual provider/model metadata, result and limitations in evidence. Do not put ephemeral model IDs in durable role definitions or instructions; execution evidence may record IDs actually used.

An omitted subagent `model` inherits its parent. Orchestrator must explicitly arrange and verify runtime model selection rather than assuming inheritance satisfies the free-Builder policy. Prefer CLI free-model Builder execution. If only the harness `general` context is available during bootstrap, Orchestrator may assign isolated, explicitly named Planner/Builder/Tester responsibilities through separate general contexts. Record harness model-selection limitations and any inability to meet the preferred/free model policy; never label an unknown or paid model as verified free. This bootstrap allowance does not permit one context to build and independently approve its own work.

New role files are loaded only after a fresh OpenCode process/restart. Orchestrator must arrange actual configuration discovery and role invocation before Tester approval, using commands supported by 1.18.30. If direct CLI selection of a subagent falls back to a primary, it is not successful subagent verification; invoke through the primary's task path and inspect the actual role/model. Keep session overrides untracked and limited to authorized runtime selection, not permission bypass. Record blocked runtime checks as blocked and return unresolved required checks to Orchestrator.

## Acceptance criteria and verification

The GitHub issue's checklist remains authoritative. The following groups cover it without changing its scope:

- [ ] **AC1 Lifecycle:** issue #1 precedes its canonical branch; exactly one implementation issue remains active; naming, issue-linked commits/PR, legal transitions, TDD and final stop are documented and independently reviewed.
- [ ] **AC2 Roles:** all four agents parse and load; hierarchy, narrow permissions, no-bypass instructions, issue #1 self-configuration exception, Planner scope, Builder lifecycle prohibition, independent Tester and no-repair rule are consistent.
- [ ] **AC3 Skills:** all six local skills parse and cover every requirement above; English-only and context economy rules are documented; both external skills have verified sources and actionable installation instructions.
- [ ] **AC4 Documentation:** all scoped docs/spec files exist and agree; project-state has exactly six headings and is concise; branches, Conventional Commits, applicable unit/E2E/Playwright tests, console/network review and application-free bootstrap are explicit.
- [ ] **AC5 Scope:** no Laravel/React application, database schema/setup, product feature/UI, media processing, social integration, container, deployment or production infrastructure is initialized.
- [ ] **AC6 Validation:** valid RED precedes implementation content, local governance checks pass, relevant real-runtime checks and independent consistency/security review pass, limitations/N/A are accurately recorded, and lightweight CI passes before merge.

Specification, planning and test tooling can precede RED; implementing required governance artifacts or corrections cannot. No application E2E fixture, test server or browser setup is justified for this issue. Required future testing rules are substantive documentation deliverables. See [plan.md](plan.md) and [test-plan.md](test-plan.md) for execution and evidence responsibilities.

### User-directed validation simplification for the same issue

The user questioned disproportionate tests for a governance-only change. Configuration loading, artifact presence and representative permission behavior are testable; the prose is primarily an independent review responsibility. This clarification supersedes earlier demands for exhaustive automated prose, link, path or policy checks while retaining every deliverable and acceptance criterion above.

Aim for roughly 8-12 cohesive checks in one readable test file of about 200-300 lines or less. These are design guidelines, not numeric acceptance gates; do not compress code or split/count tests artificially to meet them. Check required files, frontmatter shape/modes, duplicate YAML keys, skill names, exact project-state headings, representative narrow permissions and basic CI configuration. A small straightforward last-matching-glob helper is sufficient for representative permission decisions. Do not recreate OpenCode's schema, configuration merging, shell parser or security model: its native loader and actual runtime behavior are authoritative.

Use a few meaningful negative inputs through the same helpers used for real files, such as malformed YAML, duplicate keys and all-allow permissions, with valid controls. Documentation consistency, English, scope/changed paths, links, external installation instructions and full skill-topic coverage are independently reviewed, not enforced through sentence matching or an exhaustive duplicate of root governance. No artificial application tests are introduced.

Preserve the recorded valid restored-baseline rework RED and subsequent partial implementation. Simplifying redundant tests and transferring prose assertions to explicit manual review is authorized; dropping important configuration/permission checks to obtain GREEN is not. Run the simplified suite before remaining content is implemented, retain assertion-based RED for missing artifacts, and demonstrate a new focused defect RED before correcting any already-present broken permissions. No further repository rewind is required by this clarification.
