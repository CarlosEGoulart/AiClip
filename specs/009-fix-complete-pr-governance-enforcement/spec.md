# Specification — Issue #9: Fix PR Governance Enforcement

## Title

Fix PR Enforcement to Use All Five Validators and Cross-Validate Branch Issue Against PR Body

## Description

Issue #7 delivered five validator functions but the CI workflow only uses two. This issue completes the integration.

## Problem

PRs can merge without:
- Valid PR body structure
- TDD evidence
- Linkage between branch issue number and PR body
- Tester APPROVE decision
- Evidence file validation

## Goal

Create an orchestration script that reads GitHub event context and invokes all five validators, with integration tests using deterministic fixtures.

## Deliverables

- `tests/governance/pr_enforcement.py` — CLI orchestration script
- `tests/governance/test_pr_enforcement.py` — Integration tests
- `.github/workflows/governance.yml` — Updated to call orchestration script
- `docs/project-state.md` — Updated

## Acceptance Criteria

- [ ] All five validators are invoked in CI
- [ ] Branch issue number matches Closes issue number
- [ ] Evidence file is resolved for current issue
- [ ] Decision: APPROVE required for merge
- [ ] TDD evidence validated
- [ ] Integration tests cover failure scenarios
- [ ] Existing governance tests pass

## Out of Scope

- Application code
- Historical commits
- Validator function changes
