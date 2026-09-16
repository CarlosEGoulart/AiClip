# Test Plan: Deterministic Media Probing Worker

## Unit Tests

### Python Worker Probe Logic

#### Successful Probe

**Test**: `test_probe_returns_expected_fields_for_valid_media`
- **Given**: A valid MP4 file (fixture: `valid_sample.mp4`)
- **When**: `probe_media()` is called with contract pointing to the file
- **Then**: Returns dict with keys: `duration_ms`, `width`, `height`, `video_codec`, `audio_codec`, `bitrate_kbps`, `fps`, `audio_channels`, `audio_sample_rate`, `format`, `size_bytes`
- **And**: All values are of correct types (int, float, string)
- **And**: `duration_ms` is positive integer
- **And**: `width` and `height` are positive integers

**Test**: `test_probe_with_zero_duration_media`
- **Given**: A valid MP4 file with zero duration (if possible)
- **When**: `probe_media()` is called
- **Then**: Returns dict with `duration_ms` = 0
- **And**: Status is success

**Test**: `test_probe_with_audio_only_media`
- **Given**: A valid audio-only MP3 file
- **When**: `probe_media()` is called
- **Then**: Returns dict with `video_codec` = null
- **And**: Returns valid audio fields

**Test**: `test_probe_with_video_only_media`
- **Given**: A valid video-only MP4 file (no audio stream)
- **When**: `probe_media()` is called
- **Then**: Returns dict with `audio_codec` = null
- **And**: Returns valid video fields

#### Error Handling

**Test**: `test_probe_nonexistent_file_returns_error`
- **Given**: Contract pointing to non-existent storage key
- **When**: `probe_media()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "No such file" or similar
- **And**: `stderr` is non-empty

**Test**: `test_probe_corrupt_file_returns_error`
- **Given**: Contract pointing to corrupt media file (fixture: `corrupt_sample.mp4`)
- **When**: `probe_media()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "Invalid data" or similar
- **And**: Exit code is 1

**Test**: `test_probe_timeout_returns_error`
- **Given**: Contract pointing to a file that triggers long processing
- **When**: `probe_media()` is called with short timeout (1 second)
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "timeout" or "timed out"
- **And**: Exit code is 1

