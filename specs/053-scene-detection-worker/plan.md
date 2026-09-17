# Implementation Plan: Deterministic Scene Detection Worker Stage

## Phase 1: Scene Detection Engine Abstraction

### Objective
Create the scene detection engine abstraction (interface and deterministic CI implementation) in the Python worker.

### Files to Create

1. **`services/worker/aiclip_worker/scene_detection.py`**
   - `Scene` dataclass with `index: int`, `start_ms: int`, `end_ms: int`
   - `SceneResult` dataclass with `detector: str`, `detector_version: str`, `parameters: dict`, `scenes: list[Scene]`
   - `SceneDetector` abstract base class with `detect(video_path: str, options: dict) -> SceneResult`
   - `DeterministicSceneDetector` implementation that returns fixed output based on video file path hash
   - `PySceneDetectAdapter` implementation using PySceneDetect library
   - Factory function `get_scene_detector(engine: str) -> SceneDetector` based on environment variable
   - `validate_scene_result(scenes: list[Scene])` function for boundary validation

### Files to Modify

None.

### Verification Steps

1. **Unit tests for scene detection abstraction** (RED first):
   - Test `Scene` dataclass creation and validation
   - Test `SceneResult` dataclass creation
   - Test `DeterministicSceneDetector` returns deterministic output for same input
   - Test `DeterministicSceneDetector` returns different output for different input
   - Test `PySceneDetectAdapter` initialization with configuration (mocked)
   - Test `get_scene_detector` returns correct implementation based on engine name
   - Test `validate_scene_result` enforces ordering, non-overlap, valid timing

## Phase 2: Contract Schema Extension

### Objective
Extend the worker contract schema to support the `detect_scenes` action.

### Files to Modify

1. **`services/worker/contracts/media_processing_v1.json`**
   - Add `detect_scenes` to `action` enum: `["probe", "extract_audio", "transcribe", "detect_scenes"]`
   - No additional required fields for `detect_scenes` (input is the source video itself)
   - Maintain backward compatibility: existing contracts without changes still validate for probe/extract_audio/transcribe

### Files to Create

None.

### Verification Steps

1. **Schema validation tests** (RED first):
   - Test existing probe contract still validates
   - Test contract with `action: "detect_scenes"` validates
   - Test contract with `action: "detect_scenes"` and no extra fields validates
   - Test contract with unknown action fails validation

## Phase 3: Python Worker: detect-scenes Action

### Objective
Create the scene detection action in the Python worker.

### Files to Create

1. **`services/worker/aiclip_worker/actions/detect_scenes.py`**
   - `detect_scenes(contract: dict) -> dict` function (supervisor)
   - `_run_detect_scenes_child(contract: dict) -> dict` function (child process)
   - Reads video file from contract storage path
   - Invokes scene detection engine (deterministic or PySceneDetect based on configuration)
   - Timeout enforcement (configurable via `SCENE_DETECT_TIMEOUT_SECONDS` env var, default 120)
   - Subprocess supervisor pattern with `start_new_session=True`
   - `_kill_process_group(child)` helper with SIGTERM → wait → SIGKILL escalation
   - Defensive PGID check (same pattern as transcribe.py)
   - Returns structured scene detection result or error

### Files to Modify

None.

### Verification Steps

1. **Unit tests for detect_scenes logic** (RED first):
   - Test successful detection returns expected fields
   - Test deterministic engine returns fixed output
   - Test error handling for non-existent file
   - Test error handling for corrupt video file
   - Test timeout enforcement
   - Test engine selection based on environment variable
   - Test scene format validation

## Phase 4: Python Worker CLI: detect-scenes Subcommand

### Objective
Add the `detect-scenes` subcommand to the worker CLI.

### Files to Modify

1. **`services/worker/aiclip_worker/cli.py`**
   - Add `detect-scenes` subcommand with `--contract-json` and `--contract-file` arguments
   - Add `_handle_detect_scenes(args)` function
   - Validate contract for detect_scenes action
   - Invoke `detect_scenes()` action
   - Return exit codes: 0 (success), 1 (processing error), 2 (invalid input)

### Files to Create

1. **`services/worker/aiclip_worker/actions/__init__.py`** (update if needed to export detect_scenes)

### Verification Steps

1. **CLI integration tests** (RED first):
   - Test CLI with valid detect_scenes contract via stdin
   - Test CLI with valid detect_scenes contract via file
   - Test CLI with invalid contract (missing action)
   - Test CLI with probe action on detect-scenes subcommand (mismatch)
   - Test exit codes (0, 1, 2)

