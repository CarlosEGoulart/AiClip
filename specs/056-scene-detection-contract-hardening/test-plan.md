# Test Plan: Scene Detection Contract Hardening

## Overview

This test plan covers all verification steps for the 12 blockers in Issue #56. Each test is designed to be written RED-first (fail before implementation) per TDD requirements.

## Test Categories

### 1. Worker Contract Validation Tests (Phase 1)

**File**: `services/worker/tests/test_contract_detect_scenes.py` (extend existing)

#### Test Cases

| Test ID | Description | Expected |
|---------|-------------|----------|
| TC-W-01 | `detect_scenes` + valid positive integer duration | VALID |
| TC-W-02 | `detect_scenes` + missing `media` | INVALID |
| TC-W-03 | `detect_scenes` + `media: {}` | INVALID |
| TC-W-04 | `detect_scenes` + missing `duration_ms` | INVALID |
| TC-W-05 | `detect_scenes` + `duration_ms: 0` | INVALID |
| TC-W-06 | `detect_scenes` + `duration_ms: -1` | INVALID |
| TC-W-07 | `detect_scenes` + non-integer duration (float/string) | INVALID |
| TC-W-08 | `probe` without `media` | VALID |
| TC-W-09 | `extract_audio` without `media` | VALID |
| TC-W-10 | `transcribe` without `media` | VALID |

#### RED Verification

Run tests before schema change — all should fail (INVALID cases pass incorrectly, VALID cases may fail).

#### GREEN Verification

Run tests after schema change — all should pass.

### 2. Laravel Duration Validation Tests (Phase 2)

**File**: `apps/api/tests/Feature/Models/MediaSceneAnalysisTest.php` (extend existing)

#### Test Cases

| Test ID | Description | Expected |
|---------|-------------|----------|
| TC-L-01 | `validateScenes([], 0)` throws `InvalidArgumentException` | Throw |
| TC-L-02 | `validateScenes([], -1)` throws `InvalidArgumentException` | Throw |
| TC-L-03 | `validateScenes([], 1)` succeeds | No exception |
| TC-L-04 | `markCompleted(..., [], 0)` throws `InvalidArgumentException` | Throw |
| TC-L-05 | Failed completion does not persist `COMPLETED` status | Status remains `DETECTING` or `FAILED` |
| TC-L-06 | `markCompleted(..., [], positiveDuration)` succeeds when lifecycle valid | Status becomes `COMPLETED` |

#### RED Verification

Run tests before code change — TC-L-01, TC-L-02, TC-L-04, TC-L-05 should fail (currently pass incorrectly).

#### GREEN Verification

Run tests after code change — all should pass.

### 3. Risky Test Fixes (Phase 3)

**File**: `apps/api/tests/Feature/Models/MediaSceneAnalysisTest.php`

#### Current Risky Tests (to be fixed)

| Test ID | Current Issue | Fix |
|---------|---------------|-----|
| RT-01 | `test_validate_scenes_accepts_single_scene_at_zero_duration` asserts `$this->assertTrue(true)` after `validateScenes([], 0)` | Replace with `test_validate_scenes_rejects_zero_duration_with_empty_scenes` expecting exception |
| RT-02 | `test_validate_scenes_accepts_exact_duration_boundary` — no assertion on persisted state | Add assertion: `$this->assertEquals($expectedScenes, $sceneAnalysis->fresh()->scenes)` |
| RT-03 | `test_validate_scenes_accepts_scene_at_duration_boundary` — no assertion on persisted state | Add assertion: verify boundary scene end == duration |
| RT-04 | `test_validate_scenes_accepts_valid_sequential_indexes` — no assertion on persisted state | Add assertion: verify sequential indexes persisted unchanged |

#### Verification

Run `php artisan test --compact` — verify 0 risky tests reported.

### 4. PHP Contract Preflight Tests (Phase 4)

**File**: `apps/api/tests/Feature/Services/ProcessMediaActionTest.php` (new or extend)

#### Test Cases

| Test ID | Description | Expected |
|---------|-------------|----------|
| TC-P-01 | `detectScenes()` with `durationMs` null → `ProcessMediaException` | Throw |
| TC-P-02 | `detectScenes()` with `durationMs` 0 → `ProcessMediaException` | Throw |
| TC-P-03 | `detectScenes()` with `durationMs` negative → `ProcessMediaException` | Throw |
| TC-P-04 | `detectScenes()` with valid positive `durationMs` → process created | Mock `createProcess()` called |
| TC-P-05 | `detectScenes()` with invalid contract → `createProcess()` NEVER called | Mock `createProcess()` not called |

