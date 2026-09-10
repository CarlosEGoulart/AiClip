# Specification — Issue #11: Fix Governance Merge Gate Semantics

## Title

Fix Governance Merge Gate with APPROVE-Only, Deterministic Evidence, and TDD N/A Reason

## Description

The current PR governance enforcement has four confirmed defects that allow invalid states to pass the merge gate. This issue fixes them with strict TDD.

## Confirmed Defects

1. **REJECT accepted**: `validate_evidence_decision()` returns empty list for REJECT
2. **Bare TDD N/A accepted**: `validate_tdd_sections()` matches "TDD: N/A" without reason
3. **Non-deterministic evidence**: `find_evidence_file()` uses arbitrary iteration order
4. **Happy-path test filters errors**: Integration test discards evidence errors

## Deliverables

- `tests/governance/validators.py` — Add `validate_merge_approval()`, update `validate_tdd_sections()`
- `tests/governance/pr_enforcement.py` — Use merge approval, fix evidence resolution
- `tests/governance/test_enforcement.py` — RED tests proving defects
- `tests/governance/test_pr_enforcement.py` — Fix integration tests
- `docs/project-state.md` — Updated

## Acceptance Criteria

- [ ] REJECT fails merge gate
- [ ] APPROVE passes merge gate
- [ ] Bare TDD N/A fails
- [ ] Justified TDD N/A passes
- [ ] Multiple evidence directories fail
- [ ] Integration tests use real evidence
- [ ] All governance tests pass

## Out of Scope

- Application code
- Historical commits
