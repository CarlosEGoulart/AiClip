# Test Plan: Deterministic Scene Detection Worker Stage

## Unit Tests

### Python Worker Scene Detection Engine Abstraction

#### Scene and SceneResult

**Test**: `test_scene_creation_with_valid_data`
- **Given**: Valid index, start_ms, end_ms
- **When**: `Scene` dataclass is created
- **Then**: All fields are stored correctly
- **And**: `index` is integer
- **And**: `start_ms` is integer >= 0
- **And**: `end_ms` is integer > start_ms

**Test**: `test_scene_validation_start_ms_negative`
- **Given**: `start_ms` = -1
- **When**: `Scene` dataclass is created
- **Then**: Raises `ValueError`

**Test**: `test_scene_validation_end_ms_less_than_start_ms`
- **Given**: `start_ms` = 1000, `end_ms` = 500
- **When**: `Scene` dataclass is created
- **Then**: Raises `ValueError`

**Test**: `test_scene_validation_end_ms_equal_to_start_ms`
- **Given**: `start_ms` = 1000, `end_ms` = 1000
- **When**: `Scene` dataclass is created
- **Then**: Raises `ValueError` (end_ms must be > start_ms)

**Test**: `test_scene_result_creation_with_valid_data`
- **Given**: Valid detector, detector_version, parameters, scenes
- **When**: `SceneResult` dataclass is created
- **Then**: All fields are stored correctly
- **And**: `scenes` is list of `Scene`

#### Deterministic Scene Detector

**Test**: `test_deterministic_detector_returns_deterministic_output`
- **Given**: Same video file path
- **When**: `DeterministicSceneDetector.detect()` is called twice
- **Then**: Both calls return identical `SceneResult`
- **And**: `detector` is "deterministic"
- **And**: `detector_version` is "0.0.0"

**Test**: `test_deterministic_detector_returns_different_output_for_different_input`
- **Given**: Two different video file paths
- **When**: `DeterministicSceneDetector.detect()` is called for each
- **Then**: `SceneResult` differs (different scenes)

**Test**: `test_deterministic_detector_scenes_format`
- **Given**: Any video file path
- **When**: `DeterministicSceneDetector.detect()` is called
- **Then**: `scenes` is list of `Scene` objects
- **And**: `scenes` ordered by `index` ascending (0-based)
- **And**: `start_ms >= 0`
- **And**: `end_ms > start_ms`
- **And**: No overlapping scenes

**Test**: `test_deterministic_detector_empty_scenes_for_short_video`
- **Given**: Video path that hashes to a value producing 0 scenes
- **When**: `DeterministicSceneDetector.detect()` is called
- **Then**: `scenes` is empty list (not error)

#### PySceneDetect Adapter

**Test**: `test_pyscenedetect_adapter_initialization`
- **Given**: Environment variables for threshold
- **When**: `PySceneDetectAdapter` is instantiated
- **Then**: Configuration is stored
- **And**: No model download occurs (mocked)

**Test**: `test_pyscenedetect_adapter_detect_calls_engine`
- **Given**: Mocked PySceneDetect
- **When**: `PySceneDetectAdapter.detect()` is called
- **Then**: Scene detection is invoked
- **And**: Result is converted to `SceneResult`

#### Factory Function

**Test**: `test_get_scene_detector_returns_deterministic_for_deterministic_engine`
- **Given**: `SCENE_DETECTION_ENGINE=deterministic`
- **When**: `get_scene_detector()` is called
- **Then**: Returns `DeterministicSceneDetector` instance

**Test**: `test_get_scene_detector_returns_pyscenedetect_for_pyscenedetect_engine`
- **Given**: `SCENE_DETECTION_ENGINE=pyscenedetect`
- **When**: `get_scene_detector()` is called
- **Then**: Returns `PySceneDetectAdapter` instance

