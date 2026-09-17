# Implementation Plan: Deterministic Transcription Worker Stage

## Phase 1: Transcription Engine Abstraction

### Objective
Create the transcription engine abstraction (interface and deterministic CI implementation) in the Python worker.

### Files to Create

1. **`services/worker/aiclip_worker/transcription.py`**
   - `Segment` dataclass with `start_ms: int`, `end_ms: int`, `text: str`
   - `TranscriptResult` dataclass with `language: str`, `full_text: str`, `segments: list[Segment]`, `engine: str`, `model: str`
   - `Transcriber` abstract base class with `transcribe(audio_path: str, options: dict) -> TranscriptResult`
   - `DeterministicTranscriber` implementation that returns fixed output based on audio file path hash
   - `FasterWhisperTranscriber` implementation using faster-whisper library
   - Factory function `get_transcriber(engine: str) -> Transcriber` based on environment variable

### Files to Modify

None.

### Verification Steps

1. **Unit tests for transcription abstraction** (RED first):
   - Test `Segment` dataclass creation and validation
   - Test `TranscriptResult` dataclass creation
   - Test `DeterministicTranscriber` returns deterministic output for same input
   - Test `DeterministicTranscriber` returns different output for different input
   - Test `FasterWhisperTranscriber` initialization with configuration
   - Test `get_transcriber` returns correct implementation based on engine name

## Phase 2: Contract Schema Extension

### Objective
Extend the worker contract schema to support the `transcribe` action and `derived_asset_id` field.

### Files to Modify

1. **`services/worker/contracts/media_processing_v1.json`**
   - Add `transcribe` to `action` enum: `["probe", "extract_audio", "transcribe"]`
   - Add `derived_asset_id` field: integer, minimum 1, required for transcribe action
   - Maintain backward compatibility: existing contracts without `derived_asset_id` still validate for probe/extract_audio

### Files to Create

None.

### Verification Steps

1. **Schema validation tests** (RED first):
   - Test existing probe contract (no `derived_asset_id`) still validates
   - Test contract with `action: "transcribe"` and valid `derived_asset_id` validates
   - Test contract with `action: "transcribe"` without `derived_asset_id` fails validation
   - Test contract with `derived_asset_id` but `action: "probe"` still validates (optional field)
   - Test contract with unknown action fails validation

## Phase 3: Python Worker: Transcribe Action

### Objective
Create the transcription action in the Python worker.

### Files to Create

1. **`services/worker/aiclip_worker/actions/transcribe.py`**
   - `transcribe(contract: dict) -> dict` function
   - Reads audio file from contract storage path
   - Invokes transcription engine (deterministic or faster-whisper based on configuration)
   - Timeout enforcement (configurable via `TRANSCRIBE_TIMEOUT_SECONDS` env var, default 300)
   - Returns structured transcription result or error
   - Cleans up temporary files if any

### Files to Modify

None.

### Verification Steps

1. **Unit tests for transcribe logic** (RED first):
   - Test successful transcription returns expected fields
   - Test transcription with deterministic engine returns fixed output
   - Test error handling for non-existent file
   - Test error handling for corrupt audio file
   - Test timeout enforcement
   - Test engine selection based on environment variable

## Phase 4: Python Worker CLI: transcribe Subcommand

### Objective
Add the `transcribe` subcommand to the worker CLI.

### Files to Modify

1. **`services/worker/aiclip_worker/cli.py`**
   - Add `transcribe` subcommand with `--contract-json` and `--contract-file` arguments
   - Add `_handle_transcribe(args)` function
   - Validate contract for transcribe action
   - Invoke `transcribe()` action
   - Return exit codes: 0 (success), 1 (processing error), 2 (invalid input)

### Verification Steps

1. **CLI integration tests** (RED first):
   - Test CLI with valid transcribe contract via stdin
   - Test CLI with valid transcribe contract via file
   - Test CLI with invalid contract (missing derived_asset_id)
   - Test CLI with probe action on transcribe subcommand (mismatch)
   - Test exit codes (0, 1, 2)

## Phase 5: Laravel: MediaTranscript Model and Migration

### Objective
Create the MediaTranscript model and migration for storing transcript metadata.

### Files to Create

1. **`apps/api/app/Models/MediaTranscript.php`**
   - Eloquent model with fillable fields
   - Status constants: `STATUS_PENDING`, `STATUS_TRANSCRIBING`, `STATUS_COMPLETED`, `STATUS_FAILED`
   - `belongsTo(MediaAsset::class)` relationship
   - `belongsTo(DerivedAsset::class)` relationship
   - Status transition methods: `markTranscribing()`, `markCompleted()`, `markFailed()`

