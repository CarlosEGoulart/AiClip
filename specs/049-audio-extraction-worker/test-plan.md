# Test Plan: Deterministic Audio Extraction Worker

## Unit Tests

### Python Worker ExtractAudio Logic

#### Successful Extraction

**Test**: `test_extract_audio_returns_expected_fields_for_valid_media`
- **Given**: A valid MP4 file with audio stream (fixture: `valid_sample.mp4`)
- **When**: `extract_audio()` is called with contract pointing to the file
- **Then**: Returns dict with keys: `output_path`, `output_size_bytes`, `duration_ms`, `sample_rate`, `channels`, `codec`, `format`
- **And**: All values are of correct types (int, str)
- **And**: `output_size_bytes` is positive
- **And**: Output file exists and is a valid WAV file

**Test**: `test_extract_audio_output_is_mono_16khz_pcm_wav`
- **Given**: A valid MP4 file with audio stream
- **When**: `extract_audio()` is called
- **Then**: Output file is mono (1 channel)
- **And**: Output file is 16 kHz sample rate
- **And**: Output file is PCM WAV format (pcm_s16le codec)

**Test**: `test_extract_audio_duration_matches_source`
- **Given**: A valid MP4 file with known duration
- **When**: `extract_audio()` is called
- **Then**: Output `duration_ms` matches source duration within tolerance (±100ms)

#### Error Handling

**Test**: `test_extract_audio_nonexistent_file_returns_error`
- **Given**: Contract pointing to non-existent storage key
- **When**: `extract_audio()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "No such file" or similar
- **And**: Exit code is 1

**Test**: `test_extract_audio_corrupt_file_returns_error`
- **Given**: Contract pointing to corrupt media file (fixture: `corrupt_sample.mp4`)
- **When**: `extract_audio()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "Invalid data" or similar
- **And**: Exit code is 1

**Test**: `test_extract_audio_video_no_audio_returns_error`
- **Given**: Contract pointing to video-only MP4 (no audio stream)
- **When**: `extract_audio()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "No audio stream" or similar
- **And**: Exit code is 1

**Test**: `test_extract_audio_empty_storage_key_returns_error`
- **Given**: Contract with empty storage key
- **When**: `extract_audio()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "No storage key" or similar

**Test**: `test_extract_audio_empty_output_storage_key_returns_error`
- **Given**: Contract with empty output_storage key
- **When**: `extract_audio()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "No output storage key" or similar

#### Timeout

**Test**: `test_extract_audio_timeout_returns_error`
- **Given**: Contract pointing to a large file
- **When**: `extract_audio()` is called with short timeout (1 second)
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "timeout" or "timed out"
- **And**: Exit code is 1

**Test**: `test_extract_audio_ffmpeg_not_found_returns_error`
- **Given**: FFmpeg binary not in PATH
- **When**: `extract_audio()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "not found" or "No such file"

#### Temporary File Management

**Test**: `test_extract_audio_cleans_up_on_success`
- **Given**: Contract pointing to valid media
- **When**: `extract_audio()` completes successfully
- **Then**: No temporary files remain in job-isolated directory
- **And**: Output file exists at designated output path

**Test**: `test_extract_audio_cleans_up_on_failure`
- **Given**: Contract pointing to corrupt media
- **When**: `extract_audio()` fails
- **Then**: No temporary files remain in job-isolated directory

#### Subprocess Safety

**Test**: `test_extract_audio_no_shell_interpolation`
- **Given**: Contract with storage key containing shell metacharacters
- **When**: `extract_audio()` invokes FFmpeg
- **Then**: FFmpeg is invoked with list arguments (not shell string)
- **And**: No shell expansion occurs

### Worker CLI Tests: extract-audio Subcommand

#### Input Validation

**Test**: `test_cli_extract_audio_valid_contract_via_stdin`
- **Given**: Valid extract_audio contract JSON
- **When**: CLI is invoked with `extract-audio --contract-json`
- **Then**: Exit code is 0
- **And**: stdout contains valid JSON with `status` = "success"

**Test**: `test_cli_extract_audio_valid_contract_via_file`
- **Given**: Valid extract_audio contract JSON file
- **When**: CLI is invoked with `extract-audio --contract-file`
- **Then**: Exit code is 0
- **And**: stdout contains valid JSON with `status` = "success"

