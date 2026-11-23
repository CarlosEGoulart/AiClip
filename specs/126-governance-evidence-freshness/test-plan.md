# Test Plan — Issue #126

## Overview

Unit tests for the deterministic evidence freshness and traceability validator. This is governance/tooling work; no application or UI changes exist in this issue, so Playwright/E2E validation is N/A. Tests exercise missing, stale, contradictory, malformed, and fabricated-looking evidence markers, plus valid evidence.

## Test Scenarios

### E1: Valid evidence (happy path)

**Input:** evidence.md with Decision: APPROVE, Commit: <head SHA>, PR: <evaluated PR URL>, CI lines for all REQUIRED_CHECKS with matching states, Evidence-At timestamp, Merge-State: open.

**Expected:** no errors; validator returns exit 0.

### E2: Missing Decision line

**Input:** evidence with Commit/PR/CI/Evidence-At but no `Decision:` line.

**Expected:** fail closed; error names the missing Decision field.

### E3: Stale commit SHA

**Input:** evidence Commit SHA != branch head SHA being evaluated.

**Expected:** fail closed; error names the commit field and shows expected vs actual.

### E4: Stale PR URL

**Input:** evidence PR URL != PR being evaluated.

**Expected:** fail closed; error names the PR field and shows expected vs actual.

### E5: Missing required CI check

**Input:** evidence omits a CI check from `scripts/merge_gate.py` REQUIRED_CHECKS.

**Expected:** fail closed; error names the missing check.

### E6: CI check state mismatch

**Input:** evidence lists a CI check as `pass` but observed state is fail/pending/cancel.

**Expected:** fail closed; error names the check and the observed state.

### E7: Fabricated CI check marker

**Input:** evidence lists a CI check that was not run at all.

**Expected:** fail closed; error names the fabricated check.

### E8: Multiple conflicting decisions

**Input:** evidence contains both `Decision: APPROVE` and `Decision: REJECT`.

**Expected:** fail closed; error names the conflicting decisions.

### E9: Malformed decision line

**Input:** evidence contains `Decision: MAYBE` or `Decision: ` (empty).

**Expected:** fail closed; error names the malformed decision.

### E10: Empty evidence content

**Input:** evidence.md is empty.

**Expected:** fail closed; error names empty content.

### E11: Missing Commit field

**Input:** evidence has Decision but no `Commit:` line.

**Expected:** fail closed; error names the missing Commit field.

### E12: Missing PR field

**Input:** evidence has Decision but no `PR:` line.

**Expected:** fail closed; error names the missing PR field.

### E13: Missing Evidence-At timestamp

**Input:** evidence has Decision/Commit/PR/CI but no `Evidence-At:` line.

**Expected:** fail closed; error names the missing timestamp field.

### E14: No synthesized approval

**Input:** no `Decision:` line present; automation must not infer APPROVE from passing CI alone.

**Expected:** fail closed; error states no source Decision line found.

### E15: No regression on existing governance tests

**Input:** full `tests/governance/` suite after adding evidence freshness tests.

**Expected:** all existing tests continue to pass; no behavior change to merge gate or validators.

## Acceptance Criteria Coverage

| AC | Covered by |
|----|------------|
| Normative contract documented | Spec (spec.md); validated by E1 valid evidence. |
| Validation ties evidence to exact commit/PR state | E3, E4, E11, E12. |
| Missing/stale evidence fails closed with clear reason | E2, E3, E4, E5, E6, E7, E10, E11, E12, E13. |
| Conflicting/multiple decisions rejected | E8, E9. |
| Tests cover stale commits, missing checks, conflicting decisions, valid evidence | E1, E3, E5, E8. |
| No synthesized/inferred result without source evidence | E14. |
| Merge gate not weakened | E15 (existing merge_gate tests untouched); spec states merge_gate.py not modified. |

## Application E2E / Playwright

**N/A** — No application code or UI changes exist in this issue. Governance/tooling only.

## Test Execution

```bash
PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/governance -p 'test_*.py' -v
python scripts/evidence_freshness.py
```

## Execution Environment Notes

- Python 3.12; PyYAML already present in `tests/governance/requirements.txt`.
- Tests are pure unit tests with deterministic fixtures (no network, no `gh`, no git execution required for unit level).
- CI runs the full governance suite via `.github/workflows/governance.yml`.
