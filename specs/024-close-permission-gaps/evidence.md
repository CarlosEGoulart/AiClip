# Evidence: Close Remaining Permission and Baseline Gaps

## Implementation Status

- Builder permissions fixed (governance tests denied, control-plane denied, .env protected)
- Planner permissions updated (.env protected)
- Tester permissions updated (.env protected, bash patterns fixed)
- Orchestrator permissions updated (.env protected)
- Permission regression tests rewritten with parsed frontmatter and decide() helper
- composer.json setup script cleaned (no npm)
- Backend CI cleaned (no Node.js step)
- apps/api/AGENTS.md skill reference fixed
- PRD scheduling language replaced
- Roadmap M0/M1 separation done
- README lifecycle ending fixed

## TDD Evidence

### RED
- Updated test assertions to reflect new Builder governance boundaries
- Existing g3/g4 tests expected Builder to edit governance tests (now denied)
- New permission tests verify deny/allow decisions via parsed frontmatter

### GREEN
- All 135 governance tests pass
- Backend 8/8 tests pass
- Frontend 6/6 tests pass
- Lint clean
- Build clean

### REFACTOR
- Permission tests rewritten with parsed frontmatter and decide() helper
- Bash patterns simplified (removed trailing space requirement)

## Tests

- Governance tests: 135/135 passed
- Backend tests: 8/8 passed
- Frontend tests: 6/6 passed
- Lint: clean
- Build: clean

## Runtime Verification

OpenCode debug probes confirm:
- All four agents deny .env, allow .env.example
- Builder denies tests/governance/**
- Builder denies control-plane files under apps/
- Builder allows application code and tests
- Orchestrator allows lifecycle commands
- Planner allows spec files only
- Tester allows evidence only

## Decision

Decision: APPROVE
