# Test Plan — Issue #22

## Governance Tests

1. **README structure regression**: Assert root README contains required durable sections (Product Vision, Technology Stack, Repository Structure, Development Method, Agent System, Testing Policy, Local Development, Definition of Done)
2. **Permission regression**: Prove Planner/Builder/Tester/Orchestrator configs support arbitrary future issues
3. **No Issue-1 dependency**: Assert no agent config references specs/001-init-opencode-agent-architecture

## Backend Tests

4. **Health endpoint**: php artisan test --compact (existing 8 tests pass)
5. **No Node requirement**: Verify apps/api can serve without npm/node installed

## Frontend Tests

6. **Vitest**: npm test (existing 6 tests pass)
7. **Lint**: npm run lint passes
8. **Build**: npm run build succeeds

## E2E Tests

9. **Playwright**: npm run test:e2e (18 tests pass, health behavior unchanged)

## Documentation Tests

10. **Roadmap accuracy**: No stale "preparation" or "Pending" claims
11. **PRD terminology**: Neutral recommendation language, no "viral appeal"
12. **AGENTS.md consistency**: apps/api/AGENTS.md does not contradict root AGENTS.md

## Agent Discovery Probes

13. **Planner**: Can edit arbitrary specs/*/spec.md paths
14. **Builder**: Can edit apps/** paths, cannot edit specs/*/spec.md
15. **Tester**: Can edit specs/*/evidence.md, cannot edit apps/** production files
16. **Orchestrator**: Has lifecycle commands, cannot edit production code
