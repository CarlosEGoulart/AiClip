# Test Plan — Issue #127

## Overview

Unit tests for the deterministic spec-plan quality validator (`scripts/spec_plan_quality.py`). This is governance/tooling work; no application or UI changes exist in this issue, so Playwright/E2E validation is N/A. Tests exercise compliant, incomplete, and malformed spec bundles using deterministic fixtures. No network, no `gh`, no git execution required for unit level.

## Test Scenarios

### Q1: Valid compliant bundle (happy path)

**Input:** Fixture bundle `fixtures/valid/{spec.md, plan.md, test-plan.md}` with all required files, all required sections, acceptance criteria in `- [ ]` checklist format, objective criteria only, no placeholders, no empty sections, no contradictions.

**Expected:** No errors; validator returns exit 0 and reports contract version `spec-plan-quality v1.0`.

### Q2: Missing spec.md

**Input:** Issue directory with plan.md and test-plan.md present, spec.md absent.

**Expected:** Fail closed; error names the missing file `spec.md`.

### Q3: Missing plan.md

**Input:** Issue directory with spec.md and test-plan.md present, plan.md absent.

**Expected:** Fail closed; error names the missing file `plan.md`.

### Q4: Missing test-plan.md

**Input:** Issue directory with spec.md and plan.md present, test-plan.md absent.

**Expected:** Fail closed; error names the missing file `test-plan.md`.

### Q5: Empty spec.md file

**Input:** spec.md exists but is empty (0 bytes).

**Expected:** Fail closed; error names the file and "empty".

### Q6: spec.md missing required section

**Input:** Fixture `fixtures/invalid/missing-sections/spec.md` missing the `Acceptance Criteria` section.

**Expected:** Fail closed; error names the file `spec.md` and the missing section `Acceptance Criteria`.

### Q7: plan.md missing required section

**Input:** plan.md missing the `Commits` section.

**Expected:** Fail closed; error names the file `plan.md` and the missing section `Commits`.

### Q8: test-plan.md missing required section

**Input:** test-plan.md missing the `Test Scenarios` section.

**Expected:** Fail closed; error names the file `test-plan.md` and the missing section `Test Scenarios`.

### Q9: Placeholder content (TODO/FIXME/TBD)

**Input:** Fixture `fixtures/invalid/placeholder/spec.md` contains a `TODO:` token in a section body.

**Expected:** Fail closed; error names the file, section, and line number of the placeholder.

### Q10: Empty required section

**Input:** Fixture `fixtures/invalid/empty-section/plan.md` has a `Verification` heading with no substantive body.

**Expected:** Fail closed; error names the file and the empty section.

### Q11: Acceptance criteria not in checklist format

**Input:** Acceptance criteria written as plain sentences without `- [ ]` prefix.

**Expected:** Fail closed; error names the file and the non-compliant criterion.

### Q12: Acceptance criteria with untestable subjective adjectives

**Input:** Fixture `fixtures/invalid/subjective-criteria/test-plan.md` contains criteria with words like "good", "fast", "intuitive" without an objective threshold.

**Expected:** Fail closed; error names the file and the criterion with the subjective adjective.

### Q13: Contradictory acceptance criteria

**Input:** Fixture `fixtures/invalid/contradictory/spec.md` contains criteria "must X" and "must not X" on the same subject.

**Expected:** Fail closed; error names the file and the contradictory criteria (best-effort lexical detection).

### Q14: Valid existing spec bundles pass (no false positives)

**Input:** Existing compliant bundles (e.g., specs/125, specs/126 structure) run through the validator.

**Expected:** Pass; no false positives on bundles that satisfy the contract.

### Q15: Validator is read-only

**Input:** Run validator against a copy of a fixture bundle.

**Expected:** No spec file is modified (file contents and mtimes unchanged); `git diff --exit-code` clean.

### Q16: No secrets read or printed

**Input:** Validator run against fixtures containing no secrets.

**Expected:** Diagnostics reference only file/section/line; no `.env`, token, or credential content read or printed.

### Q17: Exit code behavior

**Input:** Compliant bundle vs. any violating bundle.

**Expected:** Exit 0 on all-pass; non-zero on any violation; each violation printed with remediation.

### Q18: Contract version reported

**Input:** Any validator run.

**Expected:** Output includes `spec-plan-quality v1.0`.

### Q19: No regression on existing governance tests

**Input:** Full `tests/governance/` suite after adding spec-plan quality tests.

**Expected:** All existing tests continue to pass; no behavior change to merge gate, validators, preflight, or evidence freshness.

## Acceptance Criteria Coverage

| AC | Covered by |
|----|------------|
| Quality contract explicit, versioned, documented | Spec (spec.md); validated by Q1, Q18. |
| Required planning files checked deterministically | Q2, Q3, Q4, Q5. |
| Required sections checked deterministically | Q6, Q7, Q8. |
| Placeholder content fails with actionable diagnostics | Q9. |
| Empty required sections fail with actionable diagnostics | Q10. |
| Acceptance criteria not in `- [ ]` format fail | Q11. |
| Non-objective subjective criteria fail | Q12. |
| Contradictory requirements fail | Q13. |
| Valid existing specs pass / migration separately scoped | Q14; spec documents legacy exceptions. |
| Unit/fixture tests cover compliant, incomplete, malformed | Q1–Q13. |
| Validator does not rewrite specs / no subjective decisions | Q15. |
| Integrates into CI without duplicating unrelated policy | Plan Phase 4; spec Out of Scope. |
| Fixtures for valid and invalid examples | Q1–Q13 use fixtures. |
| Remediation workflow documented | Spec (diagnostics name file/section/line + remediation). |
| Agent permissions not weakened | Spec Constraints; Q19. |

## Application E2E / Playwright

**N/A** — No application code or UI changes exist in this issue. Governance/tooling only.

## Test Execution

```bash
PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/governance -p 'test_*.py' -v
python scripts/spec_plan_quality.py <specs_dir> <issue_number>
```

## Execution Environment Notes

- Python 3.12; PyYAML already present in `tests/governance/requirements.txt`.
- Tests are pure unit tests with deterministic fixtures (no network, no `gh`, no git execution required for unit level).
- CI runs the full governance suite via `.github/workflows/governance.yml`.
- Fixtures live under `specs/127-governance-spec-plan-quality/fixtures/` and are read-only test data.

## Document Reason

This issue adds governance spec-plan quality infrastructure; no application behavior exists to test with E2E or Playwright.
