# Test Plan: Issue #76 — M6.2 Caption Burn-in Slice 1

## Authority

This test plan derives exclusively from `specs/76-m2-caption-burnin-rebuilt/spec.md` and `specs/76-m2-caption-burnin-rebuilt/plan.md`. Tester executes; Planner owns this file.

## Test Scope

### In scope (M6.2 Slice 1 atomic):
- FFmpeg `subtitles` filter integration into vertical clip renderer filter graph
- SRT caption file path via optional `caption_file` contract field
- Caption burn-in positioned after vertical reframe pipeline (crop → scale → pad → fps → subtitles → encode)
- Backward compatibility: when `caption_file` absent, output identical to M6.1 baseline
- All readiness/failure cases for M5 upstream (unchanged from M6.1)
- Idempotent reuse, version conflict, concurrency protection (unchanged from M6.1)
- Explicit invocation — no automatic ProcessMediaAsset integration (unchanged from M6.1)

### Out of scope (explicit — M6.2 Slice 1 only):
- AI-powered auto-caption generation (caption files are externally provided SRT)
- Configurable focal point or positioning UI for captions
- Multi-language caption support (English-only SRT)
- Caption styling (font, size, color) — FFmpeg libsubtitles defaults
- Transcript segment handling or auto-generation
- Frontend UI for caption editing or preview
- General queue redesign, unrelated refactors, governance/control-plane changes

## Test Categories

### 1. Worker Contract Validation (Python)

#### 1.1 Caption Contract Validation — `test_render_clip_caption_contract.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-WCC-01 | Valid request with `caption_file` pointing to valid SRT file | Pass validation; caption_file passed to renderer |
| TC-WCC-02 | Valid request without `caption_file` field | Pass validation; no caption filter applied (baseline behavior) |
| TC-WCC-03 | Valid request with `caption_file: null` | Pass validation; no caption filter applied (baseline behavior) |
| TC-WCC-04 | Valid request with `caption_file` pointing to non-existent SRT file | Fail: `invalid_contract` or `invalid_input` — file not found |
| TC-WCC-05 | Missing `version` field | Fail: `invalid_contract` |
| TC-WCC-06 | Invalid `version` (not "1.0.0") | Fail: `invalid_contract` |
| TC-WCC-07 | Invalid `action` (not "render_clip") | Fail: `invalid_contract` |
| TC-WCV-08 (existing) | Missing/invalid `media.duration_ms` | Fail: `invalid_contract` |
| TC-WCV-09 (existing) | Missing or negative/non-integer root `candidate_index` | Fail: `invalid_contract` |
| TC-WCV-10 (existing) | Missing `candidate` or missing `candidate.start_ms`/`candidate.end_ms` | Fail: `invalid_contract` |
| TC-WCV-11 (existing) | Candidate bounds invalid: start < 0, end <= start, or end > media.duration_ms | Fail: `invalid_contract` |
| TC-WCV-12 (existing) | Worker request contains `recommendation`, `recommendation_id`, `project_id`, or `media_asset_id` | Fail: `invalid_contract` |
| TC-WCV-13 (existing) | Worker request contains a second candidate index inside `candidate` | Fail: `invalid_contract` |
| TC-WCV-14 (existing) | Missing `configuration` object | Fail: `invalid_contract` |
| TC-WCV-15 (existing) | Invalid config: `target_width` odd, >4096, <1 | Fail: `invalid_contract` |
| TC-WCV-16 (existing) | Invalid config: `target_height` odd, >4096, <1 | Fail: `invalid_contract` |
| TC-WCV-17 (existing) | Invalid config: `target_fps` out of bounds | Fail: `invalid_contract` |
| TC-WCV-18 (existing) | Invalid config: `video_codec` not in enum | Fail: `invalid_contract` |
| TC-WCV-19 (existing) | Invalid config: `video_bitrate_kbps` out of bounds | Fail: `invalid_contract` |
| TC-WCV-20 (existing) | Invalid config: `audio_codec` not in enum | Fail: `invalid_contract` |
| TC-WCV-21 (existing) | Invalid config: `audio_bitrate_kbps` out of bounds | Fail: `invalid_contract` |
| TC-WCV-22 (existing) | Missing/invalid `source_media` or `output_storage` | Fail: `invalid_contract` |
| TC-WCV-23 (existing) | Unknown field or input size > 8388608 bytes | Fail: `invalid_contract` |
| TC-WCV-24 (existing) | Valid response with exactly one rendered clip and required output metadata | Pass validation |
| TC-WCV-25 (existing) | Response missing/wrong `algorithm` or `algorithm_version` | Fail: `invalid_contract` |
| TC-WCV-26 (existing) | Response `clips` not array or length != 1 | Fail: `invalid_contract` |
| TC-WCV-27 (existing) | Result candidate_index or bounds differ from validated request | Fail: `invalid_contract` |
| TC-WCV-28 (existing) | Clip missing required output fields or result invents semantic authority fields | Fail: `invalid_contract` |
| TC-WCV-29 (existing) | NaN/Infinity in any numeric field (request or response) | Fail: `invalid_contract` |