**Test**: `test_cli_extract_audio_invalid_contract_missing_output_storage`
- **Given**: Contract JSON missing `output_storage` field
- **When**: CLI is invoked with `extract-audio`
- **Then**: Exit code is 2
- **And**: stdout contains validation error message

**Test**: `test_cli_extract_audio_probe_action_mismatch`
- **Given**: Contract with `action: "probe"`
- **When**: CLI is invoked with `extract-audio`
- **Then**: Exit code is 2
- **And**: stdout contains error about action mismatch

**Test**: `test_cli_extract_audio_no_contract_provided`
- **Given**: No contract provided
- **When**: CLI is invoked with `extract-audio`
- **Then**: Exit code is 2

#### Exit Codes

**Test**: `test_cli_extract_audio_exit_code_success`
- **Given**: Valid contract pointing to valid media with audio
- **When**: CLI is invoked
- **Then**: Exit code is 0

**Test**: `test_cli_extract_audio_exit_code_processing_error`
- **Given**: Contract pointing to corrupt media
- **When**: CLI is invoked
- **Then**: Exit code is 1

**Test**: `test_cli_extract_audio_exit_code_invalid_input`
- **Given**: Invalid contract JSON
- **When**: CLI is invoked
- **Then**: Exit code is 2

**Test**: `test_cli_extract_audio_exit_code_no_audio_stream`
- **Given**: Contract pointing to video-only media (no audio)
- **When**: CLI is invoked
- **Then**: Exit code is 1

#### Output Format

**Test**: `test_cli_extract_audio_success_output_is_valid_json`
- **Given**: Valid contract pointing to valid media with audio
- **When**: CLI is invoked
- **Then**: stdout is valid JSON
- **And**: JSON contains `status` = "success"
- **And**: JSON contains `extraction` object with expected fields

**Test**: `test_cli_extract_audio_error_output_is_valid_json`
- **Given**: Contract pointing to corrupt media
- **When**: CLI is invoked
- **Then**: stdout is valid JSON
- **And**: JSON contains `status` = "error"
- **And**: JSON contains `error` field

### Worker Contract Validation Tests

**Test**: `test_contract_valid_with_action_extract_audio`
- **Given**: Contract with `action: "extract_audio"` and valid `output_storage`
- **When**: Contract is validated
- **Then**: Validation passes

**Test**: `test_contract_valid_without_action_defaults_to_probe`
- **Given**: Contract without `action` field (existing probe contract)
- **When**: Contract is validated
- **Then**: Validation passes

**Test**: `test_contract_invalid_extract_audio_without_output_storage`
- **Given**: Contract with `action: "extract_audio"` but no `output_storage`
- **When**: Contract is validated
- **Then**: Validation fails

**Test**: `test_contract_invalid_unknown_action`
- **Given**: Contract with `action: "unknown_action"`
- **When**: Contract is validated
- **Then**: Validation fails

## Feature Tests

### DerivedAsset Model Tests

#### CRUD Operations

**Test**: `test_derived_asset_can_be_created`
- **Given**: A MediaAsset exists
- **When**: A DerivedAsset is created with valid data
- **Then**: DerivedAsset is persisted in database
- **And**: All fields are stored correctly

**Test**: `test_derived_asset_belongs_to_media_asset`
- **Given**: A MediaAsset with a DerivedAsset
- **When**: DerivedAsset is loaded
- **Then**: `mediaAsset()` relationship returns correct MediaAsset

**Test**: `test_media_asset_has_many_derived_assets`
- **Given**: A MediaAsset with multiple DerivedAssets
- **When**: MediaAsset is loaded
- **Then**: `derivedAssets()` relationship returns all DerivedAssets

#### Constraints

**Test**: `test_unique_constraint_on_media_asset_id_and_type`
- **Given**: A MediaAsset with a DerivedAsset of type `audio_normalized`
- **When**: Another DerivedAsset of type `audio_normalized` is created for the same MediaAsset
- **Then**: Database throws unique constraint violation

**Test**: `test_different_types_allowed_for_same_media_asset`
- **Given**: A MediaAsset with a DerivedAsset of type `audio_normalized`
- **When**: A DerivedAsset of type `thumbnail` is created for the same MediaAsset
- **Then**: DerivedAsset is created successfully