2. **`apps/api/database/migrations/YYYY_MM_DD_HHMMSS_create_media_transcripts_table.php`**
   - Creates `media_transcripts` table
   - Columns: id, media_asset_id (FK), derived_asset_id (FK), status, language, full_text, segments (JSON), engine, model, error, timestamps
   - Unique constraint on `(media_asset_id)`
   - Foreign key to `media_assets.id` with cascade delete
   - Foreign key to `derived_assets.id` with restrict delete

### Files to Modify

1. **`apps/api/app/Models/MediaAsset.php`**
   - Add `transcript(): HasOne` relationship method

### Verification Steps

1. **Unit tests for MediaTranscript model** (RED first):
   - Test MediaTranscript can be created with valid data
   - Test MediaTranscript belongs to MediaAsset
   - Test MediaTranscript belongs to DerivedAsset
   - Test MediaAsset has one MediaTranscript
   - Test unique constraint on (media_asset_id)
   - Test cascade delete (MediaAsset deletion removes MediaTranscript)
   - Test status transitions (pending → transcribing → completed/failed)
   - Test invalid transitions are no-ops

## Phase 6: Laravel: ProcessMediaAction::transcribe()

### Objective
Add the `transcribe()` method to ProcessMediaAction service.

### Files to Modify

1. **`apps/api/app/Services/ProcessMediaAction.php`**
   - Add `transcribe(MediaProcessingContract $contract): array` method
   - Uses `Symfony\Component\Process\Process` for subprocess invocation
   - Invokes worker CLI: `python -m aiclip_worker.cli transcribe --contract-json <json>`
   - Enforces timeout (configurable via `media.transcribe_timeout_seconds`, default 300)
   - Throws `ProcessMediaException` on failure

2. **`apps/api/config/media.php`**
   - Add `transcribe_timeout_seconds` configuration (default 300)

### Verification Steps

1. **Unit tests for ProcessMediaAction::transcribe()** (RED first):
   - Mock `Process` to simulate successful transcription
   - Mock `Process` to simulate transcription failure
   - Mock `Process` to simulate timeout
   - Verify contract JSON passed correctly
   - Verify transcription result parsed correctly

## Phase 7: Laravel: ProcessMediaAsset Job: Transcription Stage

### Objective
Extend the ProcessMediaAsset job to chain probe → audio extraction → transcription.

### Files to Modify

1. **`apps/api/app/Jobs/ProcessMediaAsset.php`**
   - After audio extraction succeeds (or DerivedAsset exists):
     a. Check for existing MediaTranscript (idempotency)
     b. If no transcript exists:
        i. Create MediaTranscript with status `pending`
        ii. Build transcribe contract with `derived_asset_id`
        iii. Mark MediaTranscript as `transcribing`
        iv. Invoke `ProcessMediaAction::transcribe()`
        v. On success: update MediaTranscript with result, mark `completed`
        vi. On failure: mark MediaTranscript `failed`
     c. If transcript exists with status `completed`: skip
     d. If transcript exists with status `failed`: retry transcription

2. **`apps/api/app/Contracts/MediaProcessingContract.php`**
   - Add `derivedAssetId` field (optional, for transcribe action)
   - Update `toArray()` to include new field
   - Update validation to require `derivedAssetId` for transcribe action

### Verification Steps

1. **Feature tests for ProcessMediaAsset transcription** (RED first):
   - Test job chains probe → audio extraction → transcription
   - Test job creates MediaTranscript on successful transcription
   - Test job marks MediaTranscript completed with correct data
   - Test job marks MediaTranscript failed on transcription error
   - Test job is idempotent (skips if MediaTranscript exists)
   - Test job retries failed transcription on re-run
   - Test job handles audio extraction failure (does not reach transcription)

## Phase 8: Deterministic Unit Tests for Worker

### Objective
Create comprehensive unit tests for the Python worker transcribe logic.

### Files to Create

1. **`services/worker/tests/test_transcribe.py`**
   - Test successful transcription returns expected fields
   - Test deterministic transcriber returns fixed output
   - Test transcription with non-existent file returns error
   - Test transcription with corrupt file returns error
   - Test timeout enforcement
   - Test engine selection based on environment variable
   - Test segment format validation

2. **`services/worker/tests/test_cli_transcribe.py`**
   - Test CLI with valid transcribe contract via stdin
   - Test CLI with valid transcribe contract via file
   - Test CLI with invalid contract (missing derived_asset_id)
   - Test CLI with probe action on transcribe subcommand
   - Test exit codes (0, 1, 2)

3. **`services/worker/tests/test_transcription_engine.py`**
   - Test transcription engine abstraction
   - Test deterministic transcriber behavior
   - Test faster-whisper transcriber initialization (mocked)
   - Test factory function returns correct implementation

### Files to Modify

