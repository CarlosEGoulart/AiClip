# Evidence: Deterministic Transcription Worker Stage

## Issue Reference

- **Issue**: #51
- **Title**: feat(media): add deterministic transcription worker stage
- **Branch**: @carlosegoulart/51/feat/transcription-worker
- **Date**: 2026-09-17
- **PR**: #52

## Implementation Summary

### Files Created

1. **services/worker/aiclip_worker/transcription.py**
   - Transcription engine abstraction
   - Segment dataclass (start_ms, end_ms, text)
   - TranscriptResult dataclass
   - Transcriber abstract base class
   - DeterministicTranscriber (CI/testing)
   - FasterWhisperTranscriber (runtime)
   - get_transcriber() factory function

2. **services/worker/aiclip_worker/actions/transcribe.py**
   - Transcribe action with timeout enforcement
   - Engine selection via environment variable

3. **services/worker/tests/test_transcription_engine.py**
   - 16 tests for engine abstraction

4. **services/worker/tests/test_transcribe.py**
   - 10 tests for transcribe action

5. **services/worker/tests/test_cli_transcribe.py**
   - 11 tests for CLI subcommand

6. **apps/api/app/Models/MediaTranscript.php**
   - Eloquent model with status transitions
   - belongsTo(MediaAsset), belongsTo(DerivedAsset)

7. **apps/api/database/migrations/2026_09_17_000000_create_media_transcripts_table.php**
   - Migration with unique constraint on media_asset_id

8. **apps/api/tests/Feature/Models/MediaTranscriptTest.php**
   - 10 tests for model

9. **apps/api/tests/Feature/Jobs/ProcessMediaAssetTranscriptionTest.php**
   - 6 tests for job transcription stage

### Files Modified

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
   - Added transcription stage after audio extraction

8. **apps/api/config/media.php**
   - Added transcribe_timeout_seconds config

9. **apps/api/tests/Feature/Jobs/ProcessMediaAssetAudioExtractionTest.php**
   - Updated to mock transcribe

## TDD Evidence

### RED Phase

Tests written first for:
- Transcription engine abstraction
- Transcribe action logic
- CLI transcribe subcommand
- MediaTranscript model
- ProcessMediaAsset transcription stage

All tests failed initially due to missing implementation.

### GREEN Phase

Implementation added to make tests pass:
- Created transcription module with engine abstraction
- Extended contract schema with transcribe action
- Added transcribe CLI subcommand
- Created MediaTranscript model and migration
- Added transcribe() method to ProcessMediaAction
- Extended ProcessMediaAsset job with transcription stage

### REFACTOR Phase

Code reviewed and refactored:
- Extracted handleTranscription() method in ProcessMediaAsset
- Added proper error handling and logging
- Ensured subprocess safety

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

## Acceptance Criteria Verification

1. ✅ Python worker CLI supports transcribe subcommand
2. ✅ Worker accepts contract with action: "transcribe" and derived_asset_id
3. ✅ Worker returns structured transcription result
4. ✅ Worker returns structured error on failure
5. ✅ Worker enforces timeout
6. ✅ Worker uses subprocess-safe APIs
7. ✅ Transcription engine abstraction with deterministic CI implementation
8. ✅ Faster-whisper runtime adapter configurable
9. ✅ MediaTranscript model with correct schema
10. ✅ ProcessMediaAsset chains probe → audio extraction → transcription
11. ✅ Idempotency: duplicate transcription produces single transcript
12. ✅ Python worker never writes PostgreSQL
13. ✅ No credentials or audio leaked to logs
14. ✅ Worker tests: deterministic, no model downloads
15. ✅ All existing tests remain green

## Known Limitations

1. Real faster-whisper model not tested in CI (deterministic engine used)
2. Feature tests require PostgreSQL (CI only)
3. Model download occurs only at runtime, not in CI

## CI Verification

- **PR**: #52
- **All 5 required checks**: SUCCESS
- **Backend CI** (run 35165592691): Worker tests + Laravel tests — 1m24s
- **Frontend CI** (run 35165592724): Frontend tests — 31s
- **E2E CI** (run 35165592856): E2E tests + screenshots — 2m13s
- **Governance** (run 35165592632): pr-enforcement + governance — both GREEN
- **PR enforcement**: Closes #51 validated, SDD bundle complete, TDD sections present, APPROVE decision present

## Decision

Decision: APPROVE