#### Cascade Delete

**Test**: `test_cascade_delete_removes_derived_assets`
- **Given**: A MediaAsset with DerivedAssets
- **When**: MediaAsset is deleted
- **Then**: All associated DerivedAssets are deleted

#### Type Constants

**Test**: `test_audio_normalized_type_constant`
- **Given**: DerivedAsset class
- **When**: `TYPE_AUDIO_NORMALIZED` constant is accessed
- **Then**: Value is "audio_normalized"

### ProcessMediaAsset Job: Audio Extraction Tests

#### Successful Flow

**Test**: `test_job_chains_probe_to_extraction`
- **Given**: MediaAsset in `stored` state with audio in probe result
- **And**: Mocked `ProcessMediaAction` returning success for both probe and extraction
- **When**: `ProcessMediaAsset` job is executed
- **Then**: `ProcessMediaAction::probe()` is called
- **And**: `ProcessMediaAction::extractAudio()` is called
- **And**: Asset state transitions: stored → queued → processing → probed → completed

**Test**: `test_job_creates_derived_asset_on_successful_extraction`
- **Given**: MediaAsset with successful probe (audio present)
- **And**: Mocked `ProcessMediaAction::extractAudio()` returning success
- **When**: Job executes
- **Then**: DerivedAsset of type `audio_normalized` is created
- **And**: DerivedAsset has correct storage_disk, storage_key, mime_type
- **And**: DerivedAsset has size_bytes from extraction result

**Test**: `test_job_stores_extraction_metadata_in_derived_asset`
- **Given**: Mocked extraction result with metadata
- **When**: Job executes
- **Then**: DerivedAsset has duration_ms from extraction result
- **And**: DerivedAsset has sample_rate from extraction result
- **And**: DerivedAsset has channels from extraction result
- **And**: DerivedAsset has codec from extraction result

#### No Audio Stream

**Test**: `test_job_marks_completed_when_no_audio_stream`
- **Given**: MediaAsset with probe result where `audio_codec` is null
- **When**: Job executes
- **Then**: `ProcessMediaAction::extractAudio()` is NOT called
- **And**: Asset state is `completed`
- **And**: No DerivedAsset is created

#### Idempotency

**Test**: `test_job_skips_extraction_if_derived_asset_exists`
- **Given**: MediaAsset with existing `audio_normalized` DerivedAsset
- **And**: Mocked `ProcessMediaAction::probe()` returning success
- **When**: Job executes
- **Then**: `ProcessMediaAction::extractAudio()` is NOT called
- **And**: Asset state is `completed`
- **And**: Existing DerivedAsset is unchanged

#### Error Handling

**Test**: `test_job_marks_failed_on_extraction_error`
- **Given**: Mocked `ProcessMediaAction::probe()` returning success
- **And**: Mocked `ProcessMediaAction::extractAudio()` throwing `ProcessMediaException`
- **When**: Job executes
- **Then**: Asset state transitions to `failed`
- **And**: `processing_error` contains error message

**Test**: `test_job_marks_failed_on_probe_error`
- **Given**: Mocked `ProcessMediaAction::probe()` throwing `ProcessMediaException`
- **When**: Job executes
- **Then**: Asset state transitions to `failed`
- **And**: `ProcessMediaAction::extractAudio()` is NOT called

**Test**: `test_job_failure_handler_marks_failed`
- **Given**: Job that throws exception during extraction
- **When**: `failed()` method is called
- **Then**: Asset state is `failed`
- **And**: `processing_error` contains exception message

#### State Transitions

**Test**: `test_valid_transition_probed_to_completed_on_extraction_success`
- **Given**: Asset in `probed` state
- **When**: Extraction succeeds
- **Then**: State is `completed`
- **And**: `processing_completed_at` is set

**Test**: `test_valid_transition_probed_to_failed_on_extraction_failure`
- **Given**: Asset in `probed` state
- **When**: Extraction fails
- **Then**: State is `failed`
- **And**: `processing_error` contains error message

**Test**: `test_invalid_transition_stored_to_completed`
- **Given**: Asset in `stored` state
- **When**: `markCompleted()` is called
- **Then**: State remains `stored` (no change)

