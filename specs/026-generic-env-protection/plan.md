# Implementation Plan: Generic .env.* Protection

## Approach

Replace specific .env variant deny rules with generic wildcard patterns using OpenCode last-match-wins semantics.

## File Changes

### Agent Permissions
- `.opencode/agents/orchestrator.md`: Replace fixed .env.* list with generic patterns
- `.opencode/agents/planner.md`: Replace fixed .env.* list with generic patterns
- `.opencode/agents/builder.md`: Replace fixed .env.* list with generic patterns
- `.opencode/agents/tester.md`: Replace fixed .env.* list with generic patterns

### Permission Tests
- `tests/governance/test_agent_permissions.py`: Add tests for .env.testing, .env.development, .env.backup, arbitrary variants

### SDD Bundle
- `specs/026-generic-env-protection/spec.md`
- `specs/026-generic-env-protection/plan.md`
- `specs/026-generic-env-protection/test-plan.md`
- `specs/026-generic-env-protection/evidence.md`
