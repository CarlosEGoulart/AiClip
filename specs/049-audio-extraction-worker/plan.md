# Implementation Plan: Deterministic Audio Extraction Worker

## Phase 1: Python Worker: ExtractAudio Action

### Objective
Create the FFmpeg-based audio extraction action in the Python worker.

### Files to Create

1. **`services/worker/aiclip_worker/actions/extract_audio.py`**
   - `extract_audio(contract: dict) -> dict` function
   - FFmpeg invocation via `subprocess.run` with list arguments
   - Deterministic FFmpeg flags: `-y -i <input> -vn -acodec pcm_s16le -ar 16000 -ac 1 <output>`
   - Timeout enforcement (configurable via `EXTRACT_AUDIO_TIMEOUT_SECONDS` env var, default 120)
   - Temporary file management with job-isolated directory
   - Cleanup on success and failure
   - Return structured extraction result or error

### Files to Modify

None.

### Verification Steps

1. **Unit tests for extract_audio logic** (RED first):
   - Create test fixture: small valid MP4 with audio
   - Test successful extraction produces valid WAV file
   - Test output is mono 16 kHz PCM WAV
   - Test error handling for non-existent file
   - Test error handling for corrupt file
   - Test error handling for video without audio
   - Test timeout enforcement
   - Test temporary file cleanup on success
   - Test temporary file cleanup on failure

## Phase 2: Contract Schema Extension

### Objective
Extend the worker contract schema to support the `extract_audio` action and output storage.

### Files to Modify

1. **`services/worker/contracts/media_processing_v1.json`**
   - Add `action` field: string, enum `["probe", "extract_audio"]`, optional (default "probe")
   - Add `output_storage` field: object with `disk`, `key`, `mime_type` (required for extract_audio)
   - Maintain backward compatibility: existing probe contracts without `action` field still validate

### Files to Create

None.

### Verification Steps

1. **Schema validation tests** (RED first):
   - Test existing probe contract (no `action` field) still validates
   - Test contract with `action: "probe"` validates
   - Test contract with `action: "extract_audio"` and `output_storage` validates
   - Test contract with `action: "extract_audio"` without `output_storage` fails validation
   - Test contract with unknown action fails validation

## Phase 3: Python Worker CLI: extract-audio Subcommand

### Objective
Add the `extract-audio` subcommand to the worker CLI.

### Files to Modify

1. **`services/worker/aiclip_worker/cli.py`**
   - Add `extract-audio` subcommand with `--contract-json` and `--contract-file` arguments
   - Add `_handle_extract_audio(args)` function
   - Validate contract for extract_audio action
   - Invoke `extract_audio()` action
   - Return exit codes: 0 (success), 1 (processing error), 2 (invalid input)

### Verification Steps

1. **CLI integration tests** (RED first):
   - Test CLI with valid extract_audio contract via stdin
   - Test CLI with valid extract_audio contract via file
   - Test CLI with invalid contract (missing output_storage)
   - Test CLI with probe action on extract-audio subcommand (mismatch)
   - Test exit codes (0, 1, 2)

## Phase 4: Laravel: DerivedAsset Model and Migration

### Objective
Create the DerivedAsset model and migration for storing derivative asset metadata.

### Files to Create

1. **`apps/api/app/Models/DerivedAsset.php`**
   - Eloquent model with fillable fields
   - `TYPE_AUDIO_NORMALIZED` constant
   - `belongsTo(MediaAsset::class)` relationship
   - Casts for integer fields

2. **`apps/api/database/migrations/YYYY_MM_DD_HHMMSS_create_derived_assets_table.php`**
   - Creates `derived_assets` table
   - Columns: id, media_asset_id (FK), type, storage_disk, storage_key, mime_type, size_bytes, duration_ms, sample_rate, channels, codec, timestamps
   - Unique constraint on `(media_asset_id, type)`
   - Foreign key to `media_assets.id` with cascade delete

### Files to Modify

1. **`apps/api/app/Models/MediaAsset.php`**
   - Add `derivedAssets(): HasMany` relationship method

### Verification Steps

1. **Unit tests for DerivedAsset model** (RED first):
   - Test DerivedAsset can be created with valid data
   - Test DerivedAsset belongs to MediaAsset
   - Test MediaAsset has many DerivedAssets
   - Test unique constraint on (media_asset_id, type)
   - Test cascade delete (MediaAsset deletion removes DerivedAssets)

## Phase 5: Laravel: ProcessMediaAction::extractAudio()

### Objective
Add the `extractAudio()` method to ProcessMediaAction service.

### Files to Modify