### ProcessMediaAction: extractAudio() Tests

**Test**: `test_action_passes_contract_via_json`
- **Given**: Mocked `Process` instance
- **When**: `extractAudio()` is called with contract
- **Then**: Contract JSON is passed via `--contract-json` argument

**Test**: `test_action_reads_stdout_for_result`
- **Given**: Mocked `Process` returning JSON on stdout
- **When**: `extractAudio()` is called
- **Then**: Result is parsed from stdout JSON

**Test**: `test_action_captures_stderr_on_failure`
- **Given**: Mocked `Process` failing with stderr
- **When**: `extractAudio()` is called
- **Then**: `ProcessMediaException` contains stderr content

**Test**: `test_action_enforces_timeout`
- **Given**: Mocked `Process` that takes longer than timeout
- **When**: `extractAudio()` is called with 120s timeout
- **Then**: Process is killed after timeout
- **And**: `ProcessMediaException` indicates timeout

**Test**: `test_action_uses_extract_audio_subcommand`
- **Given**: Mocked `Process` instance
- **When**: `extractAudio()` is called
- **Then**: Command includes `extract-audio` subcommand

## E2E Scenarios

### End-to-End Audio Extraction Flow

**Scenario**: Upload video, trigger processing, verify audio extraction via API

**Given**:
- User is authenticated
- Project exists
- Video file with audio uploaded

**When**:
- Upload response returns with status `stored`
- Background job processes media
- Worker invokes FFprobe (probe stage)
- Probe result stored in database
- Worker invokes FFmpeg (extraction stage)
- Normalized audio stored in S3
- DerivedAsset metadata stored in database

**Then**:
- MediaAsset state is `completed`
- DerivedAsset of type `audio_normalized` exists
- DerivedAsset has correct metadata (duration, sample rate, channels, codec)
- DerivedAsset storage_key points to valid WAV file in S3

### Error Scenario: Corrupt Media

**Scenario**: Upload corrupt video, verify failure handling

**Given**:
- User is authenticated
- Project exists
- Corrupt video file uploaded

**When**:
- Upload response returns with status `stored`
- Background job processes media
- Worker invokes FFprobe
- FFprobe fails with error

**Then**:
- MediaAsset state is `failed`
- `processing_error` contains error message
- No DerivedAsset is created
- User can retry or delete asset

### Error Scenario: Video Without Audio

**Scenario**: Upload video without audio stream, verify completion without extraction

**Given**:
- User is authenticated
- Project exists
- Video file without audio uploaded

**When**:
- Upload response returns with status `stored`
- Background job processes media
- Worker invokes FFprobe
- Probe succeeds (video only, no audio)
- Job detects no audio stream

**Then**:
- MediaAsset state is `completed`
- No DerivedAsset is created
- No extraction is attempted

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
   - `test_extract_audio.py`
   - `test_cli_extract_audio.py`
   - `test_contract_validation.py`

2. **Laravel Unit Tests**
   - `DerivedAssetTest.php`
   - `ProcessMediaActionExtractAudioTest.php`

3. **Laravel Feature Tests**
   - `ProcessMediaAssetAudioExtractionTest.php`

4. **Full Test Suite**
   - Run all tests to verify no regressions

## Test Environment Requirements

### Python Worker Tests

- Python 3.10+
- pytest
- jsonschema
- FFmpeg binary (deterministically pinned)
- Test fixtures: valid MP4 with audio, video-only MP4, corrupt MP4

### Laravel Tests

- PHP 8.2+
- Laravel 13
- Pest testing framework
- Database with migrations
- Queue driver (database)
- Storage fake for S3-compatible storage

### CI Environment

- All of the above
- FFmpeg pinned to specific version
- Python dependencies installed
- Database migrations run
- MinIO service available

## Success Criteria

- All unit tests pass
- All feature tests pass
- All E2E tests pass (if applicable)
- No existing tests break
- Test coverage meets thresholds:
  - Python worker extract_audio: >90%
  - Laravel DerivedAsset: >90%
  - Laravel ProcessMediaAsset audio extraction: >80%
- No flaky tests
- No skipped tests (except fixture-dependent)
- All RED/GREEN/REFACTOR evidence collected
