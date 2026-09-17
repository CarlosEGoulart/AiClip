# Test Plan: Deterministic Transcription Worker Stage

## Unit Tests

### Python Worker Transcription Engine Abstraction

#### Segment and TranscriptResult

**Test**: `test_segment_creation_with_valid_data`
- **Given**: Valid start_ms, end_ms, text
- **When**: `Segment` dataclass is created
- **Then**: All fields are stored correctly
- **And**: `start_ms` is integer
- **And**: `end_ms` is integer
- **And**: `text` is string

**Test**: `test_segment_validation_start_ms_less_than_end_ms`
- **Given**: `start_ms` > `end_ms`
- **When**: `Segment` dataclass is created
- **Then**: Raises `ValueError` (or validation error)

**Test**: `test_transcript_result_creation_with_valid_data`
- **Given**: Valid language, full_text, segments, engine, model
- **When**: `TranscriptResult` dataclass is created
- **Then**: All fields are stored correctly
- **And**: `segments` is list of `Segment`

#### Deterministic Transcriber

**Test**: `test_deterministic_transcriber_returns_deterministic_output`
- **Given**: Same audio file path
- **When**: `DeterministicTranscriber.transcribe()` is called twice
- **Then**: Both calls return identical `TranscriptResult`
- **And**: `language` is "en"
- **And**: `engine` is "deterministic"
- **And**: `model` is "deterministic"

**Test**: `test_deterministic_transcriber_returns_different_output_for_different_input`
- **Given**: Two different audio file paths
- **When**: `DeterministicTranscriber.transcribe()` is called for each
- **Then**: `TranscriptResult` differs (different `full_text` or `segments`)

**Test**: `test_deterministic_transcriber_segments_format`
- **Given**: Any audio file path
- **When**: `DeterministicTranscriber.transcribe()` is called
- **Then**: `segments` is list of `Segment` objects
- **And**: `segments` ordered by `start_ms` ascending
- **And**: `start_ms >= 0`
- **And**: `end_ms >= start_ms`

#### Faster-Whisper Transcriber

**Test**: `test_faster_whisper_transcriber_initialization`
- **Given**: Environment variables for model, device, compute type
- **When**: `FasterWhisperTranscriber` is instantiated
- **Then**: Configuration is stored
- **And**: No model download occurs (mocked)

**Test**: `test_faster_whisper_transcriber_transcribe_calls_model`
- **Given**: Mocked faster-whisper model
- **When**: `FasterWhisperTranscriber.transcribe()` is called
- **Then**: Model's `transcribe` method is invoked
- **And**: Result is converted to `TranscriptResult`

#### Factory Function

**Test**: `test_get_transcriber_returns_deterministic_for_deterministic_engine`
- **Given**: `TRANSCRIPTION_ENGINE=deterministic`
- **When**: `get_transcriber()` is called
- **Then**: Returns `DeterministicTranscriber` instance

**Test**: `test_get_transcriber_returns_faster_whisper_for_faster_whisper_engine`
- **Given**: `TRANSCRIPTION_ENGINE=faster_whisper`
- **When**: `get_transcriber()` is called
- **Then**: Returns `FasterWhisperTranscriber` instance

**Test**: `test_get_transcriber_raises_for_unknown_engine`
- **Given**: `TRANSCRIPTION_ENGINE=unknown`
- **When**: `get_transcriber()` is called
- **Then**: Raises `ValueError` or `ImportError`

### Python Worker Transcribe Logic

#### Successful Transcription

**Test**: `test_transcribe_returns_expected_fields_for_valid_audio`
- **Given**: Valid normalized WAV file (fixture: `normalized_audio.wav`)
- **When**: `transcribe()` is called with contract pointing to the file
- **Then**: Returns dict with keys: `language`, `full_text`, `segments`, `engine`, `model`
- **And**: All values are of correct types
- **And**: `segments` is list of dicts with `start_ms`, `end_ms`, `text`

**Test**: `test_transcribe_segments_are_ordered_by_start_ms`
- **Given**: Valid normalized WAV file
- **When**: `transcribe()` is called
- **Then**: `segments` sorted by `start_ms` ascending
- **And**: `start_ms >= 0` for all segments
- **And**: `end_ms >= start_ms` for all segments

**Test**: `test_transcribe_full_text_concatenates_segments`
- **Given**: Valid normalized WAV file
- **When**: `transcribe()` is called
- **Then**: `full_text` equals concatenation of segment texts (with spaces)

