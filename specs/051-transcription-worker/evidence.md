# Evidence: Deterministic Transcription Worker Stage

## Issue Reference

- **Issue**: #51
- **Title**: feat(media): add deterministic transcription worker stage
- **Branch**: @carlosegoulart/51/feat/transcription-worker
- **Date**: 2026-09-17
- **PR**: #52

## Blocker Fixes (External Review Corrections)

### Blocker 1: Failed transcription retry

**Problem**: MediaTranscript VALID_TRANSITIONS did not allow `failed -> transcribing`, so retrying a failed transcription was silently a no-op.

**Fix**: Added `self::STATUS_FAILED => [self::STATUS_TRANSCRIBING]` to VALID_TRANSITIONS in `MediaTranscript.php`. Also added `error => null` cleanup in `markTranscribing()`.

**Tests added**:
- `MediaTranscriptTest::test_failed_to_transcribing_is_valid` — verifies failed->transcribing transition works
- `ProcessMediaAssetTranscriptionTest::it_retries_failed_transcription_on_rerun` — verifies retry flow with FAILED transcript

### Blocker 2: Segment validation

**Problem**: Segment dataclass accepted negative start_ms, end_ms < start_ms, empty text, and transcripts with unordered/overlapping segments.

**Fix**: Added `__post_init__` to `Segment` dataclass with validation. Added `validate_transcript_result()` function called from both transcribers.

**Tests added** (7 new in `test_transcription_engine.py`):
- `test_segment_rejects_negative_start_ms`
- `test_segment_rejects_end_before_start`
- `test_segment_rejects_empty_text`
- `test_segment_rejects_whitespace_only_text`
- `test_transcript_result_rejects_unordered_segments`
- `test_transcript_result_rejects_overlapping_segments`
- `test_validate_transcript_result_passes_for_valid`

### Blocker 3: Genuine timeout

**Problem**: Timeout was read but never enforced. The `transcribe()` function made a direct synchronous call with no cancellation mechanism.

**Fix**: Wrapped transcription in `ThreadPoolExecutor` with `future.result(timeout=timeout)`. Returns error dict on `FuturesTimeoutError`.

**Test updated**:
- `test_transcribe_timeout_returns_error` — replaced with slow mock that sleeps 5s with 1s timeout, verifies error response

### Blocker 4: faster-whisper optional dependency

**Problem**: `faster-whisper` was not declared in `pyproject.toml` despite being imported by `FasterWhisperTranscriber`.

**Fix**: Added `transcription = ["faster-whisper>=1.0.0"]` to `[project.optional-dependencies]` in `pyproject.toml`.

### Blocker 5: FasterWhisper adapter tests

**Problem**: No mocked tests for `FasterWhisperTranscriber.transcribe()` adapter behavior.

**Tests added** (4 new in `test_transcription_engine.py`):
- `test_faster_whisper_transcribe_calls_model_transcribe` — verifies transcribe() delegates to model
- `test_faster_whisper_model_load_is_lazy` — verifies no model load on construction
- `test_faster_whisper_missing_dependency_raises_import_error` — verifies ImportError when faster-whisper missing
- `test_faster_whisper_model_error_propagates` — verifies RuntimeError propagation

### Blocker 6: Laravel response validation

**Problem**: `ProcessMediaAsset` accepted any worker response without validating transcription structure before persistence.

**Fix**: Added comprehensive validation in `ProcessMediaAsset.php`: checks transcription object exists, validates required fields (language, full_text, segments, engine, model), validates each segment's start_ms/end_ms/text. Throws `ProcessMediaException` on malformed response.

**Tests added** (3 new in `ProcessMediaAssetTranscriptionTest.php`):
- `it_rejects_malformed_worker_success_response_missing_transcription_object`
- `it_rejects_worker_success_response_with_missing_language`
- `it_rejects_worker_success_response_with_invalid_segment_timing`

### Blocker 7: Documentation

**Problem**: `docs/project-state.md` and `docs/roadmap.md` needed updates for M4 progress.

**Status**: BLOCKED by tool permissions — `docs/**` directory is not writable by the Builder agent's edit/write tools. Orchestrator must update these files manually:
- `docs/project-state.md`: Update Current Milestone to "M4 Video Understanding (in progress)", update Next Architectural Goal to scene detection, update Known Limitations
- `docs/roadmap.md`: Add "## M4 — Video Understanding (in progress)" section with Issue #51

## Implementation Summary

### Files Created (original Issue #51)

1. **services/worker/aiclip_worker/transcription.py**
   - Transcription engine abstraction
   - Segment dataclass with validation (start_ms, end_ms, text)
   - TranscriptResult dataclass
   - Transcriber abstract base class
   - DeterministicTranscriber (CI/testing)
   - FasterWhisperTranscriber (runtime)
   - get_transcriber() factory function
   - validate_transcript_result() function

2. **services/worker/aiclip_worker/actions/transcribe.py**
   - Transcribe action with ThreadPoolExecutor timeout enforcement
   - Engine selection via environment variable

3. **services/worker/tests/test_transcription_engine.py**
   - 20 tests for engine abstraction + segment validation + FasterWhisper adapter

4. **services/worker/tests/test_transcribe.py**
   - 10 tests for transcribe action including genuine timeout test

5. **services/worker/tests/test_cli_transcribe.py**
   - 11 tests for CLI subcommand

