# Implementation Plan: Scene Detection Contract Hardening

## Phase 1: Worker JSON Schema Conditional

### Objective
Add Draft-07 conditional to `media_processing_v1.json` requiring `media.duration_ms` for `detect_scenes` action.

### Files to Modify

1. **`services/worker/contracts/media_processing_v1.json`**
   - Add `allOf` with `if`/`then` conditional
   - `if`: `action` property equals `"detect_scenes"` (with `required: ["action"]`)
   - `then`: require `media` object with required `duration_ms` (integer, minimum 1)

### Verification Steps (RED First)

1. Write worker contract tests for:
   - `detect_scenes` + valid positive integer duration → VALID
   - `detect_scenes` + missing `media` → INVALID
   - `detect_scenes` + `media: {}` → INVALID
   - `detect_scenes` + missing `duration_ms` → INVALID
   - `detect_scenes` + `duration_ms: 0` → INVALID
   - `detect_scenes` + `duration_ms: -1` → INVALID
   - `detect_scenes` + non-integer duration → INVALID
   - `probe` without `media` → VALID
   - `extract_audio` without `media` → VALID
   - `transcribe` without `media` → VALID

2. Run tests, verify they fail (RED)
3. Implement schema conditional
4. Run tests, verify GREEN

## Phase 2: Laravel Duration Validation Before Empty-Scenes Return

### Objective
Move duration validation to top of `validateScenes()` before any early return.

### Files to Modify

1. **`apps/api/app/Models/MediaSceneAnalysis.php`**
   - Move `if ($durationMs <= 0) { throw ... }` to the very beginning of `validateScenes()`
   - Before the `if (empty($scenes)) { return; }` early return
   - Ensure `markCompleted()` passes through the same validation

### Verification Steps (RED First)

1. Write Laravel tests for:
   - `validateScenes([], 0)` throws
   - `validateScenes([], -1)` throws
   - `validateScenes([], 1)` succeeds
   - `markCompleted(..., [], 0)` throws
   - Failed completion does not persist `COMPLETED`
   - `markCompleted(..., [], positiveDuration)` succeeds when lifecycle valid

2. Run tests, verify they fail (RED)
3. Implement fix
4. Run tests, verify GREEN

## Phase 3: Fix Risky Laravel Tests

### Objective
Replace the incorrect test and fix all 4 risky tests with meaningful assertions.

### Files to Modify

1. **`apps/api/tests/Feature/Models/MediaSceneAnalysisTest.php`**
   - Replace `test_validate_scenes_accepts_single_scene_at_zero_duration` with `test_validate_scenes_rejects_zero_duration_with_empty_scenes`
   - Fix 3 other risky tests by adding meaningful postconditions:
     - Assert persisted scenes exactly equal expected scenes
     - Assert boundary scene end == authoritative duration after completion
     - Assert sequential indexes remain persisted unchanged
     - Assert COMPLETED status is actually persisted

### Verification Steps

1. Identify the 4 risky tests from CI output
2. For each, replace `$this->assertTrue(true)` with meaningful assertion
3. Run full Laravel test suite
4. Verify: 0 failed, 0 errors, 0 risky

## Phase 4: PHP Contract Preflight in ProcessMediaAction

### Objective
Enforce contract validation in `ProcessMediaAction::detectScenes()` before subprocess creation.

### Files to Modify

1. **`apps/api/app/Services/ProcessMediaAction.php`**
   - In `detectScenes()`, add validation call before `$contractJson = json_encode(...)`
   - `if (! $contract->validate()) { throw new ProcessMediaException('Invalid detect_scenes media processing contract'); }`
   - Ensure exception uses project's existing patterns

### Verification Steps (RED First)

1. Write Laravel tests for:
   - `durationMs` null → `ProcessMediaException`
   - `durationMs` 0 → `ProcessMediaException`
   - `durationMs` negative → `ProcessMediaException`
   - Valid positive `durationMs` → process may be created
   - Invalid contract → `createProcess()` NEVER called (mock verification)

2. Run tests, verify they fail (RED)
3. Implement fix
4. Run tests, verify GREEN

## Phase 5: Actionable Missing Dependency Error

### Objective
Make `PySceneDetectAdapter` missing dependency error actionable with install instructions.

### Files to Modify

1. **`services/worker/aiclip_worker/scene_detection_pyscenedetect.py`**
   - Wrap lazy import in try/except
   - Raise `ImportError` with message: `"scenedetect is required for PySceneDetectAdapter; install the worker scene_detection extra with: pip install -e \".[scene_detection]\""`
   - Preserve lazy import (import only at detect-time)

### Verification Steps (RED First)

1. Write worker unit test:
   - Mock ImportError when importing scenedetect
   - Verify actionable error message contains install instruction
   - Verify lazy import preserved (module imports without scenedetect installed)

