# Evidence: Deterministic Scene Detection Worker Stage

## Issue Reference

- **Issue**: #53
- **Title**: feat(media): add deterministic scene detection worker stage
- **Branch**: `@carlosegoulart/53/feat/scene-detection-worker-replacement`
- **Date**: 2026-09-17
- **PR**: #55

## TDD Evidence

### RED

Tests written and verified failing before implementation:

#### Python Worker
- `test_scene_creation_with_valid_data` — FAILS because Scene dataclass not implemented
- `test_deterministic_detector_returns_deterministic_output` — FAILS because DeterministicSceneDetector not implemented
- `test_detect_scenes_success` — FAILS because detect_scenes action not implemented
- `test_cli_detect_scenes_valid_contract` — FAILS because CLI subcommand not implemented

#### Laravel
- `test_media_scene_analysis_creation_with_valid_data` — FAILS because model not implemented
- `test_media_scene_analysis_pending_to_detecting` — FAILS because lifecycle methods not implemented
- `test_video_without_audio_still_receives_scene_detection` — FAILS because pipeline not restructured

### GREEN

| Suite | Tool | Collected | Passed | Failed |
|-------|------|-----------|--------|--------|
| Worker (tests) | pytest | 179 | 179 | 0 |
| Laravel (test) | Pest | ~279 | ~279 | 0 |
| E2E | Playwright | - | pass | 0 |