### 2. Filter Graph Construction (Python)

#### 2.1 Filter Graph with Subtitles — `test_ffmpeg_vertical_renderer_caption.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-FGR-CAP-01 | Filter graph construction with `caption_file` present | Filter graph string contains `subtitles={path}` after `fps=30` and before video encode filter |
| TC-FGR-CAP-02 | Filter graph construction without `caption_file` (absent) | Filter graph string identical to M6.1 baseline — no `subtitles` filter present |
| TC-FGR-CAP-03 | Filter graph construction without `caption_file` (null) | Filter graph string identical to M6.1 baseline — no `subtitles` filter present |
| TC-FGR-CAP-04 | Filter graph order verification: crop → scale → pad → fps → subtitles(optional) → encode | Filter graph parts appear in correct sequence; subtitles filter (when present) after visual transforms and before encode |

### 3. CLI Transport (Python)

#### 3.1 CLI with Caption File — `test_cli_render_clip_caption.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-CLI-CAP-01 | Valid stdin JSON with `caption_file` → stdout JSON success envelope, exit 0 | Success when caption_file present |
| TC-CLI-CAP-02 | Valid stdin JSON without `caption_file` → stdout JSON success envelope, exit 0 | Success when caption_file absent (baseline) |
| TC-CLI-CAP-03 | Valid stdin JSON with `caption_file: null` → stdout JSON success envelope, exit 0 | Success when caption_file null (baseline) |
| TC-CLI-CAP-04 | Invalid JSON on stdin → exit 2, error envelope | `invalid_contract` |
| TC-CLI-CAP-05 | Runtime error (FFmpeg fail) → exit 1 | `render_failed` |

### 4. Real FFmpeg Integration (Python)

#### 4.1 End-to-End Render with Captions — `test_render_clip_integration_caption.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-E2E-CAP-01 | Real FFmpeg on fixture horizontal video (1920x1080, 30s, audio) with valid SRT caption file | Output 1080x1920 MP4 with burned-in captions (FFprobe confirms subtitle stream) |
| TC-E2E-CAP-02 | Output duration matches candidate bounds (±50ms) | Duration within tolerance (applies to total output including caption overlay) |
| TC-E2E-CAP-03 | Output has video codec libx264, audio codec aac | Probe confirms |
| TC-E2E-CAP-04 | Output bitrate matches configuration (±10%) | Probe confirms |
| TC-E2E-CAP-05 | Output file size > 0 | Confirmed |
| TC-E2E-CAP-06 | Filter graph recorded in result parameters | Non-empty string (includes `subtitles=` when caption_file present) |
| TC-E2E-CAP-07 | FFmpeg version recorded | Non-empty string |
| TC-E2E-CAP-08 | Source media metadata in parameters | Matches probe |
| TC-E2E-CAP-09 | Limits object present with correct values | Matches spec |
| TC-E2E-CAP-10 | Laravel supplies a distinct output_storage key | Worker writes/finalizes exactly the supplied key and does not derive a new one |

**Fixture:** `tests/fixtures/render_source.mp4` — 1920x1080, 30s, H.264/AAC, horizontal content suitable for center-crop.  
**Fixture:** `tests/fixtures/caption_test.srt` — Simple SRT file with a few caption lines for testing burn-in.

### 5. Laravel Unit Tests

#### 5.1 RenderValidator — `RenderValidatorTest.php` (updates for optional field)

| Test ID | Scenario | Expected |
|---|---|---|
| TC-LRV-01 (existing) | `request()` accepts valid render contract | Returns validated array |
| TC-LRV-02 (existing) | `request()` rejects missing candidate_index | Exception |
| TC-LRV-03 (existing) | `request()` rejects invalid candidate_index | Exception |
| TC-LRV-04 (existing) | `request()` rejects missing configuration fields | Exception |
| TC-LRV-05 (existing) | `result()` accepts valid worker success response | Returns validated result |
| TC-LRV-06 (existing) | `result()` rejects missing algorithm | Exception |
| TC-LRV-07 (existing) | `result()` rejects wrong algorithm version | Exception |
| TC-LRV-08 (existing) | `result()` rejects clips array length != 1 | Exception |
| TC-LRV-09 (existing) | `result()` rejects clip candidate_index mismatch | Exception |
| TC-LRV-10 (existing) | `result()` rejects clip bounds mismatch | Exception |
| TC-LRV-11 (existing) | `result()` rejects missing output metadata fields | Exception |
| TC-LRV-12 (existing) | `result()` SHA256 binding mismatch → fail | Exception |
| TC-LRV-13 (existing) | `validateCompletion()` accepts valid completed DerivedAsset | Pass |
| TC-LRV-14 (existing) | `validateCompletion()` rejects missing render columns | Exception |
| TC-LRV-15 (existing) | `validateCompletion()` rejects duration mismatch >50ms | Exception |
| TC-LRV-16 (existing) | `validateCompletion()` rejects resolution mismatch | Exception |
| TC-LRV-17 (new) | `request()` accepts valid render contract with optional `caption_file` | Returns validated array (caption_file passed through) |
| TC-LRV-18 (new) | `request()` rejects `caption_file` when non-string type provided | Exception or graceful handling |
| TC-LRV-19 (new) | `request()` accepts valid render contract without `caption_file` | Returns validated array (baseline behavior) |

