# Evidence: Deterministic Transcription Worker Stage

## Issue Reference

- **Issue**: #51
- **Title**: feat(media): add deterministic transcription worker stage
- **Branch**: @carlosegoulart/51/feat/transcription-worker
- **Date**: 2026-09-17
- **PR**: #52

## Independent Tester Verification

### Blocker Verification

#### A) Failed transcription retry — PASS
- `MediaTranscript::VALID_TRANSITIONS` includes `self::STATUS_FAILED => [self::STATUS_TRANSCRIBING]` (MediaTranscript.php line 40)
- `markTranscribing()` sets `'error' => null` (MediaTranscript.php line 87)
- Test `test_failed_to_transcribing_is_valid` exists (MediaTranscriptTest.php line 248)
- Test `it_retries_failed_transcription_on_rerun` exists (ProcessMediaAssetTranscriptionTest.php line 349)

#### B) Segment validation — PASS
- `Segment.__post_init__` validates: start_ms is int, end_ms is int, start_ms >= 0, end_ms >= start_ms, text is str, text stripped and non-empty (transcription.py lines 20-33)
- `validate_transcript_result()` validates ordering and no overlap (transcription.py lines 209-230)
- All 7 required tests exist in test_transcription_engine.py

#### C) Timeout — PASS
- Test `test_transcribe_timeout_returns_error` (test_transcribe.py line 209) uses a genuinely slow mock
- Subprocess isolation now implemented with deadline-bounded poll and SIGTERM/SIGKILL teardown

#### D) faster-whisper packaging — PASS
- `pyproject.toml` contains `transcription = ["faster-whisper>=1.2.1,<1.3.0"]`

#### E) FasterWhisper adapter tests — PASS
- MagicMock tests for delegation, lazy load, missing dep, error propagation

#### F) Laravel validation — PASS
- ProcessMediaAsset validates transcription fields before persistence
- `empty("0")` bug fixed: replaced with `trim($seg['text']) === ''`
- Cross-segment ordering and overlap validation added

#### G) Documentation — PASS
- project-state.md and roadmap.md updated

#### H) Evidence — PASS
- Evidence file now contains clear Decision marker

## Blocker Fix Summary

| Blocker | Status | Tests | Implementation |
|---------|--------|-------|----------------|
| 1. Failed transcription retry | FIXED | 2 tests | MediaTranscript VALID_TRANSITIONS + markTranscribing error clear |
| 2. Segment validation | FIXED | 7 tests | Segment.__post_init__ + validate_transcript_result() |
| 3. Genuine timeout | FIXED | 1 test updated | Subprocess supervisor with deadline-bounded poll and process-group kill |
| 4. faster-whisper optional dep | FIXED | N/A | pyproject.toml: `>=1.2.1,<1.3.0` |
| 5. FasterWhisper adapter tests | FIXED | 4 tests | MagicMock imported; delegation, lazy load, missing dep, error propagation |
| 6. Laravel response validation | FIXED | 3 tests | Comprehensive validation; empty() bug fixed; segment ordering/overlap check added |
| 7. Documentation | FIXED | N/A | project-state.md + roadmap.md updated |
| 8. Evidence/metadata | FIXED | N/A | Updated by independent Tester review |

## TDD Evidence

### RED

Tests written and verified failing before implementation:

#### Python Worker
- `test_transcribe_timeout_is_real_wall_clock` — FAILS because ThreadPoolExecutor does not enforce wall-clock bound
- `test_transcribe_timeout_kills_child` — FAILS because no subprocess or process-group kill exists
- `test_faster_whisper_missing_language_raises_error` — FAILS because `info.language or "en"` defaults silently

#### Laravel
- `it('accepts segment text "0" as valid')` — FAILS because `empty("0")` is true in PHP
- `it('rejects unordered segments from worker')` — FAILS because no cross-segment ordering check exists
- `it('rejects overlapping segments from worker')` — FAILS because no cross-segment overlap check exists

### GREEN

All tests pass on HEAD `af64275`:

| Suite | Tool | Collected | Passed | Failed | Duration |
|-------|------|-----------|--------|--------|----------|
| Worker | pytest | 107 | 107 | 0 | 6.71s |
| Laravel | Pest/PHPUnit | 249 | 249 | 0 | 12.93s (1561 assertions) |
| Frontend | Vitest | pass | — | — | — |
| E2E | Playwright | pass | — | — | — |

CI checks (all GREEN): `governance`, `pr-enforcement`, `test`, `tests`, `e2e`

### REFACTOR

- Extracted `_kill_process_group()` helper for bounded process teardown
- No behavior changes; all tests remain green

## Acceptance Criteria Verification

1. Python worker CLI supports transcribe subcommand ✅
2. Worker accepts contract with action: "transcribe" and derived_asset_id ✅
3. Worker returns structured transcription result ✅
4. Worker returns structured error on failure ✅
5. Worker enforces timeout via subprocess isolation with process-group kill ✅
6. Worker uses subprocess-safe APIs ✅
7. Transcription engine abstraction with deterministic CI implementation ✅
8. Faster-whisper runtime adapter configurable ✅
9. MediaTranscript model with correct schema and retry transitions ✅
10. ProcessMediaAsset chains probe -> audio extraction -> transcription ✅
11. Idempotency: duplicate transcription produces single transcript ✅
12. Failed transcription can be retried ✅
13. Response validation catches malformed worker output ✅
14. Segment validation enforces ordering and non-overlap ✅
15. Python worker never writes PostgreSQL ✅
16. No credentials or audio leaked to logs ✅
17. Worker tests: deterministic, no model downloads ✅
18. faster-whisper declared as optional dependency ✅

## Architecture Invariants Verified

1. Laravel remains authoritative for PostgreSQL ✅
2. Python worker MUST NOT write PostgreSQL ✅
3. Binary media in S3-compatible storage ✅
4. Original uploads remain immutable ✅
5. Worker uses subprocess-safe APIs ✅
6. No secrets in logs or worker payloads ✅
7. Transcription engine abstraction exists ✅
8. Deterministic CI implementation (no model downloads) ✅
9. Faster-whisper runtime adapter configurable ✅
10. MediaTranscript lifecycle independent of MediaAsset states ✅
11. Segment validation enforces data integrity ✅
12. Transcription timeout genuinely enforced via subprocess isolation ✅
13. Response validation before persistence ✅

Decision: APPROVE
