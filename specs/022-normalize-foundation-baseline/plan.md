# Implementation Plan — Issue #22

## Step 1: Root README

Replace the current milestone-specific 91-line README with a durable product overview (~150-200 lines) containing:

- Product identity and vision
- Development status / roadmap summary
- Current vs planned architecture
- Technology stack (Laravel 13, React 19, TypeScript, Vite 8, Vitest, Pest, PostgreSQL 16, Playwright, Docker Compose)
- Repository structure
- Development method (SDD, TDD, GitHub Flow)
- Agent system overview
- Branch naming convention
- Conventional Commits
- Testing policy
- Design skills (documented, not installed)
- Language policy
- Local development instructions
- Documentation index
- Definition of Done

Add a governance regression test asserting the README retains essential sections.

## Step 2: Roadmap

Update docs/roadmap.md:

- M0 — Engineering Governance: completed
- M1 — Application Foundation: in progress
  - Completed: governance bootstrap, documentation formalization, architecture corrections, governance enforcement, SDD bundle enforcement, application foundation, foundation stabilization
  - Next: authentication and user management
- M2+ remain planned only

## Step 3: PRD

Update docs/prd.md:

- Change "M1 — Application Foundation: Pending" to current status
- Replace "potential viral appeal" with "recommendation relevance" / "content suitability"
- Remove MVP scheduling claims if scheduling is not an MVP capability

## Step 4: Agent Permissions

Rewrite all four .opencode/agents/*.md files:

### Planner
- Allow edit: `specs/*/spec.md`, `specs/*/plan.md`, `specs/*/test-plan.md`
- Deny: production code, evidence, lifecycle operations

### Builder
- Allow edit: `apps/**`, `services/**`, `packages/**`, `specs/*/evidence.md`
- Allow bash: test commands (pest, vitest, playwright, lint, build, pint)
- Deny: commit, push, issues, PRs, merge, Planner-owned files, agent config changes

### Tester
- Allow edit: `specs/*/evidence.md`
- Allow bash: test commands, git status/diff, read-only probes
- Deny: production code, tests, Planner files, lifecycle operations

### Orchestrator
- Allow edit: `docs/project-state.md`, `specs/*/evidence.md`
- Allow bash: git lifecycle, gh CLI, governance tests, opencode probes
- Allow task: planner, builder, tester
- Deny: production implementation

Remove all Issue #1-specific paths and self-configuration exceptions.

## Step 5: Permission Regression Tests

Add tests/governance/test_agent_permissions.py:

- Planner supports arbitrary SDD paths
- Builder supports application paths
- Builder cannot edit Planner-owned files
- Tester can update evidence but not production files
- Orchestrator owns lifecycle commands
- No config depends on specs/001-init-opencode-agent-architecture

## Step 6: apps/api/AGENTS.md

Rewrite as Laravel-specific addendum:

- Root ../../AGENTS.md is authority
- Laravel guidance supplements root governance
- Backend tests use Pest unless spec requires otherwise
- Builder/Tester boundaries from root AGENTS.md
- SDD artifacts are permitted project artifacts
- Remove PHPUnit references (project uses Pest)
- Preserve useful Laravel best practices

## Step 7: Skill Hygiene

- Remove apps/api/.claude/skills/ (duplicate of .agents/skills/)
- Update apps/api/boost.json to remove tailwindcss-development (unused in API context)
- Keep deploying-to-cloud, infer-conventions, laravel-best-practices, testing-best-practices

## Step 8: Laravel Frontend Scaffold Removal

Remove from apps/api/:

- package.json (Vite/Tailwind dependencies)
- vite.config.js (Laravel Vite plugin config)
- resources/js/app.js
- resources/css/app.css
- resources/views/welcome.blade.php
- .npmrc

Verify: Laravel 13 API does not require Node/npm for serving. Sanctum SPA auth does not require Vite frontend assets in the API directory (the SPA frontend is apps/web).

## Step 9: Evidence Discipline

Add guidance to docs/bootstrap.md:

- Evidence contains concise audit artifacts, not execution transcripts
- Include: RED cause/result, GREEN result, REFACTOR verification, commands, test counts, Tester decision, limitations
- Do not include: large raw logs, temporary script source, long /tmp narratives, tool transcripts

## Step 10: Verification

Run: governance tests, frontend tests/lint/build, backend tests, E2E if affected.