**Test**: `test_get_scene_detector_raises_for_unknown_engine`
- **Given**: `SCENE_DETECTION_ENGINE=unknown`
- **When**: `get_scene_detector()` is called
- **Then**: Raises `ValueError` or `ImportError`

#### Scene Result Validation

**Test**: `test_validate_scene_result_passes_for_valid_scenes`
- **Given**: Ordered, non-overlapping scenes with valid timing
- **When**: `validate_scene_result()` is called
- **Then**: No exception raised

**Test**: `test_validate_scene_result_rejects_unordered_scenes`
- **Given**: Scenes where scene[1].start_ms < scene[0].start_ms
- **When**: `validate_scene_result()` is called
- **Then**: Raises `ValueError`

**Test**: `test_validate_scene_result_rejects_overlapping_scenes`
- **Given**: Scenes where scene[1].start_ms < scene[0].end_ms
- **When**: `validate_scene_result()` is called
- **Then**: Raises `ValueError`

**Test**: `test_validate_scene_result_rejects_duplicate_indexes`
- **Given**: Two scenes with same index
- **When**: `validate_scene_result()` is called
- **Then**: Raises `ValueError`

**Test**: `test_validate_scene_result_accepts_empty_scenes`
- **Given**: Empty scenes list
- **When**: `validate_scene_result()` is called
- **Then**: No exception raised (empty is valid)

**Test**: `test_validate_scene_result_rejects_negative_start_ms`
- **Given**: Scene with start_ms = -1
- **When**: `validate_scene_result()` is called
- **Then**: Raises `ValueError`

**Test**: `test_validate_scene_result_rejects_end_ms_lte_start_ms`
- **Given**: Scene with end_ms = start_ms
- **When**: `validate_scene_result()` is called
- **Then**: Raises `ValueError`

### Python Worker detect-scenes Logic

#### Successful Detection

**Test**: `test_detect_scenes_returns_expected_fields_for_valid_video`
- **Given**: Valid MP4 video file (fixture: `valid_sample.mp4`)
- **When**: `detect_scenes()` is called with contract pointing to the file
- **Then**: Returns dict with `scene_detection` object
- **And**: `scene_detection` contains `detector`, `detector_version`, `parameters`, `scenes`
- **And**: `scenes` is list of dicts with `index`, `start_ms`, `end_ms`

**Test**: `test_detect_scenes_scenes_are_ordered_by_start_ms`
- **Given**: Valid MP4 video file
- **When**: `detect_scenes()` is called
- **Then**: `scenes` sorted by `start_ms` ascending
- **And**: `start_ms >= 0` for all scenes
- **And**: `end_ms > start_ms` for all scenes
- **And**: No overlapping scenes

**Test**: `test_detect_scenes_deterministic_engine_returns_fixed_output`
- **Given**: `SCENE_DETECTION_ENGINE=deterministic`
- **When**: `detect_scenes()` is called with valid video
- **Then**: Returns deterministic scenes (same every time)

**Test**: `test_detect_scenes_empty_scenes_is_valid`
- **Given**: Video that produces no scene cuts
- **When**: `detect_scenes()` is called
- **Then**: Returns `scenes` as empty list
- **And**: Status is "success"

#### Error Handling

**Test**: `test_detect_scenes_nonexistent_file_returns_error`
- **Given**: Contract pointing to non-existent file
- **When**: `detect_scenes()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "No such file" or similar
- **And**: Exit code is 1

**Test**: `test_detect_scenes_corrupt_video_returns_error`
- **Given**: Contract pointing to corrupt video file (fixture: `corrupt_sample.mp4`)
- **When**: `detect_scenes()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "Invalid" or similar