1. **`apps/api/app/Services/ProcessMediaAction.php`**
   - Add `extractAudio(MediaProcessingContract $contract): array` method
   - Uses `Symfony\Component\Process\Process` for subprocess invocation
   - Invokes worker CLI: `python -m aiclip_worker.cli extract-audio --contract-json <json>`
   - Enforces timeout (configurable via `media.extract_audio_timeout_seconds`, default 120)
   - Throws `ProcessMediaException` on failure

2. **`apps/api/config/media.php`**
   - Add `extract_audio_timeout_seconds` configuration (default 120)

### Verification Steps

1. **Unit tests for ProcessMediaAction::extractAudio()** (RED first):
   - Mock `Process` to simulate successful extraction
   - Mock `Process` to simulate FFmpeg failure
   - Mock `Process` to simulate timeout
   - Verify contract JSON passed correctly
   - Verify extraction result parsed correctly

## Phase 6: Laravel: ProcessMediaAsset Job: Audio Extraction Stage

### Objective
Extend the ProcessMediaAsset job to chain probe → audio extraction.

### Files to Modify

1. **`apps/api/app/Jobs/ProcessMediaAsset.php`**
   - After probe succeeds, check if `audio_codec` is not null in probe result
   - If audio exists:
     a. Check for existing `audio_normalized` DerivedAsset (idempotency)
     b. If exists: skip extraction, mark as `completed`
     c. If not exists: invoke `ProcessMediaAction::extractAudio()` with output storage
     d. On success: create `DerivedAsset` record, mark as `completed`
     e. On failure: mark as `failed`
   - If no audio: mark as `completed` (no audio to extract)

2. **`apps/api/app/Contracts/MediaProcessingContract.php`**
   - Add `action` field (optional, defaults to "probe")
   - Add `outputStorage` field (optional, for extract_audio action)
   - Update `toArray()` to include new fields
   - Update `fromMediaAsset()` to support extract_audio action

### Verification Steps

1. **Feature tests for ProcessMediaAsset audio extraction** (RED first):
   - Test job chains probe → extraction on success
   - Test job creates DerivedAsset on successful extraction
   - Test job marks completed when no audio stream exists
   - Test job is idempotent (skips if DerivedAsset exists)
   - Test job marks failed on extraction error
   - Test job handles probe failure (does not reach extraction)

## Phase 7: Deterministic Unit Tests for Worker

### Objective
Create comprehensive unit tests for the Python worker extract_audio logic.

### Files to Create

1. **`services/worker/tests/test_extract_audio.py`**
   - Test successful extraction produces valid WAV file
   - Test output is mono 16 kHz PCM WAV
   - Test extraction with video without audio returns error
   - Test extraction with non-existent file returns error
   - Test extraction with corrupt file returns error
   - Test timeout enforcement
   - Test FFmpeg not found error
   - Test temporary file cleanup on success
   - Test temporary file cleanup on failure

2. **`services/worker/tests/test_cli_extract_audio.py`**
   - Test CLI with valid extract_audio contract via stdin
   - Test CLI with valid extract_audio contract via file
   - Test CLI with invalid contract (missing output_storage)
   - Test CLI with probe action on extract-audio subcommand
   - Test exit codes (0, 1, 2)

### Files to Modify

1. **`services/worker/tests/conftest.py`**
   - Add `sample_contract_extract_audio` fixture
   - Add `sample_contract_extract_audio_no_output` fixture
   - Add `sample_contract_video_no_audio` fixture (if not exists)

### Verification Steps

1. **Run pytest**:
   ```bash
   cd services/worker
   python -m pytest tests/ -v
   ```

2. **Verify all tests pass**:
   - No skipped tests (except fixture-dependent)
   - No flaky tests
   - All assertions are deterministic

## Phase 8: Deterministic Feature Tests for Laravel

### Objective
Create feature tests for the DerivedAsset model and ProcessMediaAsset audio extraction.

### Files to Create

1. **`apps/api/tests/Feature/Models/DerivedAssetTest.php`**
   - Test DerivedAsset creation
   - Test DerivedAsset belongs to MediaAsset
   - Test MediaAsset has many DerivedAssets
   - Test unique constraint enforcement
   - Test cascade delete

2. **`apps/api/tests/Feature/Jobs/ProcessMediaAssetAudioExtractionTest.php`**
   - Test job chains probe → extraction
   - Test job creates DerivedAsset
   - Test job marks completed when no audio
   - Test job is idempotent
   - Test job marks failed on extraction error

### Verification Steps

1. **Run Pest tests**:
   ```bash
   cd apps/api
   php artisan test --filter=DerivedAssetTest
   php artisan test --filter=ProcessMediaAssetAudioExtractionTest
   ```