**Test**: `test_probe_ffprobe_not_found_returns_error`
- **Given**: FFprobe binary not in PATH
- **When**: `probe_media()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "not found" or "No such file"

### Worker CLI Tests

#### Input Validation

**Test**: `test_cli_valid_contract_via_stdin`
- **Given**: Valid contract JSON
- **When**: CLI is invoked with `--contract-json`
- **Then**: Exit code is 0
- **And**: stdout contains valid JSON with `status` = "success"

**Test**: `test_cli_valid_contract_via_file`
- **Given**: Valid contract JSON file
- **When**: CLI is invoked with `--contract-file`
- **Then**: Exit code is 0
- **And**: stdout contains valid JSON with `status` = "success"

**Test**: `test_cli_invalid_contract_missing_fields`
- **Given**: Contract JSON missing required fields
- **When**: CLI is invoked
- **Then**: Exit code is 2
- **And**: stderr contains validation error message

**Test**: `test_cli_unknown_major_version`
- **Given**: Contract with `version` = "2.0.0"
- **When**: CLI is invoked
- **Then**: Exit code is 2
- **And**: stderr contains "unsupported version" or similar

**Test**: `test_cli_nonexistent_storage_key`
- **Given**: Contract with non-existent storage key
- **When**: CLI is invoked
- **Then**: Exit code is 1
- **And**: stdout contains error JSON

#### Exit Codes

**Test**: `test_cli_exit_code_success`
- **Given**: Valid contract pointing to valid media
- **When**: CLI is invoked
- **Then**: Exit code is 0

**Test**: `test_cli_exit_code_processing_error`
- **Given**: Contract pointing to corrupt media
- **When**: CLI is invoked
- **Then**: Exit code is 1

**Test**: `test_cli_exit_code_invalid_input`
- **Given**: Invalid contract JSON
- **When**: CLI is invoked
- **Then**: Exit code is 2

### Worker Error Handling Tests

**Test**: `test_capture_stderr_on_ffprobe_failure`
- **Given**: Contract pointing to corrupt file
- **When**: Worker invokes FFprobe
- **Then**: stderr is captured and included in error response

**Test**: `test_enforce_timeout_on_ffprobe_invocation`
- **Given**: Contract pointing to large file
- **When**: Worker invokes FFprobe with 1s timeout
- **Then**: FFprobe process is killed after timeout
- **And**: Error response indicates timeout

**Test**: `test_no_shell_interpolation_in_subprocess`
- **Given**: Contract with storage key containing shell metacharacters
- **When**: Worker invokes FFprobe
- **Then**: FFprobe is invoked with list arguments (not shell string)
- **And**: No shell expansion occurs

## Feature Tests

### ProcessMediaAsset Job Tests

#### Successful Probe Flow

**Test**: `test_job_invokes_process_media_action_on_success`
- **Given**: MediaAsset in `stored` state
- **And**: Mocked `ProcessMediaAction` returning success
- **When**: `ProcessMediaAsset` job is executed
- **Then**: `ProcessMediaAction::probe()` is called with contract
- **And**: Asset state transitions: stored → queued → processing → probed
- **And**: `probe_result` is stored in database
- **And**: `duration_ms` is stored in database

**Test**: `test_job_stores_probe_result_correctly`
- **Given**: Mocked `ProcessMediaAction` returning probe result
- **When**: Job executes
- **Then**: `probe_result` column contains JSON with all probe fields
- **And**: `duration_ms` column contains integer milliseconds

**Test**: `test_job_transitions_to_probed_state`
- **Given**: Asset in `processing` state
- **When**: Probe succeeds
- **Then**: Asset state is `probed`
- **And**: `processing_completed_at` is NOT set (still processing)

#### Error Handling

**Test**: `test_job_marks_failed_on_worker_error`
- **Given**: Mocked `ProcessMediaAction` throwing `ProcessMediaException`
- **When**: Job executes
- **Then**: Asset state transitions to `failed`
- **And**: `processing_error` contains error message

**Test**: `test_job_marks_failed_on_timeout`
- **Given**: Mocked `ProcessMediaAction` throwing timeout exception
- **When**: Job executes
- **Then**: Asset state is `failed`
- **And**: `processing_error` contains "timeout" or "timed out"

**Test**: `test_job_failure_handler_marks_failed`
- **Given**: Job that throws exception
- **When**: `failed()` method is called
- **Then**: Asset state is `failed`
- **And**: `processing_error` contains exception message

#### State Transitions

**Test**: `test_valid_transition_stored_to_queued`
- **Given**: Asset in `stored` state
- **When**: Job marks as queued
- **Then**: State is `queued`

**Test**: `test_valid_transition_queued_to_processing`
- **Given**: Asset in `queued` state
- **When**: Job marks as processing
- **Then**: State is `processing`

**Test**: `test_valid_transition_processing_to_probed`
- **Given**: Asset in `processing` state
- **When**: Probe succeeds
- **Then**: State is `probed`

**Test**: `test_valid_transition_probed_to_completed`
- **Given**: Asset in `probed` state
- **When**: Job marks as completed
- **Then**: State is `completed`

**Test**: `test_invalid_transition_stored_to_probed`
- **Given**: Asset in `stored` state
- **When**: `markProbed()` is called
- **Then**: State remains `stored` (no change)

**Test**: `test_invalid_transition_completed_to_probed`
- **Given**: Asset in `completed` state
- **When**: `markProbed()` is called
- **Then**: State remains `completed` (no change)

### ProcessMediaAction Tests

**Test**: `test_action_passes_contract_via_stdin`
- **Given**: Mocked `Process` instance
- **When**: `probe()` is called with contract
- **Then**: Contract JSON is passed via stdin to worker CLI

**Test**: `test_action_reads_stdout_for_result`
- **Given**: Mocked `Process` returning JSON on stdout
- **When**: `probe()` is called
- **Then**: Result is parsed from stdout JSON

**Test**: `test_action_captures_stderr_on_failure`
- **Given**: Mocked `Process` failing with stderr
- **When**: `probe()` is called
- **Then**: `ProcessMediaException` contains stderr content

**Test**: `test_action_enforces_timeout`
- **Given**: Mocked `Process` that takes longer than timeout
- **When**: `probe()` is called with 1s timeout
- **Then**: Process is killed after timeout
- **And**: `ProcessMediaException` indicates timeout

### MediaAsset Probed State Tests

**Test**: `test_new_probed_state_constant`
- **Given**: MediaAsset class
- **When**: `PROCESSING_PROBED` constant is accessed
- **Then**: Value is "probed"

**Test**: `test_mark_probed_method`
- **Given**: Asset in `processing` state
- **When**: `markProbed()` is called with probe result and duration
- **Then**: State is `probed`
- **And**: `probe_result` contains provided data
- **And**: `duration_ms` contains provided duration

**Test**: `test_valid_transitions_include_probed`
- **Given**: MediaAsset class
- **When**: Valid transitions are checked
- **Then**: `processing` → `probed` is valid
- **And**: `probed` → `completed` is valid
- **And**: `probed` → `failed` is valid

**Test**: `test_invalid_transitions_to_probed`
- **Given**: MediaAsset class
- **When**: Invalid transitions are checked
- **Then**: `stored` → `probed` is invalid
- **And**: `queued` → `probed` is invalid
- **And**: `completed` → `probed` is invalid
- **And**: `failed` → `probed` is invalid

## Auth Focus Test Stabilization

### Test Case

**Test**: `test_associates_all_422_field_errors_and_focuses_first_invalid_field_for_register_false`
- **Given**: LoginForm with validation errors
- **When**: Form is submitted and validation errors appear
- **Then**: First invalid field receives focus
- **And**: Focus assertion uses `waitFor()` synchronization

### Verification

**10 Consecutive Runs**:
```bash
cd apps/web
for i in {1..10}; do
  npx vitest run --reporter=verbose src/__tests__/Auth.test.tsx -t "associates all 422 field errors"
  echo "Run $i completed"