## Phase 5: Laravel: MediaSceneAnalysis Model and Migration

### Objective
Create the MediaSceneAnalysis model and migration for storing scene detection metadata.

### Files to Create

1. **`apps/api/app/Models/MediaSceneAnalysis.php`**
   - Eloquent model with fillable fields
   - Status constants: `STATUS_PENDING`, `STATUS_DETECTING`, `STATUS_COMPLETED`, `STATUS_FAILED`
   - `belongsTo(MediaAsset::class)` relationship
   - Status transition methods: `markDetecting()`, `markCompleted()`, `markFailed()`

2. **`apps/api/database/migrations/2026_09_17_100000_create_media_scene_analyses_table.php`**
   - Creates `media_scene_analyses` table
   - Columns: id, media_asset_id (FK), status, detector, detector_version, parameters (JSON), scenes (JSON), error, timestamps
   - Unique constraint on `(media_asset_id)`
   - Foreign key to `media_assets.id` with cascade delete

### Files to Modify

1. **`apps/api/app/Models/MediaAsset.php`**
   - Add `sceneAnalysis(): HasOne` relationship method
   - Add `isVideo(): bool` helper method (checks `video_codec` in `probe_result`)

### Verification Steps

1. **Unit tests for MediaSceneAnalysis model** (RED first):
   - Test MediaSceneAnalysis can be created with valid data
   - Test MediaSceneAnalysis belongs to MediaAsset
   - Test MediaAsset has one MediaSceneAnalysis
   - Test unique constraint on (media_asset_id)
   - Test cascade delete (MediaAsset deletion removes MediaSceneAnalysis)
   - Test status transitions (pending → detecting → completed/failed)
   - Test invalid transitions are no-ops
   - Test failed → detecting retry transition

## Phase 6: Laravel: ProcessMediaAction::detectScenes()

### Objective
Add the `detectScenes()` method to ProcessMediaAction service.

### Files to Modify

1. **`apps/api/app/Services/ProcessMediaAction.php`**
   - Add `detectScenes(MediaProcessingContract $contract): array` method
   - Uses `Symfony\Component\Process\Process` for subprocess invocation
   - Invokes worker CLI: `python -m aiclip_worker.cli detect-scenes --contract-json <json>`
   - Enforces timeout (configurable via `media.scene_detect_timeout_seconds`, default 120)
   - Throws `ProcessMediaException` on failure

2. **`apps/api/config/media.php`**
   - Add `scene_detect_timeout_seconds` configuration (default 120)

3. **`apps/api/app/Contracts/MediaProcessingContract.php`**
   - Add `detect_scenes` to valid actions list in `validate()` method

### Verification Steps

1. **Unit tests for ProcessMediaAction::detectScenes()** (RED first):
   - Mock `Process` to simulate successful detection
   - Mock `Process` to simulate detection failure
   - Mock `Process` to simulate timeout
   - Verify contract JSON passed correctly
   - Verify detection result parsed correctly

## Phase 7: Laravel: ProcessMediaAsset Pipeline Restructuring

### Objective
Restructure the ProcessMediaAsset job to run scene detection independently of audio extraction/transcription.

### Files to Modify

1. **`apps/api/app/Jobs/ProcessMediaAsset.php`**
   - **Remove Early Exit A** (lines 99-108): No audio stream → `markCompleted()` → return
   - **Remove Early Exit B** (lines 166-173): Existing completed transcript → `markCompleted()` → return
   - Add scene detection stage after probe:
     a. Check if `video_codec` is present in probe result
     b. If video exists:
        - Check for existing MediaSceneAnalysis (idempotency)
        - If completed: skip
        - If not exists or failed: create/retry
        - Create MediaSceneAnalysis with status `pending`
        - Mark as `detecting`
        - Invoke `ProcessMediaAction::detectScenes()`
        - On success: update with result, mark `completed`
        - On failure: mark `failed`
   - Restructure audio path:
     a. Only proceed if `audio_codec` is present
     b. If audio exists: existing extraction and transcription logic
     c. If no audio: skip audio path
   - New completion logic:
     - Mark MediaAsset `completed` when BOTH:
       - Scene detection resolved (completed/failed) OR no video_codec
       - Audio path resolved (completed/failed/skipped)

### Verification Steps