6. **apps/api/app/Models/MediaTranscript.php**
   - Eloquent model with status transitions (including failed->transcribing retry)
   - belongsTo(MediaAsset), belongsTo(DerivedAsset)

7. **apps/api/database/migrations/2026_09_17_000000_create_media_transcripts_table.php**
   - Migration with unique constraint on media_asset_id

8. **apps/api/tests/Feature/Models/MediaTranscriptTest.php**
   - 11 tests for model including retry transition

9. **apps/api/tests/Feature/Jobs/ProcessMediaAssetTranscriptionTest.php**
   - 9 tests for job transcription stage including retry and validation

### Files Modified (original Issue #51 + blocker fixes)

1. **services/worker/contracts/media_processing_v1.json**
   - Added "transcribe" to action enum
   - Added derived_asset_id field

2. **services/worker/aiclip_worker/cli.py**
   - Added transcribe subcommand

3. **services/worker/tests/conftest.py**
   - Added transcribe fixtures

4. **apps/api/app/Models/MediaAsset.php**
   - Added transcript() HasOne relationship

5. **apps/api/app/Contracts/MediaProcessingContract.php**
   - Added derivedAssetId field
   - Added transcribe action validation

6. **apps/api/app/Services/ProcessMediaAction.php**
   - Added transcribe() method

7. **apps/api/app/Jobs/ProcessMediaAsset.php**
   - Added transcription stage with comprehensive response validation

8. **apps/api/config/media.php**
   - Added transcribe_timeout_seconds config

9. **apps/api/tests/Feature/Jobs/ProcessMediaAssetAudioExtractionTest.php**
   - Updated to mock transcribe

10. **services/worker/pyproject.toml**
    - Added faster-whisper optional transcription dependency

## TDD Evidence

### RED Phase

Tests written first for all blocker fixes:
- Segment validation (negative start, end<start, empty text, whitespace)
- Transcript validation (unordered, overlapping segments)
- Failed transcription retry (MediaTranscript lifecycle)
- Genuine timeout (ThreadPoolExecutor-based)
- FasterWhisper adapter mocking
- Laravel response validation (missing transcription, missing language, invalid segments)

### GREEN Phase

Implementation added to make all tests pass:
- MediaTranscript VALID_TRANSITIONS updated with failed->transcribing
- Segment.__post_init__ validation
- validate_transcript_result() function
- ThreadPoolExecutor timeout in transcribe action
- ProcessMediaAsset comprehensive response validation
- faster-whisper optional dependency declared

### REFACTOR Phase

- Pint applied to all PHP files (auto-fixed style)
- `implode()` spacing fixed by Pint

## Architecture Invariants Verified

1. ✅ Laravel remains authoritative for PostgreSQL
2. ✅ Python worker MUST NOT write PostgreSQL
3. ✅ Binary media in S3-compatible storage
4. ✅ Original uploads remain immutable
5. ✅ Worker uses subprocess-safe APIs
6. ✅ No secrets in logs or worker payloads
7. ✅ Transcription engine abstraction exists
8. ✅ Deterministic CI implementation (no model downloads)
9. ✅ Faster-whisper runtime adapter configurable
10. ✅ MediaTranscript lifecycle independent of MediaAsset states
11. ✅ Segment validation enforces data integrity
12. ✅ Transcription timeout genuinely enforced
13. ✅ Response validation before persistence

## Acceptance Criteria Verification

1. ✅ Python worker CLI supports transcribe subcommand
2. ✅ Worker accepts contract with action: "transcribe" and derived_asset_id
3. ✅ Worker returns structured transcription result
4. ✅ Worker returns structured error on failure
5. ✅ Worker enforces timeout (ThreadPoolExecutor)
6. ✅ Worker uses subprocess-safe APIs
7. ✅ Transcription engine abstraction with deterministic CI implementation
8. ✅ Faster-whisper runtime adapter configurable
9. ✅ MediaTranscript model with correct schema and retry transitions
10. ✅ ProcessMediaAsset chains probe → audio extraction → transcription
11. ✅ Idempotency: duplicate transcription produces single transcript
12. ✅ Failed transcription can be retried
13. ✅ Response validation catches malformed worker output
14. ✅ Segment validation enforces ordering and non-overlap
15. ✅ Python worker never writes PostgreSQL
16. ✅ No credentials or audio leaked to logs
17. ✅ Worker tests: deterministic, no model downloads
18. ✅ faster-whisper declared as optional dependency

## Blocker Fix Summary

| Blocker | Status | Tests | Implementation |
|---------|--------|-------|----------------|
| 1. Failed transcription retry | ✅ Fixed | 2 tests | MediaTranscript VALID_TRANSITIONS + markTranscribing |
| 2. Segment validation | ✅ Fixed | 7 tests | Segment.__post_init__ + validate_transcript_result() |
| 3. Genuine timeout | ✅ Fixed | 1 test updated | ThreadPoolExecutor in transcribe action |
| 4. faster-whisper optional dep | ✅ Fixed | N/A | pyproject.toml optional-dependencies |
| 5. FasterWhisper adapter tests | ✅ Fixed | 4 tests | Mocked adapter tests |
| 6. Laravel response validation | ✅ Fixed | 3 tests | ProcessMediaAsset comprehensive validation |
| 7. Documentation | ⚠️ Blocked | N/A | Tool permissions prevent docs/** edits |
