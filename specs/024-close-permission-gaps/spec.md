# Specification: Close Remaining Permission and Baseline Gaps

## Issue

GitHub Issue #24: fix(foundation): close remaining permission and baseline gaps

## Goal

Narrow corrective foundation cycle after Issue #22. Fix concrete defects in Builder permissions, environment secret protection, composer.json setup, backend CI, stale references, PRD scheduling, roadmap grouping, and README lifecycle.

## Technical Tasks

- [ ] Fix Builder governance-test permission (remove tests/**)
- [ ] Protect nested control-plane files under apps/api
- [ ] Protect environment secrets (.env deny, .env.example allow)
- [ ] Strengthen permission regression tests with parsed frontmatter
- [ ] Fix Laravel API composer.json setup script
- [ ] Remove Node step from backend CI
- [ ] Fix apps/api/AGENTS.md skill reference
- [ ] PRD scheduling cleanup
- [ ] Clarify roadmap M0 vs M1 grouping
- [ ] Fix README lifecycle ending

## Acceptance Criteria

- [ ] Builder cannot edit tests/governance/**
- [ ] Builder can edit application tests
- [ ] Builder cannot edit nested control-plane files under apps/
- [ ] All agents deny .env, allow .env.example
- [ ] Permission tests use parsed rule decisions
- [ ] composer setup has no npm steps
- [ ] Backend CI has no Node step
- [ ] apps/api/AGENTS.md references no .claude/skills/
- [ ] PRD has no scheduling claims
- [ ] Roadmap separates M0 governance from M1 implementation
- [ ] README DoD says explicit authorization
- [ ] All existing tests green
- [ ] Tester APPROVES

## Out of Scope

- Authentication, registration, login
- Product features
- Health endpoint changes
- Design skill installation