1. **Feature tests for ProcessMediaAsset scene detection** (RED first):
   - Test job chains probe → scene detection → completed
   - Test job creates MediaSceneAnalysis on successful detection
   - Test job marks MediaSceneAnalysis completed with correct data
   - Test job marks MediaSceneAnalysis failed on detection error
   - Test job is idempotent (skips if MediaSceneAnalysis exists)
   - Test job retries failed detection on re-run
   - Test scene detection failure does NOT block audio extraction/transcription
   - Test transcription failure does NOT block scene detection
   - Test video without audio still receives scene detection
   - Test audio-only file skips scene detection but proceeds with transcription
   - Test existing transcription tests still pass (no regression)

## Phase 8: Deterministic Unit Tests for Worker

### Objective
Create comprehensive unit tests for the Python worker scene detection logic.

### Files to Create

1. **`services/worker/tests/test_detect_scenes.py`**
   - Test successful detection returns expected fields
   - Test deterministic detector returns fixed output
   - Test detection with non-existent file returns error
   - Test detection with corrupt file returns error
   - Test timeout enforcement
   - Test engine selection based on environment variable
   - Test scene format validation
   - Test scene invariants (ordering, non-overlap, valid timing)

2. **`services/worker/tests/test_cli_detect_scenes.py`**
   - Test CLI with valid detect_scenes contract via stdin
   - Test CLI with valid detect_scenes contract via file
   - Test CLI with invalid contract
   - Test CLI with probe action on detect-scenes subcommand
   - Test exit codes (0, 1, 2)

3. **`services/worker/tests/test_scene_detection_engine.py`**
   - Test scene detection engine abstraction
   - Test deterministic detector behavior
   - Test PySceneDetect adapter initialization (mocked)
   - Test factory function returns correct implementation
   - Test validate_scene_result enforces invariants

### Files to Modify

1. **`services/worker/tests/conftest.py`**
   - Add `sample_contract_detect_scenes` fixture
   - Add `sample_contract_detect_scenes_no_storage_key` fixture
   - Add `sample_contract_detect_scenes_nonexistent_file` fixture

### Verification Steps

1. **Run pytest**:
   ```bash
   cd services/worker
   python -m pytest tests/ -v
   ```

2. **Verify all tests pass**:
   - No skipped tests
   - No flaky tests
   - All assertions are deterministic

## Phase 9: Deterministic Feature Tests for Laravel

### Objective
Create feature tests for the MediaSceneAnalysis model and ProcessMediaAsset scene detection.

### Files to Create

1. **`apps/api/tests/Feature/Models/MediaSceneAnalysisTest.php`**
   - Test MediaSceneAnalysis creation
   - Test MediaSceneAnalysis belongs to MediaAsset
   - Test unique constraint enforcement
   - Test cascade delete
   - Test status transitions
   - Test failed → retry transition

2. **`apps/api/tests/Feature/Jobs/ProcessMediaAssetSceneDetectionTest.php`**
   - Test job chains probe → scene detection → completed
   - Test job creates MediaSceneAnalysis
   - Test job marks MediaSceneAnalysis completed
   - Test job marks MediaSceneAnalysis failed
   - Test job is idempotent
   - Test scene detection failure does not block audio path
   - Test video without audio still receives scene detection
   - Test existing tests remain green

### Files to Modify

1. **`apps/api/tests/Feature/Jobs/ProcessMediaAssetTranscriptionTest.php`**
   - Update test setup to account for scene detection stage (mock `detectScenes` in action mocks)
   - Verify existing tests still pass without functional changes

### Verification Steps

1. **Run Pest tests**:
   ```bash
   cd apps/api
   php artisan test --filter=MediaSceneAnalysisTest
   php artisan test --filter=ProcessMediaAssetSceneDetectionTest
   ```

2. **Verify all tests pass**:
   - No database issues
   - No queue issues
   - No mocking issues
   - Existing transcription tests pass

## Phase 10: CI Integration

### Objective
Ensure CI can run Python worker tests and Laravel tests with scene detection.

### Files to Modify

1. **`.github/workflows/backend.yml`** (if exists)
   - Add `SCENE_DETECTION_ENGINE=deterministic` in CI environment
   - Add scene detect timeout configuration
   - Ensure worker tests run BEFORE Laravel tests

### Verification Steps

1. **Push to branch and verify CI passes**:
   - All checks green
   - Worker tests pass in CI
   - Laravel tests pass in CI
   - No flaky tests
   - No model downloads in CI

