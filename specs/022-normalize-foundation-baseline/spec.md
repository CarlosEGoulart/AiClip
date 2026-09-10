# Issue #22 — Normalize Repository Baseline and Durable Agent Workflow

## Description

Corrective cycle to restore the intended repository baseline after M1 implementation and stabilization. Addresses accumulated inconsistencies that would impair future OpenCode development: milestone-specific README, stale roadmap/PRD, Issue-1-hardcoded agent permissions, conflicting nested AGENTS, duplicate skill trees, and unused Laravel frontend scaffold.

## Acceptance Criteria

- [ ] Root README provides durable product overview with accurate current facts
- [ ] Roadmap reflects M1 in-progress state, not preparation
- [ ] PRD uses neutral recommendation terminology, no stale Pending claims
- [ ] Agent configs support arbitrary future issues without external permission overrides
- [ ] Role separation enforced; no unrestricted permissions introduced
- [ ] apps/api/AGENTS.md supplements root governance without contradiction
- [ ] Pest remains documented backend testing convention
- [ ] Laravel API has no accidental duplicate frontend pipeline
- [ ] Existing health behavior remains green
- [ ] All affected CI remains green
- [ ] Permission regression tests prove role reusability
- [ ] Independent Tester APPROVES

## Out of Scope

- Authentication, registration, login, logout, email verification
- Any product feature implementation
- Sanctum setup beyond what exists
- Design skill installation
- Application UI changes

## Technical Tasks

1. Restore root README durable product structure
2. Fix docs/roadmap.md current state
3. Fix docs/prd.md state and terminology
4. Rewrite .opencode/agents/*.md for durable permissions
5. Add permission regression governance tests
6. Rewrite apps/api/AGENTS.md as scoped addendum
7. Clean up Laravel Boost skill duplicates
8. Remove unused Laravel frontend scaffold from apps/api
9. Strengthen evidence discipline in docs/bootstrap.md
10. Run all tests, verify CI, get Tester approval

## Security Considerations

- Agent permissions must maintain least privilege
- No unrestricted shell access for subagents
- No self-authorization escalation

## Dependencies

- Issue #19 closed (foundation stabilization)
- Issue #21 merged (PR)