**Test**: `test_transcribe_deterministic_engine_returns_fixed_output`
- **Given**: `TRANSCRIPTION_ENGINE=deterministic`
- **When**: `transcribe()` is called with valid audio
- **Then**: Returns deterministic transcript (same every time)

#### Error Handling

**Test**: `test_transcribe_nonexistent_file_returns_error`
- **Given**: Contract pointing to non-existent file
- **When**: `transcribe()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "No such file" or similar
- **And**: Exit code is 1

**Test**: `test_transcribe_corrupt_audio_returns_error`
- **Given**: Contract pointing to corrupt audio file (fixture: `corrupt_audio.wav`)
- **When**: `transcribe()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "Invalid" or similar
- **And**: Exit code is 1

**Test**: `test_transcribe_empty_storage_key_returns_error`
- **Given**: Contract with empty storage key
- **When**: `transcribe()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "No storage key" or similar

**Test**: `test_transcribe_invalid_contract_returns_error`
- **Given**: Contract missing required fields
- **When**: `transcribe()` is called
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "Invalid contract" or similar

#### Timeout

**Test**: `test_transcribe_timeout_returns_error`
- **Given**: Contract pointing to a large audio file
- **When**: `transcribe()` is called with short timeout (1 second)
- **Then**: Returns dict with `status` = "error"
- **And**: `error` contains "timeout" or "timed out"
- **And**: Exit code is 1

#### Engine Selection

**Test**: `test_transcribe_uses_deterministic_engine_when_configured`
- **Given**: `TRANSCRIPTION_ENGINE=deterministic`
- **When**: `transcribe()` is called
- **Then**: `DeterministicTranscriber` is used
- **And**: No model download occurs

**Test**: `test_transcribe_uses_faster_whisper_engine_when_configured`
- **Given**: `TRANSCRIPTION_ENGINE=faster_whisper`
- **When**: `transcribe()` is called
- **Then**: `FasterWhisperTranscriber` is used
- **And**: Model download may occur (but not in tests)

### Worker CLI Tests: transcribe Subcommand

#### Input Validation

**Test**: `test_cli_transcribe_valid_contract_via_stdin`
- **Given**: Valid transcribe contract JSON with `derived_asset_id`
- **When**: CLI is invoked with `transcribe --contract-json`
- **Then**: Exit code is 0
- **And**: stdout contains valid JSON with `status` = "success"

**Test**: `test_cli_transcribe_valid_contract_via_file`
- **Given**: Valid transcribe contract JSON file
- **When**: CLI is invoked with `transcribe --contract-file`
- **Then**: Exit code is 0
- **And**: stdout contains valid JSON with `status` = "success"

**Test**: `test_cli_transcribe_invalid_contract_missing_derived_asset_id`
- **Given**: Contract JSON missing `derived_asset_id` field
- **When**: CLI is invoked with `transcribe`
- **Then**: Exit code is 2
- **And**: stdout contains validation error message

**Test**: `test_cli_transcribe_probe_action_mismatch`
- **Given**: Contract with `action: "probe"`
- **When**: CLI is invoked with `transcribe`
- **Then**: Exit code is 2
- **And**: stdout contains error about action mismatch

**Test**: `test_cli_transcribe_no_contract_provided`
- **Given**: No contract provided
- **When**: CLI is invoked with `transcribe`
- **Then**: Exit code is 2

#### Exit Codes

**Test**: `test_cli_transcribe_exit_code_success`
- **Given**: Valid contract pointing to valid audio
- **When**: CLI is invoked
- **Then**: Exit code is 0

**Test**: `test_cli_transcribe_exit_code_processing_error`
- **Given**: Contract pointing to corrupt audio
- **When**: CLI is invoked
- **Then**: Exit code is 1

**Test**: `test_cli_transcribe_exit_code_invalid_input`
- **Given**: Invalid contract JSON
- **When**: CLI is invoked
- **Then**: Exit code is 2

#### Output Format

**Test**: `test_cli_transcribe_success_output_is_valid_json`
- **Given**: Valid contract pointing to valid audio
- **When**: CLI is invoked
- **Then**: stdout is valid JSON
- **And**: JSON contains `status` = "success"
- **And**: JSON contains `transcription` object with expected fields

**Test**: `test_cli_transcribe_error_output_is_valid_json`
- **Given**: Contract pointing to corrupt audio
- **When**: CLI is invoked
- **Then**: stdout is valid JSON
- **And**: JSON contains `status` = "error"
- **And**: JSON contains `error` field

### Worker Contract Validation Tests

**Test**: `test_contract_valid_with_action_transcribe_and_derived_asset_id`
- **Given**: Contract with `action: "transcribe"` and valid `derived_asset_id`
- **When**: Contract is validated
- **Then**: Validation passes

**Test**: `test_contract_valid_with_action_transcribe_without_derived_asset_id`
- **Given**: Contract with `action: "transcribe"` but no `derived_asset_id`
- **When**: Contract is validated
- **Then**: Validation fails

**Test**: `test_contract_valid_with_derived_asset_id_but_action_probe`
- **Given**: Contract with `action: "probe"` and `derived_asset_id`
- **When**: Contract is validated
- **Then**: Validation passes (optional field)

**Test**: `test_contract_invalid_unknown_action`
- **Given**: Contract with `action: "unknown_action"`
- **When**: Contract is validated
- **Then**: Validation fails

## Feature Tests

### MediaTranscript Model Tests

#### CRUD Operations

**Test**: `test_media_transcript_can_be_created`
- **Given**: A MediaAsset with a DerivedAsset of type `audio_normalized`
- **When**: A MediaTranscript is created with valid data
- **Then**: MediaTranscript is persisted in database
- **And**: All fields are stored correctly

**Test**: `test_media_transcript_belongs_to_media_asset`
- **Given**: A MediaAsset with a MediaTranscript
- **When**: MediaTranscript is loaded
- **Then**: `mediaAsset()` relationship returns correct MediaAsset

**Test**: `test_media_transcript_belongs_to_derived_asset`
- **Given**: A MediaAsset with a DerivedAsset and MediaTranscript
- **When**: MediaTranscript is loaded
- **Then**: `derivedAsset()` relationship returns correct DerivedAsset

**Test**: `test_media_asset_has_one_media_transcript`
- **Given**: A MediaAsset with a MediaTranscript
- **When**: MediaAsset is loaded
- **Then**: `transcript()` relationship returns the MediaTranscript

#### Constraints

**Test**: `test_unique_constraint_on_media_asset_id`
- **Given**: A MediaAsset with a MediaTranscript
- **When**: Another MediaTranscript is created for the same MediaAsset
- **Then**: Database throws unique constraint violation

**Test**: `test_different_media_assets_can_have_transcripts`
- **Given**: Two MediaAssets with DerivedAssets
- **When**: MediaTranscript is created for each
- **Then**: Both MediaTranscripts are created successfully

#### Cascade Delete

**Test**: `test_cascade_delete_removes_media_transcript`
- **Given**: A MediaAsset with a MediaTranscript
- **When**: MediaAsset is deleted
- **Then**: Associated MediaTranscript is deleted

**Test**: `test_restrict_delete_prevents_derived_asset_deletion`
- **Given**: A DerivedAsset referenced by a MediaTranscript
- **When**: Attempt to delete DerivedAsset
- **Then**: Database throws foreign key constraint violation

#### Status Constants

**Test**: `test_status_constants`
- **Given**: MediaTranscript class
- **When**: Status constants are accessed
- **Then**: `STATUS_PENDING` is "pending"
- **And**: `STATUS_TRANSCRIBING` is "transcribing"
- **And**: `STATUS_COMPLETED` is "completed"
- **And**: `STATUS_FAILED` is "failed"

#### Status Transitions

**Test**: `test_mark_transcribing_transitions_pending_to_transcribing`
- **Given**: MediaTranscript with status `pending`
- **When**: `markTranscribing()` is called
- **Then**: Status becomes `transcribing`

**Test**: `test_mark_completed_transitions_transcribing_to_completed`
- **Given**: MediaTranscript with status `transcribing`
- **When**: `markCompleted()` is called with valid data
- **Then**: Status becomes `completed`
- **And**: `language`, `full_text`, `segments`, `engine`, `model` are stored

**Test**: `test_mark_failed_transitions_transcribing_to_failed`
- **Given**: MediaTranscript with status `transcribing`
- **When**: `markFailed()` is called with error message
- **Then**: Status becomes `failed`
- **And**: `error` is stored

**Test**: `test_invalid_transition_pending_to_completed_is_noop`
- **Given**: MediaTranscript with status `pending`
- **When**: `markCompleted()` is called
- **Then**: Status remains `pending`

**Test**: `test_invalid_transition_completed_to_failed_is_noop`
- **Given**: MediaTranscript with status `completed`
- **When**: `markFailed()` is called
- **Then**: Status remains `completed`

### ProcessMediaAsset Job: Transcription Tests

#### Successful Flow

**Test**: `test_job_chains_probe_to_audio_extraction_to_transcription`
- **Given**: MediaAsset in `stored` state with audio in probe result
- **And**: Mocked `ProcessMediaAction` returning success for probe, extraction, and transcription
- **When**: `ProcessMediaAsset` job is executed
- **Then**: `ProcessMediaAction::probe()` is called
- **And**: `ProcessMediaAction::extractAudio()` is called
- **And**: `ProcessMediaAction::transcribe()` is called
- **And**: Asset state transitions: stored → queued → processing → probed → completed

**Test**: `test_job_creates_media_transcript_on_successful_transcription`
- **Given**: MediaAsset with successful probe and extraction
- **And**: Mocked `ProcessMediaAction::transcribe()` returning success
- **When**: Job executes
- **Then**: MediaTranscript is created
- **And**: MediaTranscript has status `completed`
- **And**: MediaTranscript has language, full_text, segments, engine, model

**Test**: `test_job_stores_transcription_metadata_in_media_transcript`
- **Given**: Mocked transcription result with metadata
- **When**: Job executes
- **Then**: MediaTranscript has `language` from transcription result
- **And**: MediaTranscript has `full_text` from transcription result
- **And**: MediaTranscript has `segments` from transcription result
- **And**: MediaTranscript has `engine` from transcription result
- **And**: MediaTranscript has `model` from transcription result

#### Idempotency

**Test**: `test_job_skips_transcription_if_media_transcript_exists`
- **Given**: MediaAsset with existing MediaTranscript (status `completed`)
- **And**: Mocked `ProcessMediaAction::probe()` returning success
- **When**: Job executes
- **Then**: `ProcessMediaAction::transcribe()` is NOT called
- **And**: Existing MediaTranscript is unchanged

**Test**: `test_job_retries_failed_transcription_on_rerun`
- **Given**: MediaAsset with existing MediaTranscript (status `failed`)
- **And**: Mocked `ProcessMediaAction::probe()` returning success
- **And**: Mocked `ProcessMediaAction::transcribe()` returning success
- **When**: Job executes
- **Then**: `ProcessMediaAction::transcribe()` is called
- **And**: MediaTranscript status becomes `completed`

#### Error Handling

**Test**: `test_job_marks_media_transcript_failed_on_transcription_error`
- **Given**: Mocked `ProcessMediaAction::probe()` returning success
- **And**: Mocked `ProcessMediaAction::extractAudio()` returning success
- **And**: Mocked `ProcessMediaAction::transcribe()` throwing `ProcessMediaException`
- **When**: Job executes
- **Then**: MediaTranscript status is `failed`
- **And**: `error` contains exception message

**Test**: `test_job_handles_audio_extraction_failure_no_transcription`
- **Given**: Mocked `ProcessMediaAction::probe()` returning success
- **And**: Mocked `ProcessMediaAction::extractAudio()` throwing `ProcessMediaException`
- **When**: Job executes
- **Then**: `ProcessMediaAction::transcribe()` is NOT called
- **And**: MediaAsset status is `failed`
- **And**: No MediaTranscript is created

#### State Transitions

**Test**: `test_valid_transition_pending_to_transcribing`
- **Given**: MediaTranscript in `pending` state
- **When**: `markTranscribing()` is called
- **Then**: State is `transcribing`

**Test**: `test_valid_transition_transcribing_to_completed`
- **Given**: MediaTranscript in `transcribing` state
- **When**: Transcription succeeds
- **Then**: State is `completed`

**Test**: `test_valid_transition_transcribing_to_failed`
- **Given**: MediaTranscript in `transcribing` state
- **When**: Transcription fails
- **Then**: State is `failed`

### ProcessMediaAction: transcribe() Tests

**Test**: `test_action_passes_contract_via_json`
- **Given**: Mocked `Process` instance
- **When**: `transcribe()` is called with contract
- **Then**: Contract JSON is passed via `--contract-json` argument

**Test**: `test_action_reads_stdout_for_result`
- **Given**: Mocked `Process` returning JSON on stdout
- **When**: `transcribe()` is called
- **Then**: Result is parsed from stdout JSON

**Test**: `test_action_captures_stderr_on_failure`
- **Given**: Mocked `Process` failing with stderr
- **When**: `transcribe()` is called
- **Then**: `ProcessMediaException` contains stderr content

**Test**: `test_action_enforces_timeout`
- **Given**: Mocked `Process` that takes longer than timeout
- **When**: `transcribe()` is called with 300s timeout
- **Then**: Process is killed after timeout
- **And**: `ProcessMediaException` indicates timeout

**Test**: `test_action_uses_transcribe_subcommand`
- **Given**: Mocked `Process` instance
- **When**: `transcribe()` is called
- **Then**: Command includes `transcribe` subcommand

## E2E Scenarios

### End-to-End Transcription Flow

**Scenario**: Upload video, trigger processing, verify transcription via API

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
- Worker invokes transcription engine
- Transcript metadata stored in database

**Then**:
- MediaAsset state is `completed`
- DerivedAsset of type `audio_normalized` exists
- MediaTranscript exists with status `completed`
- MediaTranscript has language, full_text, segments
- MediaTranscript segments are ordered by start_ms
- MediaTranscript full_text matches segment texts

### Error Scenario: Corrupt Audio

**Scenario**: Upload video with corrupt audio, verify failure handling

**Given**:
- User is authenticated
- Project exists
- Video file with corrupt audio uploaded

**When**:
- Upload response returns with status `stored`
- Background job processes media
- Worker invokes FFprobe
- Probe succeeds (audio codec detected)
- Worker invokes FFmpeg extraction
- Extraction succeeds (corrupt audio still extracted)
- Worker invokes transcription
- Transcription fails (corrupt audio)

**Then**:
- MediaAsset state is `completed` (audio extraction succeeded)
- DerivedAsset of type `audio_normalized` exists
- MediaTranscript exists with status `failed`
- MediaTranscript error contains transcription failure message

### Error Scenario: No Audio Stream

**Scenario**: Upload video without audio stream, verify completion without transcription

**Given**:
- User is authenticated
- Project exists
- Video file without audio uploaded

**When**:
- Upload response returns with status `stored`
- Background job processes media
- Worker invokes FFprobe
- Probe succeeds (no audio)
- Job detects no audio stream

**Then**:
- MediaAsset state is `completed`
- No DerivedAsset is created
- No MediaTranscript is created
- No transcription attempted

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
   - `test_transcription_engine.py`
   - `test_transcribe.py`
   - `test_cli_transcribe.py`
   - `test_contract_validation.py`

2. **Laravel Unit Tests**
   - `MediaTranscriptTest.php`
   - `ProcessMediaActionTranscribeTest.php`

3. **Laravel Feature Tests**
   - `ProcessMediaAssetTranscriptionTest.php`

4. **Full Test Suite**
   - Run all tests to verify no regressions

## Test Environment Requirements

### Python Worker Tests

- Python 3.10+
- pytest
- jsonschema
- No model downloads (deterministic engine used)
- Test fixtures: normalized WAV audio, corrupt WAV audio

### Laravel Tests

- PHP 8.2+
- Laravel 13
- Pest testing framework
- Database with migrations
- Queue driver (database)
- Storage fake for S3-compatible storage

### CI Environment

- All of the above
- `TRANSCRIPTION_ENGINE=deterministic` environment variable
- Python dependencies installed
- Database migrations run
- MinIO service available
- No model downloads

## Test Coverage Thresholds

| Component | Minimum Coverage |
|-----------|------------------|
| Python worker transcription engine | >90% |
| Python worker transcribe logic | >90% |
| Python worker CLI transcribe | >85% |
| Laravel MediaTranscript model | >90% |
| Laravel ProcessMediaAction transcribe | >85% |
| Laravel ProcessMediaAsset transcription | >80% |

## Success Criteria

- All unit tests pass
- All feature tests pass
- All E2E tests pass (if applicable)
- No existing tests break
- Test coverage meets thresholds
- No flaky tests
- No skipped tests
- All RED/GREEN/REFACTOR evidence collected
- CI passes with deterministic transcription engine
- No model downloads in CI
- Transcription engine abstraction tested with both deterministic and mocked faster-whisper
- MediaTranscript lifecycle fully tested
- Idempotency fully tested
- Error handling fully tested