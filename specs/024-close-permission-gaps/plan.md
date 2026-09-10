# Implementation Plan: Close Remaining Permission and Baseline Gaps

## Approach

1. Fix agent permission files (Builder, Planner, Tester, Orchestrator)
2. Rewrite permission regression tests with parsed frontmatter
3. Fix composer.json setup script
4. Fix backend CI workflow
5. Fix apps/api/AGENTS.md skill reference
6. Fix PRD scheduling language
7. Fix roadmap M0/M1 separation
8. Fix README lifecycle ending
9. Create SDD bundle for Issue #24
10. Run all tests

## File Changes

### Agent Permissions
- `.opencode/agents/builder.md`: Remove tests/**, add control-plane DENY, add .env protection
- `.opencode/agents/planner.md`: Add .env protection
- `.opencode/agents/tester.md`: Add .env protection, fix bash patterns
- `.opencode/agents/orchestrator.md`: Add .env protection

### Permission Tests
- `tests/governance/test_agent_permissions.py`: Full rewrite with parsed frontmatter/decide()
- `tests/governance/test_governance.py`: Update g3/g4 for new Builder boundaries

### Configuration
- `apps/api/composer.json`: Remove npm install/build from setup script
- `.github/workflows/backend.yml`: Remove Setup Node.js step
- `apps/api/AGENTS.md`: Remove .claude/skills/ reference

### Documentation
- `docs/prd.md`: Replace scheduling language
- `docs/roadmap.md`: Separate M0 governance from M1
- `README.md`: Fix lifecycle ending

### SDD Bundle
- `specs/024-close-permission-gaps/spec.md`
- `specs/024-close-permission-gaps/plan.md`
- `specs/024-close-permission-gaps/test-plan.md`
- `specs/024-close-permission-gaps/evidence.md`
