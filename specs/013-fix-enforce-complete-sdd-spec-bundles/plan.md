# Implementation Plan: Issue #13

## Issue

#13 - fix(governance): enforce complete SDD specification bundles

## Overview

Add generic SDD bundle validation, fix integration test isolation, and repair historical metadata.

## Phase 1: RED — Prove Current Weakness

1. Add `TestSddBundleValidator` to `test_enforcement.py`
2. Add `validate_sdd_bundle()` stub to `validators.py` that always returns empty
3. Run tests — new tests FAIL proving incomplete bundles currently pass
4. Document RED evidence

## Phase 2: GREEN — Implement Fixes

1. Implement `validate_sdd_bundle()` in `validators.py`:
   - Accept `specs_dir` parameter
   - Scan all `specs/<issue>-*` directories
   - Require spec.md, plan.md, test-plan.md, evidence.md
   - Each must be regular file and non-empty
2. Add `specs_dir` parameter to `find_evidence_file()` in `pr_enforcement.py`
3. Run tests — all pass

## Phase 3: REFACTOR — Improve Test Isolation

1. Refactor `test_pr_enforcement.py` integration tests:
   - Remove `_create_temp_specs` / `_restore_specs` / `shutil.move`
   - Use `tempfile.TemporaryDirectory()` fixtures
   - Pass temporary specs directory to `find_evidence_file()`
2. Run tests — all remain green

## Phase 4: Historical Repair

1. Reconstruct `plan.md` for `specs/009-fix-complete-pr-governance-enforcement/`
2. Reconstruct `test-plan.md` for `specs/009-fix-complete-pr-governance-enforcement/`
3. Reconstruct `plan.md` for `specs/011-fix-finalize-governance-merge-gate/`
4. Reconstruct `test-plan.md` for `specs/011-fix-finalize-governance-merge-gate/`
5. Each file contains retrospective recovery note

## Phase 5: Evidence and State

1. Write `evidence.md` with TDD sections
2. Update `docs/project-state.md`

## Commit Strategy

Single commit: `fix(governance): enforce complete SDD specification bundles`