## Phase 11: Evidence Collection and Documentation

### Objective
Document implementation evidence and update project state.

### Files to Modify

1. **`docs/project-state.md`**
   - Update M4 progress
   - Add scene detection capability
   - Note MediaSceneAnalysis model
   - Update Next Architectural Goal

### Verification Steps

1. **Verify documentation is accurate**:
   - Project state reflects current capabilities
   - No outdated information

## Implementation Order

1. **Phase 1**: Scene Detection Engine Abstraction
   - Write RED tests first
   - Implement scene detection module
   - Verify GREEN

2. **Phase 2**: Contract Schema Extension
   - Write RED tests first
   - Update schema
   - Verify GREEN

3. **Phase 3**: Python Worker detect-scenes Action
   - Write RED tests first
   - Implement action
   - Verify GREEN

4. **Phase 4**: Python Worker CLI detect-scenes Subcommand
   - Write RED tests first
   - Implement CLI subcommand
   - Verify GREEN

5. **Phase 5**: Laravel MediaSceneAnalysis Model and Migration
   - Write RED tests first
   - Implement model and migration
   - Verify GREEN

6. **Phase 6**: Laravel ProcessMediaAction::detectScenes()
   - Write RED tests first
   - Implement method
   - Verify GREEN

7. **Phase 7**: Laravel ProcessMediaAsset Pipeline Restructuring
   - Write RED tests first
   - Implement pipeline changes
   - Verify GREEN

8. **Phase 8**: Deterministic Unit Tests for Worker
   - Write tests first
   - Ensure they pass with worker implementation
   - Verify coverage

9. **Phase 9**: Deterministic Feature Tests for Laravel
   - Write tests first
   - Ensure they pass with job implementation
   - Verify coverage

10. **Phase 10**: CI Integration
    - Update workflows
    - Verify CI passes

11. **Phase 11**: Evidence Collection and Documentation
    - Collect all evidence
    - Update documentation

## Dependencies

1. **Python Environment**:
   - Python 3.10+
   - pytest
   - jsonschema (for contract validation)
   - PySceneDetect (runtime only, not required for tests)
   - opencv-python-headless (runtime only, not required for tests)

2. **Laravel Environment**:
   - PHP 8.2+
   - Laravel 13
   - Pest testing framework
   - Symfony Process component

3. **CI Environment**:
   - Python runtime
   - MinIO (existing)
   - No model downloads

4. **Existing Code**:
   - MediaAsset model (Issue #35)
   - DerivedAsset model (Issue #49)
   - MediaTranscript model (Issue #51)
   - MediaProcessingContract (Issue #45)
   - ProcessMediaAsset job (Issue #45, #49, #51)
   - ProcessMediaAction service (Issue #47, #49, #51)
   - Worker contract JSON schema (Issue #45)
   - Worker CLI with probe, extract-audio, transcribe subcommands (Issue #47, #49, #51)

## Risks and Mitigations

1. **PySceneDetect version differences**:
   - Risk: Different versions may produce different scene boundaries
   - Mitigation: Pin version in production, use deterministic CI engine

2. **Timeout tuning**:
   - Risk: 120s default may be insufficient for very long videos
   - Mitigation: Make configurable via environment variable

3. **OpenCV dependency size**:
   - Risk: opencv-python-headless adds significant package size
   - Mitigation: Acceptable for production; CI uses deterministic engine without it

4. **Scene boundary precision**:
   - Risk: Different detectors may disagree on exact cut frame
   - Mitigation: Document 1-frame rounding tolerance

5. **Pipeline restructuring complexity**:
   - Risk: Changing ProcessMediaAsset flow may break existing audio extraction/transcription
   - Mitigation: Comprehensive test coverage of all paths; update existing test mocks

6. **Existing test regressions**:
   - Risk: ProcessMediaAsset restructuring may break existing transcription tests
   - Mitigation: Update existing tests to mock new `detectScenes` method; verify no functional changes

## Success Criteria

- All acceptance criteria from spec.md satisfied
- All existing tests pass unchanged (after mock updates)
- New tests provide adequate coverage
- Code follows project conventions
- No security regressions
- Documentation updated
- CI passes with deterministic scene detection engine
- Worker tests run in CI from the start
- Scene detection engine abstraction is clean and replaceable
- MediaSceneAnalysis lifecycle is independent of MediaAsset processing states
- Pipeline restructuring preserves existing audio extraction and transcription behavior