**Test**: `test_detect_scenes_empty_storage_key_returns_error`
- **Given**: Contract with empty storage key
- **When**: `detect_scenes()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "storage" or "key"

**Test**: `test_detect_scenes_invalid_contract_returns_error`
- **Given**: Contract missing required fields
- **When**: `detect_scenes()` is called
- **Then**: Returns dict with `status` = "error"

#### Timeout

**Test**: `test_detect_scenes_timeout_returns_error`
- **Given**: Contract pointing to a video file
- **When**: `detect_scenes()` is called with short timeout (1 second)
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "timeout" or "timed out"

#### Engine Selection

**Test**: `test_detect_scenes_uses_deterministic_engine_when_configured`
- **Given**: `SCENE_DETECTION_ENGINE=deterministic`
- **When**: `detect_scenes()` is called
- **Then**: `DeterministicSceneDetector` is used
- **And**: No model download occurs

### Worker CLI Tests: detect-scenes Subcommand

#### Input Validation

**Test**: `test_cli_detect_scenes_valid_contract_via_stdin`
- **Given**: Valid detect_scenes contract JSON
- **When**: CLI is invoked with `detect-scenes --contract-json`
- **Then**: Exit code is 0
- **And**: stdout contains valid JSON with `status` = "success"

**Test**: `test_cli_detect_scenes_valid_contract_via_file`
- **Given**: Valid detect_scenes contract JSON file
- **When**: CLI is invoked with `detect-scenes --contract-file`
- **Then**: Exit code is 0

**Test**: `test_cli_detect_scenes_probe_action_mismatch`
- **Given**: Contract with `action: "probe"`
- **When**: CLI is invoked with `detect-scenes`
- **Then**: Exit code is 2
- **And**: stdout contains error about action mismatch

**Test**: `test_cli_detect_scenes_no_contract_provided`
- **Given**: No contract provided
- **When**: CLI is invoked with `detect-scenes`
- **Then**: Exit code is 2

#### Exit Codes

**Test**: `test_cli_detect_scenes_exit_code_success`
- **Given**: Valid contract pointing to valid video
- **When**: CLI is invoked
- **Then**: Exit code is 0

**Test**: `test_cli_detect_scenes_exit_code_processing_error`
- **Given**: Contract pointing to corrupt video
- **When**: CLI is invoked
- **Then**: Exit code is 1

**Test**: `test_cli_detect_scenes_exit_code_invalid_input`
- **Given**: Invalid contract JSON
- **When**: CLI is invoked
- **Then**: Exit code is 2

#### Output Format

**Test**: `test_cli_detect_scenes_success_output_is_valid_json`
- **Given**: Valid contract pointing to valid video
- **When**: CLI is invoked
- **Then**: stdout is valid JSON
- **And**: JSON contains `status` = "success"
- **And**: JSON contains `scene_detection` object

**Test**: `test_cli_detect_scenes_error_output_is_valid_json`
- **Given**: Contract pointing to corrupt video
- **When**: CLI is invoked
- **Then**: stdout is valid JSON
- **And**: JSON contains `status` = "error"

### Worker Contract Validation Tests

**Test**: `test_contract_valid_with_action_detect_scenes`
- **Given**: Contract with `action: "detect_scenes"`
- **When**: Contract is validated
- **Then**: Validation passes

**Test**: `test_contract_valid_with_action_detect_scenes_no_extra_fields`
- **Given**: Contract with `action: "detect_scenes"` and no extra fields
- **When**: Contract is validated
- **Then**: Validation passes

**Test**: `test_contract_invalid_unknown_action`
- **Given**: Contract with `action: "unknown_action"`
- **When**: Contract is validated
- **Then**: Validation fails

### Worker Timeout and Process Isolation Tests

**Test**: `test_detect_scenes_timeout_is_real_wall_clock`
- **Given**: Mocked slow scene detection
- **When**: `detect_scenes()` is called with 1s timeout
- **Then**: Returns error within bounded time (< 3s)
- **And**: Error contains "timeout"

**Test**: `test_detect_scenes_timeout_kills_child`
- **Given**: Mocked child process
- **When**: Timeout occurs
- **Then**: Child process group is killed

**Test**: `test_detect_scenes_popen_receives_start_new_session`
- **Given**: Mocked subprocess.Popen
- **When**: `detect_scenes()` is called
- **Then**: Popen called with `start_new_session=True`

**Test**: `test_detect_scenes_killpg_not_called_when_pgid_equals_parent`
- **Given**: Child PGID equals parent PGID
- **When**: Kill is attempted
- **Then**: `killpg` is NOT called (self-group defense)

### Worker No-Dependency Tests

**Test**: `test_detect_scenes_no_shell_interpolation`
- **Given**: Contract with storage key containing shell metacharacters
- **When**: `detect_scenes()` is called
- **Then**: No shell injection occurs
- **And**: Contract is passed via JSON argument

**Test**: `test_detect_scenes_no_db_dependency`
- **Given**: Worker running without database
- **When**: `detect_scenes()` is called
- **Then**: No database connection attempted
- **And**: No PostgreSQL credentials referenced

**Test**: `test_detect_scenes_no_model_download`
- **Given**: `SCENE_DETECTION_ENGINE=deterministic`
- **When**: `detect_scenes()` is called
- **Then**: No model download occurs
- **And**: No network requests made

## Feature Tests

### MediaSceneAnalysis Model Tests

#### CRUD Operations

**Test**: `test_media_scene_analysis_can_be_created`
- **Given**: A MediaAsset
- **When**: A MediaSceneAnalysis is created with valid data
- **Then**: MediaSceneAnalysis is persisted in database
- **And**: All fields are stored correctly

**Test**: `test_media_scene_analysis_belongs_to_media_asset`
- **Given**: A MediaAsset with a MediaSceneAnalysis
- **When**: MediaSceneAnalysis is loaded
- **Then**: `mediaAsset()` relationship returns correct MediaAsset

**Test**: `test_media_asset_has_one_media_scene_analysis`
- **Given**: A MediaAsset with a MediaSceneAnalysis
- **When**: MediaAsset is loaded
- **Then**: `sceneAnalysis()` relationship returns the MediaSceneAnalysis

#### Constraints

**Test**: `test_unique_constraint_on_media_asset_id`
- **Given**: A MediaAsset with a MediaSceneAnalysis
- **When**: Another MediaSceneAnalysis is created for the same MediaAsset
- **Then**: Database throws unique constraint violation

**Test**: `test_different_media_assets_can_have_scene_analyses`
- **Given**: Two MediaAssets
- **When**: MediaSceneAnalysis is created for each
- **Then**: Both MediaSceneAnalyses are created successfully

#### Cascade Delete

**Test**: `test_cascade_delete_removes_media_scene_analysis`
- **Given**: A MediaAsset with a MediaSceneAnalysis
- **When**: MediaAsset is deleted
- **Then**: Associated MediaSceneAnalysis is deleted

#### Status Constants

**Test**: `test_status_constants`
- **Given**: MediaSceneAnalysis class
- **When**: Status constants are accessed
- **Then**: `STATUS_PENDING` is "pending"
- **And**: `STATUS_DETECTING` is "detecting"
- **And**: `STATUS_COMPLETED` is "completed"
- **And**: `STATUS_FAILED` is "failed"

#### Status Transitions

**Test**: `test_mark_detecting_transitions_pending_to_detecting`
- **Given**: MediaSceneAnalysis with status `pending`
- **When**: `markDetecting()` is called
- **Then**: Status becomes `detecting`
- **And**: Error is cleared

**Test**: `test_mark_completed_transitions_detecting_to_completed`
- **Given**: MediaSceneAnalysis with status `detecting`
- **When**: `markCompleted()` is called with valid data
- **Then**: Status becomes `completed`
- **And**: `detector`, `detector_version`, `parameters`, `scenes` are stored

**Test**: `test_mark_failed_transitions_detecting_to_failed`
- **Given**: MediaSceneAnalysis with status `detecting`
- **When**: `markFailed()` is called with error message
- **Then**: Status becomes `failed`
- **And**: `error` is stored

**Test**: `test_invalid_transition_pending_to_completed_is_noop`
- **Given**: MediaSceneAnalysis with status `pending`
- **When**: `markCompleted()` is called
- **Then**: Status remains `pending`

**Test**: `test_invalid_transition_completed_to_failed_is_noop`
- **Given**: MediaSceneAnalysis with status `completed`
- **When**: `markFailed()` is called
- **Then**: Status remains `completed`

**Test**: `test_retry_transition_failed_to_detecting`
- **Given**: MediaSceneAnalysis with status `failed`
- **When**: `markDetecting()` is called
- **Then**: Status becomes `detecting`
- **And**: Previous error is cleared

### ProcessMediaAsset Job: Scene Detection Tests

#### Successful Flow

**Test**: `test_job_chains_probe_to_scene_detection_to_completed`
- **Given**: MediaAsset in `stored` state with video in probe result
- **And**: Mocked `ProcessMediaAction` returning success for probe and scene detection
- **When**: `ProcessMediaAsset` job is executed
- **Then**: `ProcessMediaAction::probe()` is called
- **And**: `ProcessMediaAction::detectScenes()` is called
- **And**: Asset state transitions: stored → queued → processing → probed → completed

**Test**: `test_job_creates_media_scene_analysis_on_successful_detection`
- **Given**: MediaAsset with successful probe
- **And**: Mocked `ProcessMediaAction::detectScenes()` returning success
- **When**: Job executes
- **Then**: MediaSceneAnalysis is created
- **And**: MediaSceneAnalysis has status `completed`
- **And**: MediaSceneAnalysis has detector, detector_version, parameters, scenes

**Test**: `test_job_stores_scene_detection_metadata`
- **Given**: Mocked detection result with metadata
- **When**: Job executes
- **Then**: MediaSceneAnalysis has `detector` from detection result
- **And**: MediaSceneAnalysis has `detector_version` from detection result
- **And**: MediaSceneAnalysis has `parameters` from detection result
- **And**: MediaSceneAnalysis has `scenes` from detection result

#### Idempotency

**Test**: `test_job_skips_scene_detection_if_analysis_exists`
- **Given**: MediaAsset with existing MediaSceneAnalysis (status `completed`)
- **And**: Mocked `ProcessMediaAction::probe()` returning success
- **When**: Job executes
- **Then**: `ProcessMediaAction::detectScenes()` is NOT called
- **And**: Existing MediaSceneAnalysis is unchanged

**Test**: `test_job_retries_failed_detection_on_rerun`
- **Given**: MediaAsset with existing MediaSceneAnalysis (status `failed`)
- **And**: Mocked `ProcessMediaAction::probe()` returning success
- **And**: Mocked `ProcessMediaAction::detectScenes()` returning success
- **When**: Job executes
- **Then**: `ProcessMediaAction::detectScenes()` is called
- **And**: MediaSceneAnalysis status becomes `completed`

#### Error Handling

**Test**: `test_job_marks_scene_analysis_failed_on_detection_error`
- **Given**: Mocked `ProcessMediaAction::probe()` returning success
- **And**: Mocked `ProcessMediaAction::detectScenes()` throwing `ProcessMediaException`
- **When**: Job executes
- **Then**: MediaSceneAnalysis status is `failed`
- **And**: `error` contains exception message

**Test**: `test_scene_detection_failure_does_not_block_audio_extraction`
- **Given**: Mocked `ProcessMediaAction::probe()` returning success with audio and video
- **And**: Mocked `ProcessMediaAction::detectScenes()` throwing `ProcessMediaException`
- **And**: Mocked `ProcessMediaAction::extractAudio()` returning success
- **And**: Mocked `ProcessMediaAction::transcribe()` returning success
- **When**: Job executes
- **Then**: MediaSceneAnalysis is `failed`
- **And**: DerivedAsset is created
- **And**: MediaTranscript is `completed`

**Test**: `test_transcription_failure_does_not_block_scene_detection`
- **Given**: Mocked `ProcessMediaAction::probe()` returning success with audio and video
- **And**: Mocked `ProcessMediaAction::detectScenes()` returning success
- **And**: Mocked `ProcessMediaAction::extractAudio()` returning success
- **And**: Mocked `ProcessMediaAction::transcribe()` throwing `ProcessMediaException`
- **When**: Job executes
- **Then**: MediaSceneAnalysis is `completed`
- **And**: MediaTranscript is `failed`

#### Video Without Audio

**Test**: `test_video_without_audio_still_receives_scene_detection`
- **Given**: MediaAsset with probe result having `video_codec` but no `audio_codec`
- **And**: Mocked `ProcessMediaAction::probe()` returning video-only probe
- **And**: Mocked `ProcessMediaAction::detectScenes()` returning success
- **When**: Job executes
- **Then**: `ProcessMediaAction::detectScenes()` is called
- **And**: `ProcessMediaAction::extractAudio()` is NOT called
- **And**: `ProcessMediaAction::transcribe()` is NOT called
- **And**: MediaSceneAnalysis is `completed`
- **And**: MediaAsset is `completed`

#### Audio-Only File

**Test**: `test_audio_only_file_skips_scene_detection`
- **Given**: MediaAsset with probe result having `audio_codec` but no `video_codec`
- **And**: Mocked `ProcessMediaAction::probe()` returning audio-only probe
- **And**: Mocked `ProcessMediaAction::extractAudio()` returning success
- **And**: Mocked `ProcessMediaAction::transcribe()` returning success
- **When**: Job executes
- **Then**: `ProcessMediaAction::detectScenes()` is NOT called
- **And**: `ProcessMediaAction::extractAudio()` is called
- **And**: `ProcessMediaAction::transcribe()` is called
- **And**: MediaSceneAnalysis is NOT created
- **And**: MediaAsset is `completed`

### ProcessMediaAction: detectScenes() Tests

**Test**: `test_action_passes_contract_via_json`
- **Given**: Mocked `Process` instance
- **When**: `detectScenes()` is called with contract
- **Then**: Contract JSON is passed via `--contract-json` argument

**Test**: `test_action_reads_stdout_for_result`
- **Given**: Mocked `Process` returning JSON on stdout
- **When**: `detectScenes()` is called
- **Then**: Result is parsed from stdout JSON

**Test**: `test_action_captures_stderr_on_failure`
- **Given**: Mocked `Process` failing with stderr
- **When**: `detectScenes()` is called
- **Then**: `ProcessMediaException` contains stderr content

**Test**: `test_action_enforces_timeout`
- **Given**: Mocked `Process` that takes longer than timeout
- **When**: `detectScenes()` is called with 120s timeout
- **Then**: Process is killed after timeout
- **And**: `ProcessMediaException` indicates timeout

**Test**: `test_action_uses_detect_scenes_subcommand`
- **Given**: Mocked `Process` instance
- **When**: `detectScenes()` is called
- **Then**: Command includes `detect-scenes` subcommand

## E2E Scenarios

### End-to-End Scene Detection Flow

**Scenario**: Upload video, trigger processing, verify scene detection via API

**Given**:
- User is authenticated
- Project exists
- Video file with multiple scenes uploaded

**When**:
- Upload response returns with status `stored`
- Background job processes media
- Worker invokes FFprobe (probe stage)
- Probe result stored in database
- Worker invokes scene detection engine
- Scene detection metadata stored in database

**Then**:
- MediaAsset state is `completed`
- MediaSceneAnalysis exists with status `completed`
- MediaSceneAnalysis has scenes ordered by start_ms
- MediaSceneAnalysis scenes are non-overlapping
- Scene indexes are 0-based and sequential

### Error Scenario: Corrupt Video

**Scenario**: Upload corrupt video, verify failure handling

**Given**:
- User is authenticated
- Project exists
- Corrupt video file uploaded

**When**:
- Upload response returns with status `stored`
- Background job processes media
- Worker invokes FFprobe
- Probe may succeed or fail
- Worker invokes scene detection
- Scene detection fails

**Then**:
- MediaSceneAnalysis exists with status `failed`
- MediaSceneAnalysis error contains failure message
- Audio extraction/transcription proceeds independently if applicable

### Error Scenario: No Video Stream

**Scenario**: Upload audio-only file, verify completion without scene detection

**Given**:
- User is authenticated
- Project exists
- Audio-only file uploaded

**When**:
- Upload response returns with status `stored`
- Background job processes media
- Worker invokes FFprobe
- Probe succeeds (audio only, no video)

**Then**:
- MediaSceneAnalysis is NOT created
- Audio extraction proceeds
- Transcription proceeds
- MediaAsset state is `completed`

## RED/GREEN/REFACTOR Evidence Requirements

### For Each Test

1. **RED**:
   - Write test
   - Run test
   - Verify it fails because behavior is missing
   - Document failure output

2. **GREEN**:
   - Implement minimal code to make test pass
   - Run test
   - Verify it passes
   - Document pass output

3. **REFACTOR**:
   - Improve code quality without changing behavior
   - Run test again
   - Verify it still passes
   - Document refactoring changes

### Evidence Collection

For each test file:
- Screenshot of RED failure
- Screenshot of GREEN pass
- Screenshot of REFACTOR pass
- Git diff showing implementation

## Test Execution Order

1. **Python Worker Unit Tests**
   - `test_scene_detection_engine.py`
   - `test_detect_scenes.py`
   - `test_cli_detect_scenes.py`

2. **Laravel Unit Tests**
   - `MediaSceneAnalysisTest.php`
   - `ProcessMediaActionDetectScenesTest.php`

3. **Laravel Feature Tests**
   - `ProcessMediaAssetSceneDetectionTest.php`

4. **Existing Regression Tests**
   - `ProcessMediaAssetTranscriptionTest.php` (updated mocks)
   - `ProcessMediaAssetProbeTest.php` (unchanged)
   - `ProcessMediaAssetAudioExtractionTest.php` (unchanged)

5. **Full Test Suite**
   - Run all tests to verify no regressions

## Test Environment Requirements

### Python Worker Tests

- Python 3.10+
- pytest
- jsonschema
- No model downloads (deterministic engine used)
- Test fixtures: valid MP4 video, corrupt MP4 video

### Laravel Tests

- PHP 8.2+
- Laravel 13
- Pest testing framework
- Database with migrations
- Queue driver (database)
- Storage fake for S3-compatible storage

### CI Environment

- All of the above
- `SCENE_DETECTION_ENGINE=deterministic` environment variable
- Python dependencies installed
- Database migrations run
- MinIO service available
- No model downloads

## Test Coverage Thresholds

| Component | Minimum Coverage |
|-----------|------------------|
| Python worker scene detection engine | >90% |
| Python worker detect-scenes logic | >90% |
| Python worker CLI detect-scenes | >85% |
| Laravel MediaSceneAnalysis model | >90% |
| Laravel ProcessMediaAction detectScenes | >85% |
| Laravel ProcessMediaAsset scene detection | >80% |

## Success Criteria

- All unit tests pass
- All feature tests pass
- All E2E tests pass (if applicable)
- No existing tests break
- Test coverage meets thresholds
- No flaky tests
- No skipped tests
- All RED/GREEN/REFACTOR evidence collected
- CI passes with deterministic scene detection engine
- No model downloads in CI
- Scene detection engine abstraction tested with both deterministic and mocked PySceneDetect
- MediaSceneAnalysis lifecycle fully tested
- Idempotency fully tested
- Error handling fully tested
- Pipeline restructuring preserves existing audio extraction and transcription behavior
- Existing transcription tests pass with updated mocks
