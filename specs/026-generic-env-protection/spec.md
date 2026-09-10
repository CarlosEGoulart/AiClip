# Specification: Generic .env.* Protection Across All Agents

## Issue

GitHub Issue #26: fix(foundation): generic .env.* protection across all agents

## Goal

Replace fixed-list environment file protection with generic wildcard patterns that deny arbitrary .env.* variants while explicitly allowing .env.example.

## Technical Tasks

- [ ] Update read rules in all four agents with generic wildcard patterns
- [ ] Update permission regression tests for all variants
- [ ] Verify via governance tests and OpenCode debug probes

## Acceptance Criteria

- [ ] .env → DENY
- [ ] .env.testing → DENY
- [ ] .env.development → DENY
- [ ] .env.backup → DENY
- [ ] .env.any-future-name → DENY
- [ ] .env.example → ALLOW
- [ ] Normal source files → ALLOW
- [ ] Governance tests pass
- [ ] Tester APPROVES

## Out of Scope

- Application behavior changes
- Authentication
- CI changes
- Documentation changes
