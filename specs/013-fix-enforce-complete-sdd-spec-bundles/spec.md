# Specification: Enforce Complete SDD Specification Bundles

## Issue

#13 - fix(governance): enforce complete SDD specification bundles

## Overview

Enforce that every governed issue directory under `specs/` contains exactly the four required SDD artifacts: `spec.md`, `plan.md`, `test-plan.md`, and `evidence.md`. Repair the historical metadata gap in Issues #9 and #11.

## Current Weakness

- `test_g1_required_artifacts_exist_nonempty` only validates hardcoded Issue #1 paths
- No generic validator scans all `specs/<issue>-*` directories
- Issues #9 and #11 have only `spec.md` + `evidence.md` (missing `plan.md` and `test-plan.md`)
- Integration tests in `test_pr_enforcement.py` mutate the real `specs/` directory via `shutil.move`

## Required Changes

### validators.py

Add `validate_sdd_bundle(specs_dir: Path) -> list[str]`:
- Scans all `specs/<issue>-*` directories
- Requires each to contain: `spec.md`, `plan.md`, `test-plan.md`, `evidence.md`
- Each required file must be a regular file and non-empty
- Returns empty list for valid bundles, list of error strings for invalid

### test_enforcement.py

Add `TestSddBundleValidator`:
- `test_complete_bundle_passes` - four valid files → PASS
- `test_missing_plan_fails` - no plan.md → FAIL
- `test_missing_test_plan_fails` - no test-plan.md → FAIL
- `test_missing_evidence_fails` - no evidence.md → FAIL
- `test_missing_spec_fails` - no spec.md → FAIL
- `test_empty_required_file_fails` - empty plan.md → FAIL
- `test_multiple_valid_directories_pass` - two valid directories → PASS

All tests must use `tempfile.TemporaryDirectory()` for isolated fixtures.

### test_pr_enforcement.py

Refactor `_create_temp_specs` to use dependency injection:
- Add optional `specs_dir` parameter to `find_evidence_file()`
- Tests pass temporary directories instead of mutating real `specs/`
- Remove `shutil.move` / backup logic from integration tests

### Historical Repair

Add `plan.md` and `test-plan.md` to:
- `specs/009-fix-complete-pr-governance-enforcement/`
- `specs/011-fix-finalize-governance-merge-gate/`

Each must contain a retrospective recovery note.

### test_governance.py

Update `test_g1_required_artifacts_exist_nonempty` to also verify:
- `specs/009-*` contains all four files
- `specs/011-*` contains all four files

## Acceptance Criteria

- [ ] Generic SDD bundle validation exists in validators.py
- [ ] Every issue bundle requires spec.md, plan.md, test-plan.md, evidence.md
- [ ] Empty required artifacts fail validation
- [ ] Issues #9 and #11 have complete bundles with retrospective markers
- [ ] Tests use temporary directories without mutating real specs/
- [ ] All existing governance tests remain green

## Out of Scope

- Application scaffolding (Laravel, React, PostgreSQL)
- API endpoints or authentication
- Media processing or social publishing

## Test Scenarios

- Complete four-file bundle → PASS
- Missing plan.md → FAIL
- Missing test-plan.md → FAIL
- Missing evidence.md → FAIL
- Missing spec.md → FAIL
- Empty required file → FAIL
- Multiple valid directories → PASS

## Dependencies

- Issue #11 (merge gate finalization)