#### RED Verification

Run tests before code change — TC-P-01 through TC-P-03 should fail (no exception thrown), TC-P-05 should fail (process created despite invalid contract).

#### GREEN Verification

Run tests after code change — all should pass.

### 5. Missing Dependency Error Test (Phase 5)

**File**: `services/worker/tests/test_pyscenedetect_adapter.py` (new or extend)

#### Test Cases

| Test ID | Description | Expected |
|---------|-------------|----------|
| TC-D-01 | `PySceneDetectAdapter.detect()` with mocked `ImportError` on `scenedetect` import | Raises `ImportError` with message containing `"pip install -e \".[scene_detection]\""` |
| TC-D-02 | Module import succeeds without `scenedetect` installed | No import-time error (lazy import preserved) |

#### RED Verification

Run test before code change — TC-D-01 fails (raw ImportError), TC-D-02 passes.

#### GREEN Verification

Run test after code change — both pass.

### 6. Real PySceneDetect Integration Tests (Phase 7 - Preserve)

**File**: `services/worker/tests/test_pyscenedetect_integration.py` (existing)

#### Test Cases (must remain passing)

| Test ID | Description |
|---------|-------------|
| TC-I-01 | `test_real_scenedetect_on_solid_color_cuts` |
| TC-I-02 | `test_real_scenedetect_detects_expected_boundaries` |
| TC-I-03 | `test_real_scenedetect_single_color_no_scenes` |

#### Verification

Run with `[scene_detection]` extra installed — all 3 must pass. These are mandatory CI coverage.

### 7. Regression Tests (All Phases)

**Files**: All existing test files

#### Test Cases

| Suite | Expected |
|-------|----------|
| Worker full suite | All pass, 0 skipped, total ≥ 196 |
| Laravel full suite | All pass, 0 risky, 0 failed |
| Frontend suite | All pass (187+) |
| E2E suite | All pass (75+) |
| Governance suite | All pass (170+) |

## TDD Evidence Requirements

For each phase, the following evidence must be recorded:

### RED Evidence
- Test command output showing failures
- Specific test names that fail
- Verification that failure is due to missing behavior (not environment/syntax)

### GREEN Evidence
- Test command output showing all pass
- Specific test names that now pass

### REFACTOR Evidence
- Any refactoring done after GREEN
- Tests still pass after refactoring

## CI Verification Checklist

When PR is opened, the following CI checks must pass:

- [ ] Backend CI / tests: Worker tests pass with new contract tests
- [ ] Backend CI / tests: Laravel tests pass with 0 risky
- [ ] Backend CI / tests: Real PySceneDetect integration tests execute
- [ ] Frontend CI / test: Pass
- [ ] Frontend CI / lint: Pass
- [ ] Frontend CI / build: Pass
- [ ] E2E CI / e2e: Pass
- [ ] governance / governance: Pass
- [ ] governance / pr-enforcement: Pass on PR HEAD

## Acceptance Criteria Mapping

| Acceptance Criterion | Test Coverage |
|---------------------|---------------|
| JSON Schema conditional requires `media.duration_ms` for `detect_scenes` | TC-W-01 through TC-W-10 |
| Laravel validates duration before empty-scenes early return | TC-L-01 through TC-L-06 |
| 0 risky Laravel tests | RT-01 through RT-04 fixed |
| PHP contract preflight rejects invalid contracts before subprocess | TC-P-01 through TC-P-05 |
| Missing dependency produces actionable error | TC-D-01, TC-D-02 |
| Historical evidence/metadata corrected | Manual verification of docs |
| All CI checks green with 0 risky | Full CI suite |

## Out of Scope Tests

- Clip ranking tests
- New frontend component tests
- Governance/control-plane tests
- MediaScene model tests (not implemented)
- media_scenes table tests (not implemented)

## Test Data Requirements

- Worker fixtures: `services/worker/tests/fixtures/valid_sample.mp4` (existing)
- Laravel factories: `MediaAsset::factory()` (existing)
- Mock contracts for worker validation tests
- Mock Process for ProcessMediaAction tests

## Tools

- Python: pytest with jsonschema Draft7Validator
- Laravel: Pest with RefreshDatabase trait
- Frontend: Vitest + React Testing Library
- E2E: Playwright
- Governance: Custom scripts