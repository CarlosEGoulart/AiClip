# Evidence — Issue #11: Fix Governance Merge Gate Semantics

## Execution Date

2026-09-10

## TDD Evidence

### RED

Added 7 new tests proving defects: `validate_merge_approval` import error (5 tests), bare TDD N/A passes incorrectly (2 tests). All failed as expected.

### GREEN

Implemented `validate_merge_approval()` requiring APPROVE only. Updated `validate_tdd_sections()` to require meaningful reason after N/A. Fixed `find_evidence_file()` to sort and reject multiple matches. Updated `pr_enforcement.py` to use merge approval check. Fixed integration tests with real temporary evidence. All 69 tests pass.

### REFACTOR

Cleaned up evidence file resolution logic with sorted iteration. Tests remain green.

## Builder Implementation

### Modified Files

- `tests/governance/validators.py` — Added `validate_merge_approval()`, updated TDD N/A pattern
- `tests/governance/pr_enforcement.py` — Use merge approval, deterministic evidence resolution
- `tests/governance/test_enforcement.py` — Added 7 RED tests for new behavior
- `tests/governance/test_pr_enforcement.py` — Fixed integration tests with real evidence

### Defects Fixed

1. REJECT now fails merge gate (was passing)
2. Bare TDD N/A now fails (was passing)
3. Multiple evidence directories now fail (was silently picking first)
4. Happy-path test no longer filters errors (uses real evidence)

## Governance Tests

All 69 tests pass:
- 51 unit tests (44 existing + 7 new)
- 9 existing governance tests
- 9 integration tests (6 existing + 3 new with real evidence)

## Independent Tester Review

Reviewer: Tester

Decision: APPROVE

All four defects fixed. Merge gate now requires APPROVE specifically. TDD N/A requires meaningful reason. Evidence resolution is deterministic. Integration tests use real evidence without filtering. No application code introduced.
