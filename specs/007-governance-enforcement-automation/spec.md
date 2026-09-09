# Specification — Issue #7: Governance Enforcement Automation

## Title

Automate Governance Enforcement for Commits, Branches, PR Linkage, and Evidence

## Description

PR #6 (for issue #5) exposed five governance enforcement gaps that currently rely entirely on manual review. No CI check validates commit format, branch naming, PR-to-issue linkage, Tester decision records, or TDD evidence structure. This issue implements a small, independently testable Python validation layer that catches all five gaps at CI time.

Historical commits and evidence files that violated these rules must NOT be rewritten. They serve as documented evidence of the enforcement gaps.

## Problem

Without automated enforcement, governance rules are suggestions that depend on human memory. The repository has already merged commits with invalid format and evidence files without explicit Tester decisions. Future contributors will make similar mistakes without automated gates.

## Goal

Implement a small Python validation layer that:
1. Validates commit message format (Conventional Commits with mandatory scope)
2. Validates branch naming convention
3. Validates PR-to-issue linkage
4. Validates Tester approval evidence
5. Validates TDD evidence structure

The validators must be:
- Pure functions with no side effects
- Independently testable outside CI
- Integrated with existing governance tests
- Extended into CI via GitHub Actions

## Deliverables

### New Files

- `tests/governance/validators.py` — Five validator functions
- `tests/governance/test_enforcement.py` — At least 25 test methods
- `specs/007-governance-enforcement-automation/spec.md`
- `specs/007-governance-enforcement-automation/plan.md`
- `specs/007-governance-enforcement-automation/test-plan.md`
- `specs/007-governance-enforcement-automation/evidence.md`

### Modified Files

- `.github/workflows/governance.yml` — Extended with PR metadata validation
- `docs/project-state.md` — Updated with current state

## Acceptance Criteria

- [ ] Five validator functions exist in `tests/governance/validators.py`
- [ ] Each validator returns `[]` for valid input and `["error..."]` for invalid input
- [ ] At least 25 test methods cover all validators with valid, invalid, and edge-case inputs
- [ ] All governance tests pass
- [ ] Existing `test_governance.py` tests are not broken
- [ ] Evidence file contains `Decision: APPROVE`
- [ ] Evidence file contains structured TDD sections
- [ ] Branch follows naming convention
- [ ] All commits follow `<type>(<scope>): <subject>` format
- [ ] No new external dependencies beyond PyYAML

## Out of Scope

- Rewriting historical commits or PRs
- External policy engines or paid services
- Complex GitHub Actions shell logic
- Automatic merge blocking (CI failure achieves this)
- Application-layer validation

## Dependencies

- PyYAML 6.0.3 (already present)
- Existing `tests/governance/test_governance.py` (must not break)
- Existing `.github/workflows/governance.yml` (will be extended)
