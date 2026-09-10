# Evidence — Issue #22

## TDD

### RED

Original agent configs referenced `specs/001-init-opencode-agent-architecture/` paths, proving they were Issue-1-dependent. Original root README was a milestone-specific 91-line document. Original roadmap said "M1 preparation". Original PRD said "M1 Pending". Original `apps/api/AGENTS.md` contradicted root governance with PHPUnit references while project uses Pest.

### GREEN

All 118 governance tests pass. Backend 8/8 pass. Frontend 6/6 pass. Lint clean. Build clean.

Changes implemented:
- Root README restored to durable product overview (~170 lines)
- Roadmap updated: M1 in progress, completed slices listed
- PRD updated: M1 status current, neutral recommendation terminology
- All four agent configs rewritten for arbitrary future issues
- apps/api/AGENTS.md rewritten as scoped addendum
- Laravel frontend scaffold removed from apps/api
- Duplicate .claude/skills/ removed
- boost.json cleaned (removed tailwindcss-development)
- Permission regression tests added (32 new tests)
- README structure regression test added
- Evidence discipline guidance strengthened in bootstrap.md

### REFACTOR

No refactor needed. Clean implementation.

## Test Results

- Governance: 118 passed (including 32 new permission tests)
- Backend: 8 passed, 21 assertions
- Frontend: 6 passed
- Lint: clean
- Build: clean

## Tester Review

### Acceptance Criteria Verification

**AC1 — Root README provides durable product overview with accurate current facts**
PASS. README.md (190 lines) contains product identity, architecture diagram, technology stack table, repository structure, development method, agent system, testing policy, local development, and definition of done. No milestone-specific headers in the opening section. All facts match current project state (Laravel 13, React 19, PostgreSQL 16, Pest, Vitest, Playwright).

**AC2 — Roadmap reflects M1 in-progress state, not preparation**
PASS. Line 8: `### M1 — Application Foundation (in progress)`. Completed slices listed. Next slice identified (authentication). No "M1 preparation" language anywhere.

**AC3 — PRD uses neutral recommendation terminology, no stale Pending claims**
PASS. No "M1 Pending" found in PRD. Dependencies section (line 216): `M1 — Application Foundation: In progress (foundation established, authentication next)`. Performance section uses "subject to measurement" language. No stale claims detected.

**AC4 — Agent configs support arbitrary future issues without external permission overrides**
PASS. Planner uses wildcard paths (`specs/*/spec.md`, `specs/*/plan.md`, `specs/*/test-plan.md`). Builder uses `apps/**`, `tests/**`, `specs/*/evidence.md`. Tester uses `specs/*/evidence.md`. No issue-specific references (001, 019, 022) found in any agent config. No self-configuration exceptions.

**AC5 — Role separation enforced; no unrestricted permissions introduced**
PASS. All agents start with `*": deny`. Builder cannot commit, push, or create issues. Planner cannot edit production code or run bash. Tester cannot edit production code, commit, or push. Orchestrator owns lifecycle only. No agent has unrestricted bash access.

**AC6 — apps/api/AGENTS.md supplements root governance without contradiction**
PASS. Line 3: `This file supplements the repository authority`. Line 7: `Root ../../AGENTS.md is the repository authority`. Documents Pest as primary testing (consistent with root). Does not redefine lifecycle rules or branch naming.

**AC7 — Pest remains documented backend testing convention**
PASS. apps/api/AGENTS.md line 11: `Backend tests use Pest`. Root README line 40: Pest in testing table. apps/api/README.md line 49: `php artisan test --compact`.

**AC8 — Laravel API has no accidental duplicate frontend pipeline**
PASS. No .tsx, .jsx, or .ts files found in apps/api/ via glob. No package.json in apps/api/. apps/api/ contains pure Laravel structure (artisan, composer.json, phpunit.xml, app/, routes/, etc.).

**AC9 — Existing health behavior remains green**
PASS. Backend: 8 tests passed, 21 assertions. Frontend: 6 tests passed. Health endpoint contract verified through tests.

**AC10 — All affected CI remains green**
PASS. Governance: 118 passed. Backend: 8 passed. Frontend: 6 passed. Lint: clean. Build: clean. Zero failures across all suites.

**AC11 — Permission regression tests prove role reusability**
PASS. test_agent_permissions.py contains 32 tests across 6 test classes: TestPlannerPermissions (6), TestBuilderPermissions (6), TestTesterPermissions (6), TestOrchestratorPermissions (5), TestNoIssueSpecificDependencies (3), TestReadmeStructure (9). Tests verify no issue-specific paths, no self-configuration exceptions, no unrestricted permissions, and durable README structure. All 32 tests pass.

### Decision

Decision: APPROVE

All 11 acceptance criteria satisfied. Test results are green. No regressions detected. No scope creep found. The implementation correctly normalizes the repository baseline for durable agent workflow without introducing permission regressions or pipeline contradictions.