done
```

**Expected**: All 10 runs pass without failures.

### Constraints

- NO `test.retry()`
- NO `setTimeout()`
- NO `sleep()`
- NO skipping
- NO removing `toHaveFocus()` assertion

## E2E Scenarios

### End-to-End Probe Flow

**Scenario**: Upload media, trigger probing, verify probe result via API

**Given**:
- User is authenticated
- Project exists
- Media file uploaded

**When**:
- Upload response returns with status `stored`
- Background job processes media
- Worker invokes FFprobe
- Probe result stored in database

**Then**:
- MediaAsset state is `probed`
- `probe_result` contains valid JSON
- `duration_ms` is positive integer
- API response includes probe information (if endpoint exists)

### Error Scenario

**Scenario**: Upload corrupt media, verify failure handling

**Given**:
- User is authenticated
- Project exists
- Corrupt media file uploaded

**When**:
- Upload response returns with status `stored`
- Background job processes media
- Worker invokes FFprobe
- FFprobe fails with error

**Then**:
- MediaAsset state is `failed`
- `processing_error` contains error message
- User can retry or delete asset

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
   - `test_probe.py`
   - `test_cli.py`
   - `test_error_handling.py`

2. **Laravel Unit Tests**
   - `ProcessMediaActionTest.php`
   - `MediaAssetProbedStateTest.php`

3. **Laravel Feature Tests**
   - `ProcessMediaAssetProbeTest.php`

4. **Frontend Tests**
   - Auth focus test stabilization
   - 10 consecutive runs

5. **E2E Tests** (if applicable)
   - End-to-end probe flow

6. **Full Test Suite**
   - Run all tests to verify no regressions

## Test Environment Requirements

### Python Worker Tests

- Python 3.10+
- pytest
- jsonschema
- FFprobe/FFmpeg binaries (deterministically pinned)
- Test fixtures: valid MP4, corrupt MP4, audio-only MP3

### Laravel Tests

- PHP 8.2+
- Laravel 13
- Pest testing framework
- Database with migrations
- Queue driver (database)
- Storage fake for S3-compatible storage

### Frontend Tests

- Node.js 18+
- Vitest
- React Testing Library
- jsdom environment

### CI Environment

- All of the above
- FFprobe/FFmpeg pinned to specific version
- Python dependencies installed
- Database migrations run
- MinIO service available

## Success Criteria

- All unit tests pass
- All feature tests pass
- All E2E tests pass (if applicable)
- Auth flaky test passes 10 consecutive runs
- No existing tests break
- Test coverage meets thresholds:
  - Python worker: >90%
  - Laravel job: >80%
  - Frontend auth: 100% for affected test
- No flaky tests
- No skipped tests
- All RED/GREEN/REFACTOR evidence collected