2. **Verify all tests pass**:
   - No database issues
   - No queue issues
   - No mocking issues

## Phase 9: CI Integration

### Objective
Ensure CI can run Python worker tests and Laravel tests with worker invocation.

### Files to Modify

1. **`.github/workflows/backend.yml`**
   - Add Python setup step (if not exists)
   - Add FFmpeg installation (deterministically pinned)
   - Add Python dependencies installation for worker
   - Add worker test step (`python -m pytest tests/ -v`)
   - Ensure worker tests run BEFORE Laravel tests
   - Add extract_audio timeout configuration

### Verification Steps

1. **Push to branch and verify CI passes**:
   - All checks green
   - Worker tests pass in CI
   - Laravel tests pass in CI
   - No flaky tests

## Phase 10: Evidence Collection and Documentation

### Objective
Document implementation evidence and update project state.

### Files to Create

1. **`specs/049-audio-extraction-worker/evidence.md`**
   - RED evidence: test failures before implementation
   - GREEN evidence: test passes after implementation
   - REFACTOR evidence: tests remain green after refactoring
   - Manual verification screenshots (if applicable)

### Files to Modify

1. **`docs/project-state.md`**
   - Update M3 progress
   - Add audio extraction capability
   - Note DerivedAsset model

### Verification Steps

1. **Verify evidence is complete**:
   - All test runs documented
   - All acceptance criteria verified
   - No outstanding issues

2. **Verify documentation is accurate**:
   - Project state reflects current capabilities
   - No outdated information

## Implementation Order

1. **Phase 1**: Python Worker ExtractAudio Action
   - Write RED tests first
   - Implement worker logic
   - Verify GREEN

2. **Phase 2**: Contract Schema Extension
   - Write RED tests first
   - Update schema
   - Verify GREEN

3. **Phase 3**: Python Worker CLI extract-audio Subcommand
   - Write RED tests first
   - Implement CLI subcommand
   - Verify GREEN

4. **Phase 4**: Laravel DerivedAsset Model and Migration
   - Write RED tests first
   - Implement model and migration
   - Verify GREEN

5. **Phase 5**: Laravel ProcessMediaAction::extractAudio()
   - Write RED tests first
   - Implement method
   - Verify GREEN

6. **Phase 6**: Laravel ProcessMediaAsset Job Audio Extraction
   - Write RED tests first
   - Implement job extension
   - Verify GREEN

7. **Phase 7**: Deterministic Unit Tests for Worker
   - Write tests first
   - Ensure they pass with worker implementation
   - Verify coverage

8. **Phase 8**: Deterministic Feature Tests for Laravel
   - Write tests first
   - Ensure they pass with job implementation
   - Verify coverage

9. **Phase 9**: CI Integration
   - Update workflows
   - Verify CI passes

10. **Phase 10**: Evidence Collection and Documentation
    - Collect all evidence
    - Update documentation

## Dependencies

1. **Python Environment**:
   - Python 3.10+
   - pytest
   - jsonschema (for contract validation)

2. **Laravel Environment**:
   - PHP 8.2+
   - Laravel 13
   - Pest testing framework
   - Symfony Process component

3. **CI Environment**:
   - FFmpeg binary (deterministically pinned)
   - Python runtime
   - MinIO (existing)

4. **Existing Code**:
   - MediaAsset model (Issue #35)
   - MediaProcessingContract (Issue #45)
   - ProcessMediaAsset job (Issue #45)
   - ProcessMediaAction service (Issue #47)
   - Worker contract JSON schema (Issue #45)
   - Worker CLI with probe subcommand (Issue #47)

## Risks and Mitigations

1. **FFmpeg version differences**:
   - Risk: Different FFmpeg versions may produce different WAV output
   - Mitigation: Use deterministic flags, pin version in CI, test with specific version

2. **Timeout tuning**:
   - Risk: 120s default may be insufficient for very large files
   - Mitigation: Make configurable via environment variable

3. **Storage access in worker**:
   - Risk: Worker needs read/write access to S3-compatible storage
   - Mitigation: Use environment variables for credentials, do not log them

4. **Large file handling**:
   - Risk: Temporary files may consume significant disk space
   - Mitigate with bounded cleanup and job-isolated directories

5. **No audio stream**:
   - Risk: Video without audio should not be an error
   - Mitigate by checking probe result and marking completed without extraction

## Success Criteria

- All acceptance criteria from spec.md satisfied
- All existing tests pass unchanged
- New tests provide adequate coverage
- Code follows project conventions
- No security regressions
- Documentation updated
- CI passes with deterministic FFmpeg pinning
- Worker tests run in CI from the start