#### 5.2 RenderMediaClip Feature Tests — `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php` (new, requires PostgreSQL)

| Test ID | Scenario | Expected |
|---|---|---|
| TC-RMJ-CAP-01 | M5 completed/ranked, valid candidate_index, with `caption_file` → job completes, DerivedAsset created, output has burned-in captions | DerivedAsset completed; output file with captions |
| TC-RMJ-CAP-02 | M5 completed/ranked, valid candidate_index, without `caption_file` → job completes, DerivedAsset created, output identical to M6.1 baseline | DerivedAsset completed; no caption filter; baseline output |
| TC-RMJ-CAP-03 | M5 completed/ranked, candidate_index out of bounds, with `caption_file` → job fails | DerivedAsset failed, error=invalid_candidate_index |
| TC-RMJ-CAP-04 | Existing completed DerivedAsset (same candidate+profile) → reused, no worker call | Returns existing, no process spawn (unchanged from M6.1) |

### 6. Existing Frontend / E2E Regression

No new frontend behavior, public render API, browser interaction, or visual surface is introduced by M6.2 Slice 1.

| Test ID | Scenario | Expected |
|---|---|---|
| TC-E2E-REG-01 | Existing frontend/E2E CI suite | Remains green with no M6.2 Slice 1 regressions |
| TC-E2E-REG-02 | Browser visual review for new rendering UI | N/A — no new rendering UI exists in this issue (slice 1 is backend FFmpeg filter) |
| TC-E2E-REG-03 | New Playwright scenarios / viewport matrix | N/A — no new browser behavior |

## Test Data Requirements

| Fixture | Description |
|---|---|
| `render_source.mp4` | 1920x1080, 30s, H.264/AAC, horizontal content suitable for center-crop (existing) |
| `caption_test.srt` | Simple SRT file with a few caption lines for testing burn-in (new) |
| M5 recommendation fixture | Completed, ranked, K=3 candidates with semantic_rank 1,2,3 and non-null scores (existing) |
| Probe fixture | duration_ms=30000, width=1920, height=1080, video_codec=h264, audio_codec=aac (existing) |

## Test Environment

- Worker CI: FFmpeg 6.x+ with `--enable-libsubtitle`, Python 3.12, pytest
- Laravel CI: PHP 8.3+, PostgreSQL 16, Pest/PHPUnit
- Existing Frontend/E2E CI: run unchanged repository-required checks; no new Playwright/viewport requirement for M6.2 Slice 1
- Storage: S3-compatible (MinIO in CI), `renders/` prefix

## Acceptance Gate

All applicable test scenarios above must pass. Browser visual review and new Playwright coverage are N/A because M6.2 Slice 1 introduces no UI/public API; existing Frontend/E2E required checks must remain green. No test may be weakened, skipped, or removed to achieve pass. Full M1-M5 regression baseline must be preserved.

## Traceability Matrix

| Spec Section | Test Categories |
|---|---|
| Authoritative inputs & readiness | 5.1 (TC-RMJ-CAP-01, TC-RMJ-CAP-02), 4.1 (TC-LRV-17 to TC-LRV-19) |
| Algorithm: configuration | 1.1, 1.2 |
| Selected-candidate projection | 1.1 (TC-WCC-01 to TC-WCC-04), 4.1 |
| Algorithm: filter graph | 2.1, 2.2 |
| Laravel-owned output naming | 4.2, 5.2 |
| Worker output-storage passthrough | 3.1, 4.1 (TC-E2E-CAP-10) |
| Algorithm: result serialization | 1.1 (TC-WCC-01 to TC-WCC-04), 4.1 (TC-E2E-CAP-06) |
| Errors & privacy | 1.1, 3.1 |
| Persistence: DerivedAsset extension | 5.2 (TC-RMJ-CAP-01, TC-RMJ-CAP-02) |
| Validation: independent Laravel | 4.1, 5.1 (TC-RMJ-CAP-01 to TC-RMJ-CAP-04) |
| Lifecycle & concurrency | 5.2 (TC-RMJ-CAP-03, TC-RMJ-CAP-04) |
| RenderMediaClip job boundary | 5.2 (all) |
| ProcessMediaAsset non-integration | 5.3 (all) — unchanged |
| Frontend/UI scope | 6 (existing regression only; new visual/Playwright N/A) |

## Out of Scope Test Scenarios (Do Not Implement)

- AI-powered auto-caption generation (any)
- Configurable focal point or positioning UI for captions
- Multi-language caption support tests
- Caption styling (font, size, color) tests
- Transcript segment projection tests
- Smart crop configuration tests
- Clip review UI tests
- Social publishing integration tests
- ProcessMediaAsset automatic render stage tests
- clipRenderResolved flag tests
- Hardcoded candidate_index=0 / semantic_rank=1 default tests
- Any test that assumes AI model download or external API calls for caption generation