2. Run tests, verify they fail (RED)
3. Implement fix
4. Run tests, verify GREEN

## Phase 6: Historical Evidence Correction

### Objective
Correct `specs/053-scene-detection-worker/evidence.md` factually.

### Files to Modify

1. **`specs/053-scene-detection-worker/evidence.md`**
   - Update GREEN table with actual post-merge results (196 worker, 305 Laravel, 4 risky)
   - Add "Post-Merge Maintenance" section referencing issue #56
   - Correct Decision line: show PENDING then APPROVE after maintenance
   - Remove "Known Issues: None" since maintenance is required
   - Document which guarantees required correction

### Verification Steps

1. Update evidence.md with factual data
2. Verify no deceptive rewriting (preserve original RED/TDD history)

## Phase 7: Issue #53 Metadata Correction

### Objective
Update GitHub Issue #53 body for historical consistency.

### Files to Modify

1. **GitHub Issue #53** (via gh CLI)
   - Add architecture note: "During SDD planning, normalized MediaScene rows were superseded by Option B: scene boundaries are persisted in the MediaSceneAnalysis `scenes` JSON field."
   - Update checklist items to reflect actual implementation
   - Do NOT reopen issue

## Phase 8: PR #55 Body Correction

### Objective
Update GitHub PR #55 body for factual historical accuracy.

### Files to Modify

1. **GitHub PR #55** (via gh CLI)
   - Update totals: Worker 196/196, Laravel 305/1735/4 risky, Frontend 187, E2E 75, governance 170
   - Correct package terminology: `scenedetect[opencv-headless]` not `pyscenedetect[opencv-headless]`
   - Add post-merge maintenance note referencing issue #56
   - Do NOT claim zero risky tests for PR #55

## Phase 9: Project-State Documentation

### Objective
Update `docs/project-state.md` with correct terminology and CI description.

### Files to Modify

1. **`docs/project-state.md`**
   - Correct "pyscenedetect optional dependency" to "scenedetect[opencv-headless]"
   - Update CI description: mandatory CI contains deterministic + mocked + real FFmpeg+PySceneDetect integration
   - Preserve exactly six H1 headings
   - M4 remains IN PROGRESS
   - Next Architectural Goal: clip ranking/analysis (NOT implemented here)

## Phase 10: SDD Text Reconciliation

### Objective
Update `specs/053-scene-detection-worker/spec.md`, `plan.md`, `test-plan.md` to remove stale statements.

### Files to Modify

1. **`specs/053-scene-detection-worker/spec.md`**
   - Remove statements saying real integration is optional/not required by CI gate
   - Remove statements saying CI uses deterministic engine exclusively
   - Make duration contract unambiguous
   - Empty scenes valid ONLY with valid positive authoritative duration
   - Real integration is mandatory Backend CI coverage

2. **`specs/053-scene-detection-worker/plan.md`**
   - Update to reflect actual implementation state

3. **`specs/053-scene-detection-worker/test-plan.md`**
   - Update if exists

## Phase 11: Full Verification

### Objective
Run all test suites and verify CI readiness.

### Verification Steps

1. **Worker tests**:
   ```bash
   cd services/worker
   python -m pytest tests/ -v
   ```
   - Final exact total (should increase from 196)
   - 0 skipped
   - Contract regression tests executed
   - Missing dependency regression test executed
   - 3 real PySceneDetect integration tests executed

2. **Laravel tests**:
   ```bash
   cd apps/api
   php artisan test --compact
   ```
   - Final exact total
   - Exact assertions
   - 0 risky
   - 0 failed

3. **Run Pint**:
   ```bash
   cd apps/api && vendor/bin/pint --dirty --format agent
   ```

4. **Frontend tests**: Must still pass (187 or legitimate new total)

5. **E2E tests**: Must still pass (75 or legitimate new total)

6. **Governance tests**: Must pass

## Implementation Order

1. **Phase 1**: Worker JSON Schema Conditional (RED → GREEN)
2. **Phase 2**: Laravel Duration Validation (RED → GREEN)
3. **Phase 3**: Fix Risky Laravel Tests
4. **Phase 4**: PHP Contract Preflight (RED → GREEN)
5. **Phase 5**: Actionable Missing Dependency (RED → GREEN)
6. **Phase 6**: Historical Evidence Correction
7. **Phase 7**: Issue #53 Metadata Correction
8. **Phase 8**: PR #55 Body Correction
9. **Phase 9**: Project-State Documentation
10. **Phase 10**: SDD Text Reconciliation
11. **Phase 11**: Full Verification

## Success Criteria

- All 12 blockers resolved
- Worker tests: all pass, 0 unexpected skips, total increased from new tests
- Laravel tests: 0 failed, 0 errors, 0 risky
- Real PySceneDetect integration tests still run
- Historical documents factually accurate
- No control-plane modifications
- No clip-ranking implementation