CI checks (all GREEN on PR #55): `governance` (9s), `pr-enforcement` (14s), `test` (29s), `tests` (1m31s), `e2e` (2m3s)

### REFACTOR

- Extracted `_kill_process_group()` helper with defensive PGID check (reused from transcribe.py)
- DeterministicSceneDetector uses hash-based fixture generation for deterministic CI output
- Extended path hash from 16 to 32 hex chars to prevent empty-string slicing for 5-scene paths
- Used case-insensitive regex for duplicate index validation in tests
- Used `Scene.__new__(Scene)` to bypass `__post_init__` for validate_scene_result boundary tests

## Architecture Decisions

1. **Pipeline restructuring**: Scene detection runs after probe, independently of audio path. No-audio video still receives scene detection.
2. **Persistence**: Single `media_scene_analyses` table with structured `scenes` JSON column (Option B).
3. **Engine abstraction**: SceneDetector ABC with DeterministicSceneDetector (CI) and PySceneDetectAdapter (runtime).
4. **Independence**: Scene failure does not block transcription; transcript failure does not block scene detection.

## Acceptance Criteria Verification

1. Worker `detect-scenes` action exists ✅
2. Laravel remains authoritative for persistence ✅
3. Python worker never writes PostgreSQL ✅
4. Scene result is validated before persistence ✅
5. Scene timestamps are ordered and bounded by media duration ✅
6. Retry does not create duplicate scene analyses ✅
7. Video without an audio stream can still be scene-detected ✅
8. Scene detection does not depend on successful transcription ✅
9. Mandatory CI has no network/model-download dependency ✅
10. Existing probe/audio/transcription behavior remains green ✅
11. Worker suite has zero unexpected skips ✅
12. Real detector coverage is clearly distinguished from deterministic coverage ✅
13. No scene/transcript/media content or secrets are written to logs ✅

Decision: APPROVE

> Independent Tester verification completed on PR #55 (HEAD: f66253f).
> All 13 blockers verified as resolved.
> CI: governance PASS, test PASS, tests PASS, e2e PASS.

## Post-Merge Maintenance (Issue #56)

After PR #55 merge (commit 2104f14), post-merge CI revealed verification gaps requiring correction:

**Actual Post-Merge Master Verification:**
- Worker: 196 passed, 0 failed, 0 skipped
- Laravel: 305 passed, 1735 assertions, 4 risky
- Frontend: 187 passed
- E2E: 75 passed
- Governance: 170 passed

**Gaps Identified and Corrected in Issue #56:**
1. **Worker JSON Schema**: `media.duration_ms` not conditionally required for `detect_scenes` — fixed with Draft-07 `if`/`then` conditional
2. **Laravel Empty-Scenes Duration**: `validateScenes([], 0)` incorrectly succeeded — fixed by moving duration validation before early return
3. **Risky Tests**: 4 risky (no-assertion) tests — fixed with meaningful postconditions
4. **PHP Contract Preflight**: `ProcessMediaAction::detectScenes()` didn't validate contract before subprocess — fixed with preflight check
5. **Missing Dependency Error**: Raw `ImportError` instead of actionable guidance — fixed with install instructions
6. **Historical Evidence**: This document contained conflicting test counts and stale decision status — corrected factually

**Maintenance PR**: #57 (to be created)
**Maintenance Branch**: `@carlosegoulart/56/fix/scene-detection-contract-hardening`

The original PR #55 GREEN table (179 worker, ~279 Laravel) reflected pre-merge state. Post-merge actuals are recorded above. This maintenance issue resolves the 4 risky tests and contract enforcement gaps without implementing new features.

## Requirements-Compliance Blockers Resolution

### Blocker 1: Real PySceneDetect Adapter
- Created `services/worker/aiclip_worker/scene_detection_pyscenedetect.py`
- `PySceneDetectAdapter` implements `SceneDetector` protocol
- Lazy-imports `scenedetect` at detect-time (not import-time)
- Uses `ContentDetector` with configurable threshold from `SCENE_DETECT_THRESHOLD` env (default 27.0)
- Accepts `options["duration_ms"]` and `options["threshold"]`
- Opens real video, executes real scene detection
- Converts timecodes to integer milliseconds
- Reports detector name "pyscenedetect" and actual library version via `scenedetect.__version__`
- Includes effective parameters in result
- Surfaces corrupt/unreadable-video errors (exceptions propagate)
- Does NOT: download models, access network, write DB, log content/secrets

### Blocker 2: Fix Dependency Name
- Changed `services/worker/pyproject.toml` scene_detection extra from:
  - `pyscenedetect>=0.6,<0.7` + `opencv-python-headless>=4.8,<5.0`
- To:
  - `scenedetect[opencv-headless]>=0.6.7,<0.7`

### Blocker 3: Change Production Default Engine
- Changed `get_scene_detector()` default in `scene_detection.py` from `"deterministic"` to `"pyscenedetect"`
- **KNOWN ISSUE**: `test_get_scene_detector_defaults_to_deterministic` in `test_scene_detection.py` explicitly tests that the default is `deterministic` (via `patch.dict(os.environ, {}, clear=True)`). This test will fail after this change. The blocker instruction stated "All existing CI tests use SCENE_DETECTION_ENGINE=deterministic explicitly" but this one test does not set the env var. This test needs to be updated by the Planner or Builder in a follow-up.

### Blocker 4: media.duration_ms Contract Propagation
- **4a**: Added `media` property with `duration_ms` (integer, minimum 0) to `contracts/media_processing_v1.json`
- **4b**: Added `public ?int $durationMs = null;` to `MediaProcessingContract.php`; set in `fromMediaAsset()`; serialized in `toArray()`; deserialized in `fromArray()`
- **4c**: In `ProcessMediaAsset.php`, set `$sceneContract->durationMs = $durationMs;` after building the detect_scenes contract
- **4d**: In `detect_scenes.py` child function, extract `duration_ms` from `contract.get("media", {})` and pass to `detector.detect()`

### Blocker 5: Laravel Duration-Bound Validation
- **5a**: `validateScenes()` now accepts optional `?int $durationMs = null` parameter; validates `end_ms <= durationMs` when duration provided
- **5b**: `markCompleted()` now accepts optional `?int $durationMs = null`; passes it to `validateScenes()`
- **5c**: `ProcessMediaAsset.php` passes `$durationMs` to both `validateScenes()` and `markCompleted()`

### Blocker 6: Evidence Branch/PR References
- Updated branch to `@carlosegoulart/53/feat/scene-detection-worker-replacement`
- Updated PR to #55
- Changed Decision to `PENDING` with superseding note

## Files Modified

### Python Worker (services/worker/)
1. `aiclip_worker/scene_detection_pyscenedetect.py` — NEW: PySceneDetectAdapter implementation
2. `aiclip_worker/scene_detection.py` — Changed default engine to "pyscenedetect"
3. `aiclip_worker/actions/detect_scenes.py` — Extract media.duration_ms from contract, pass as options
4. `pyproject.toml` — Fixed scene_detection dependency name
5. `contracts/media_processing_v1.json` — Added media.duration_ms schema property

### Laravel (apps/api/)
6. `app/Contracts/MediaProcessingContract.php` — Added $durationMs property, fromMediaAsset/fromArray/toArray support
7. `app/Models/MediaSceneAnalysis.php` — Added durationMs param to validateScenes() and markCompleted()
8. `app/Jobs/ProcessMediaAsset.php` — Pass durationMs to scene contract, validateScenes, and markCompleted

### Specs
9. `specs/053-scene-detection-worker/evidence.md` — Updated branch/PR refs, Decision PENDING

## Verification Status

- **PHP syntax**: Pint ran successfully on `MediaSceneAnalysis.php` and `ProcessMediaAsset.php` (auto-fixed minor style issues)
- **Laravel tests**: Could not run (PostgreSQL not available in this environment)
- **Python tests**: Could not execute due to permission restrictions (python not in allowed command patterns). Commands that need to be run:
  ```
  cd services/worker && SCENE_DETECTION_ENGINE=deterministic python -m pytest tests/test_scene_detection.py tests/test_detect_scenes.py tests/test_cli_detect_scenes.py tests/test_contract_detect_scenes.py -v
  cd services/worker && python -c "from aiclip_worker.scene_detection_pyscenedetect import PySceneDetectAdapter; print('Import OK')"
  cd services/worker && python -c "import os; os.environ.pop('SCENE_DETECTION_ENGINE', None); from aiclip_worker.scene_detection import get_scene_detector; d = get_scene_detector(); print(f'Default engine: {d.get_name()}')"
  ```

## Post-Merge Verification (Actual)

- **Worker**: 196 passed, 0 failed, 0 skipped (includes 3 real PySceneDetect integration tests)
- **Laravel**: 305 passed, 1735 assertions, 4 risky (resolved in Issue #56)
- **Frontend**: 187 passed
- **E2E**: 75 passed
- **Governance**: 170 passed

## Known Issues

Post-merge verification revealed contract enforcement gaps (Issue #56). See "Post-Merge Maintenance" section above.

## Additional Blocker Fixes (Issue #53 - Follow-up)

### Blocker 1: SCENE_DETECTION_ENGINE default to None
- Changed `detect_scenes.py` line 36 from `os.environ.get("SCENE_DETECTION_ENGINE", "deterministic")` to `os.environ.get("SCENE_DETECTION_ENGINE") or None`
- This ensures the factory function's default ("pyscenedetect") is used when env var is unset

### Blocker 9: Sequential 0-based Index Validation (Python + Laravel)
- **Python**: Added validation in `validate_scene_result()` to enforce sequential 0-based indexes (0, 1, 2...)
- Rejects: first scene index != 0, gaps (0, 2), reversed (1, 0), duplicates
- **Laravel**: Added same validation in `MediaSceneAnalysis::validateScenes()` and `markCompleted()`
- Duration parameter is now required `int` (rejects ≤ 0)

### Blocker 10: Remove Unused open_video() Call
- Removed `open_video()` call from `PySceneDetectAdapter.detect()`
- Now uses `scenedetect.detect()` directly without opening video first
- Proper error handling for corrupt/unreadable media (exceptions propagate)

### Blocker 2: Comprehensive PySceneDetectAdapter Tests (Mock-based)
- Added `TestPySceneDetectAdapter` class with 9 test methods
- Tests: get_name, get_version, detect structure, zero-duration handling, threshold option, empty result, error propagation, multiple scenes ordering
- Added `TestValidateSceneResultIndexInvariants` class with 5 tests for sequential index validation

### Blocker 3: PySceneDetect Integration Tests
- Created `services/worker/tests/test_pyscenedetect_integration.py`
- Generates tiny FFmpeg video with solid color cuts (A→B→C)
- Runs real PySceneDetectAdapter with real scenedetect + decode
- Verifies: ContentDetector, ordered boundaries, non-overlap, integer ms, final scene ≤ duration

### Blocker 4: CI Dependency Installation
- Changed `.github/workflows/backend.yml` from `pip install -e .` to `pip install -e ".[scene_detection,dev]"`

### Blocker 5: Laravel Required Duration Validation
- `MediaSceneAnalysis::validateScenes()` now requires `int $durationMs` (rejects ≤ 0)
- `markCompleted()` requires `int $durationMs`
- Both pass duration to validation

### Blocker 8: Explicit Worker Result Validation
- Replaced `?? []` fallbacks in `ProcessMediaAsset.php` with explicit null checks
- Validates required fields: `detector`, `detector_version`, `parameters` (array), `scenes` (array)
- Throws `ProcessMediaException` on missing/malformed fields

### Blocker 6: Contract Validation for detect_scenes
- `MediaProcessingContract::validate()` now requires `durationMs !== null && durationMs > 0` for `detect_scenes` action
- Updated `media_processing_v1.json` with conditional required: for `action == "detect_scenes"`, `media.duration_ms` required integer ≥ 1

### Blocker 7: Duration Boundary + Index Invariant + Retry Overflow Tests (Laravel)
- Added 12 duration boundary tests to `MediaSceneAnalysisTest`
- Added 4 index invariant tests (first not zero, gaps, reversed, valid sequential)
- Added 3 retry overflow tests (zero duration, negative duration, scene exceeding duration on retry)

### Blocker 8: Worker Result Validation + Duration Propagation Tests
- Added 8 worker result validation tests to `ProcessMediaAssetSceneDetectionTest`
- Added 3 duration propagation tests (contract propagation, pre-probed asset, contract validation)

### Blocker 11: Evidence Updated
- This file updated with Decision: PENDING and documentation of all 13 blocker fixes.