1. **`services/worker/tests/conftest.py`**
   - Add `sample_contract_transcribe` fixture
   - Add `sample_contract_transcribe_no_derived_asset` fixture
   - Add `sample_contract_transcribe_corrupt_audio` fixture

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
Create feature tests for the MediaTranscript model and ProcessMediaAsset transcription.

### Files to Create

1. **`apps/api/tests/Feature/Models/MediaTranscriptTest.php`**
   - Test MediaTranscript creation
   - Test MediaTranscript belongs to MediaAsset
   - Test MediaTranscript belongs to DerivedAsset
   - Test unique constraint enforcement
   - Test cascade delete
   - Test status transitions

2. **`apps/api/tests/Feature/Jobs/ProcessMediaAssetTranscriptionTest.php`**
   - Test job chains probe → audio extraction → transcription
   - Test job creates MediaTranscript
   - Test job marks MediaTranscript completed
   - Test job marks MediaTranscript failed
   - Test job is idempotent

### Verification Steps

1. **Run Pest tests**:
   ```bash
   cd apps/api
   php artisan test --filter=MediaTranscriptTest
   php artisan test --filter=ProcessMediaAssetTranscriptionTest
   ```

2. **Verify all tests pass**:
   - No database issues
   - No queue issues
   - No mocking issues

## Phase 10: CI Integration

### Objective
Ensure CI can run Python worker tests and Laravel tests with transcription.

### Files to Modify

1. **`.github/workflows/backend.yml`**
   - Add Python setup step (if not exists)
   - Add Python dependencies installation for worker
   - Add worker test step (`python -m pytest tests/ -v`)
   - Ensure worker tests run BEFORE Laravel tests
   - Set `TRANSCRIPTION_ENGINE=deterministic` in CI environment
   - Add transcribe timeout configuration

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

### Files to Create

1. **`specs/051-transcription-worker/evidence.md`**
   - RED evidence: test failures before implementation
   - GREEN evidence: test passes after implementation
   - REFACTOR evidence: tests remain green after refactoring
   - Manual verification screenshots (if applicable)

### Files to Modify

1. **`docs/project-state.md`**
   - Update M4 progress
   - Add transcription capability
   - Note MediaTranscript model
   - Update Next Architectural Goal

### Verification Steps

1. **Verify evidence is complete**:
   - All test runs documented
   - All acceptance criteria verified
   - No outstanding issues

2. **Verify documentation is accurate**:
   - Project state reflects current capabilities
   - No outdated information

## Implementation Order

1. **Phase 1**: Transcription Engine Abstraction
   - Write RED tests first
   - Implement transcription module
   - Verify GREEN

2. **Phase 2**: Contract Schema Extension
   - Write RED tests first
   - Update schema
   - Verify GREEN

3. **Phase 3**: Python Worker Transcribe Action
   - Write RED tests first
   - Implement action
   - Verify GREEN

4. **Phase 4**: Python Worker CLI transcribe Subcommand
   - Write RED tests first
   - Implement CLI subcommand
   - Verify GREEN

5. **Phase 5**: Laravel MediaTranscript Model and Migration
   - Write RED tests first
   - Implement model and migration
   - Verify GREEN

6. **Phase 6**: Laravel ProcessMediaAction::transcribe()
   - Write RED tests first
   - Implement method
   - Verify GREEN

7. **Phase 7**: Laravel ProcessMediaAsset Job Transcription
   - Write RED tests first
   - Implement job extension
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
   - faster-whisper (runtime only, not required for tests)

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
   - MediaProcessingContract (Issue #45)
   - ProcessMediaAsset job (Issue #45, #49)
   - ProcessMediaAction service (Issue #47, #49)
   - Worker contract JSON schema (Issue #45)
   - Worker CLI with probe and extract-audio subcommands (Issue #47, #49)

## Risks and Mitigations

1. **faster-whisper version differences**:
   - Risk: Different versions may produce different transcription output
   - Mitigation: Pin version in production, use deterministic CI engine

2. **Timeout tuning**:
   - Risk: 300s default may be insufficient for very long audio
   - Mitigation: Make configurable via environment variable

3. **Model size constraints**:
   - Risk: Large models may exceed server memory
   - Mitigation: Make model configurable, default to small model

4. **CPU inference performance**:
   - Risk: CPU inference may be slow for long audio
   - Mitigation: Consider GPU support as future optimization

5. **Deterministic CI vs runtime divergence**:
   - Risk: CI uses deterministic fake, runtime uses real engine
   - Mitigation: Acceptable for testing infrastructure; integration tests use real engine

## Success Criteria

- All acceptance criteria from spec.md satisfied
- All existing tests pass unchanged
- New tests provide adequate coverage
- Code follows project conventions
- No security regressions
- Documentation updated
- CI passes with deterministic transcription engine
- Worker tests run in CI from the start
- Transcription engine abstraction is clean and replaceable
- MediaTranscript lifecycle is independent of MediaAsset processing states