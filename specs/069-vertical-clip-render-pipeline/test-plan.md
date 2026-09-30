# Test Plan: Issue #69 — Durable Baseline Vertical Clip Render Pipeline

## Authority

This test plan derives exclusively from `specs/069-vertical-clip-render-pipeline/spec.md` and `specs/069-vertical-clip-render-pipeline/plan.md`. Tester executes; Planner owns this file.

## Test Scope

**In scope (M6.1 atomic):**
- Single candidate render by explicit `candidate_index`
- Center-crop only (9:16) vertical reframe
- Deterministic `ffmpeg_vertical_baseline` v1.0.0 profile
- No captions, no face tracking, no smart crop variants
- Persistence via `DerivedAsset` with `type=clip_rendered`
- Unique constraint: `(media_asset_id, type, candidate_index, render_profile_version)`
- All readiness/failure cases for M5 upstream

**Out of scope (explicit):**
- Captions, subtitle generation, burn-in, styling
- Face-aware crop, AI smart reframing, subject tracking
- Configurable focal point UI
- Multiple clips per render job (top-N, semantic_rank=1 auto-selection)
- Transcript segment handling
- Clip review UI, social publishing, frontend/API changes

## Test Categories

### 1. Worker Contract Validation (Python)

#### 1.1 Schema Validation — `test_render_clips_contract_validation.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-WCV-01 | Valid request with all required fields, single candidate_index | Pass validation |
| TC-WCV-02 | Missing `version` field | Fail: `invalid_contract` |
| TC-WCV-03 | Invalid `version` (not "1.0.0") | Fail: `invalid_contract` |
| TC-WCV-04 | Invalid `action` (not "render_clips") | Fail: `invalid_contract` |
| TC-WCV-05 | Missing `media.duration_ms` | Fail: `invalid_contract` |
| TC-WCV-06 | `media.duration_ms` non-integer or out of bounds | Fail: `invalid_contract` |
| TC-WCV-07 | Missing `recommendation.candidates` array | Fail: `invalid_contract` |
| TC-WCV-08 | Missing `recommendation.candidate_index` | Fail: `invalid_contract` |
| TC-WCV-09 | `candidate_index` out of bounds (negative or >= K) | Fail: `invalid_contract` |
| TC-WCV-10 | Selected candidate has null `semantic_score` | Fail: `invalid_contract` |
| TC-WCV-11 | Missing `configuration` object | Fail: `invalid_contract` |
| TC-WCV-12 | Invalid config: `target_width` odd, >4096, <1 | Fail: `invalid_contract` |
| TC-WCV-13 | Invalid config: `target_height` odd, >4096, <1 | Fail: `invalid_contract` |
| TC-WCV-14 | Invalid config: `target_fps` out of bounds | Fail: `invalid_contract` |
| TC-WCV-15 | Invalid config: `video_codec` not in enum | Fail: `invalid_contract` |
| TC-WCV-16 | Invalid config: `video_bitrate_kbps` out of bounds | Fail: `invalid_contract` |
| TC-WCV-17 | Invalid config: `audio_codec` not in enum | Fail: `invalid_contract` |
| TC-WCV-18 | Invalid config: `audio_bitrate_kbps` out of bounds | Fail: `invalid_contract` |
| TC-WCV-19 | Unknown field in request (strict schema) | Fail: `invalid_contract` |
| TC-WCV-20 | Input size > 8388608 bytes | Fail: `invalid_contract` |
| TC-WCV-21 | Valid response with single clip, all required fields | Pass validation |
| TC-WCV-22 | Response missing `algorithm` or wrong value | Fail: `invalid_contract` |
| TC-WCV-23 | Response missing `algorithm_version` or wrong value | Fail: `invalid_contract` |
| TC-WCV-24 | Response `clips` not array or length != 1 | Fail: `invalid_contract` |
| TC-WCV-25 | Clip missing required output fields | Fail: `invalid_contract` |
| TC-WCV-26 | NaN/Infinity in any numeric field (request or response) | Fail: `invalid_contract` |

#### 1.2 Configuration Validation — `test_render_configuration.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-WCF-01 | All defaults applied when configuration partial | Defaults match spec table |
| TC-WCF-02 | Explicit valid configuration accepted | All fields pass |
| TC-WCF-03 | Each field at boundary (min/max) accepted | Pass |
| TC-WCF-04 | Each field just outside boundary rejected | Fail with clear error |
| TC-WCF-05 | Enum fields accept only defined values | Others rejected |

### 2. Renderer Unit Tests (Python)

#### 2.1 Filter Graph Construction — `test_ffmpeg_vertical_renderer.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-FGR-01 | Center-crop filter: `crop=ih*9/16:ih:(iw-ih*9/16)/2:0` | Exact string match |
| TC-FGR-02 | Scale+pad filter for 1080x1920 @ 30fps | Contains `scale=1080:1920` and `pad=1080:1920` |
| TC-FGR-03 | FPS filter: `fps=30` | Exact string match |
| TC-FGR-04 | Full filter graph chain order: crop → scale → pad → fps | Correct sequence |
| TC-FGR-05 | Different target resolution (e.g., 720x1280) | Adapts correctly |
| TC-FGR-06 | Different target_fps (e.g., 60) | Adapts correctly |
| TC-FGR-07 | No caption filter present in graph | Confirmed absent |
| TC-FGR-08 | No face_aware logic in code path | Confirmed absent |

#### 2.2 Clip Selection — `test_ffmpeg_vertical_renderer.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-CSL-01 | Valid candidate_index (0) with semantic_score → selected | Returns that candidate |
| TC-CSL-02 | Valid candidate_index (K-1) with semantic_score → selected | Returns that candidate |
| TC-CSL-03 | candidate_index out of bounds (K) | Raises `InvalidCandidateIndex` |
| TC-CSL-04 | candidate_index negative | Raises `InvalidCandidateIndex` |
| TC-CSL-05 | Selected candidate has null semantic_score | Raises `InvalidCandidateIndex` |
| TC-CSL-06 | Multiple candidates, only one selected by index | Only indexed candidate processed |

#### 2.3 Output Naming — `test_ffmpeg_vertical_renderer.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-ONM-01 | Key format: `renders/{asset_id}/{rec_id}/{cand_idx}_{timestamp}.mp4` | Regex match |
| TC-ONM-02 | Timestamp format: `YYYYMMDDTHHMMSSZ` (UTC, no separators) | Regex match |
| TC-ONM-03 | Deterministic for same inputs (same second) | Identical keys |

#### 2.4 CLI Transport — `test_cli_render_clips.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-CLI-01 | Valid stdin JSON → stdout JSON, exit 0 | Success envelope |
| TC-CLI-02 | Invalid JSON on stdin → exit 2, error envelope | `invalid_contract` |
| TC-CLI-03 | Invalid contract (missing field) → exit 2 | `invalid_contract` |
| TC-CLI-04 | Runtime error (FFmpeg fail) → exit 1 | `render_failed` |
| TC-CLI-05 | Output bounded (no unbounded stdout/stderr leak) | Only JSON envelope on stdout |
| TC-CLI-06 | NaN in output → rejected before emit | Exit 1, `render_failed` |
| TC-CLI-07 | Input size > 8MB → exit 2 | `invalid_contract` |

### 3. Worker Integration Tests (Real FFmpeg)

#### 3.1 End-to-End Render — `test_render_clips_integration.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-E2E-01 | Real FFmpeg on fixture horizontal video (1920x1080, 30s, audio) | Output 1080x1920 MP4 |
| TC-E2E-02 | Output duration matches candidate bounds (±5%) | Duration within tolerance |
| TC-E2E-03 | Output has video codec libx264, audio codec aac | Probe confirms |
| TC-E2E-04 | Output bitrate matches configuration (±10%) | Probe confirms |
| TC-E2E-05 | Output file size > 0 | Confirmed |
| TC-E2E-06 | Filter graph recorded in result parameters | Non-empty string |
| TC-E2E-07 | FFmpeg version recorded | Non-empty string |
| TC-E2E-08 | Source media metadata in parameters | Matches probe |
| TC-E2E-09 | Limits object present with correct values | Matches spec |
| TC-E2E-10 | Different candidate_index produces different output key | Keys differ by candidate_index |

**Fixture:** `tests/fixtures/render_source.mp4` — 1920x1080, 30 seconds, H.264/AAC, with audio.

### 4. Laravel Unit Tests

#### 4.1 RenderProfile — `RenderProfileTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-LUP-01 | `configuration()` returns all 7 fields with spec defaults | Exact match |
| TC-LUP-02 | `timeoutSeconds()` returns 300 default | Integer 300 |
| TC-LUP-03 | `timeoutSeconds()` rejects non-canonical decimal string | Exception |
| TC-LUP-04 | `timeoutSeconds()` rejects <30 or >1800 | Exception |
| TC-LUP-05 | `timeoutSeconds()` rejects null/float/non-string | Exception |
| TC-LUP-06 | `lockWaitSeconds()` = timeout + 5 | Integer 305 |

#### 4.2 RenderValidator — `RenderValidatorTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-LRV-01 | `request()` accepts valid render contract | Returns validated array |
| TC-LRV-02 | `request()` rejects missing candidate_index | Exception |
| TC-LRV-03 | `request()` rejects invalid candidate_index | Exception |
| TC-LRV-04 | `request()` rejects missing configuration fields | Exception |
| TC-LRV-05 | `result()` accepts valid worker success response | Returns validated result |
| TC-LRV-06 | `result()` rejects missing algorithm | Exception |
| TC-LRV-07 | `result()` rejects wrong algorithm value | Exception |
| TC-LRV-08 | `result()` rejects wrong algorithm_version | Exception |
| TC-LRV-09 | `result()` rejects clips array length != 1 | Exception |
| TC-LRV-10 | `result()` rejects clip candidate_index mismatch | Exception |
| TC-LRV-11 | `result()` rejects clip bounds mismatch | Exception |
| TC-LRV-12 | `result()` rejects missing output metadata fields | Exception |
| TC-LRV-13 | `result()` SHA256 binding mismatch → fail | Exception |
| TC-LRV-14 | `validateCompletion()` accepts valid completed DerivedAsset | Pass |
| TC-LRV-15 | `validateCompletion()` rejects missing render columns | Exception |
| TC-LRV-16 | `validateCompletion()` rejects output file missing | Exception |
| TC-LRV-17 | `validateCompletion()` rejects duration mismatch >5% | Exception |
| TC-LRV-18 | `validateCompletion()` rejects resolution mismatch | Exception |

#### 4.3 DerivedAsset Rendered Clip — `DerivedAssetRenderedClipTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-LDA-01 | Create DerivedAsset with type=clip_rendered, candidate_index, render_profile_version | Persists correctly |
| TC-LDA-02 | Unique constraint: same asset, type, candidate_index, profile_version → conflict | DB exception |
| TC-LDA-03 | Unique constraint: different profile_version → allowed | New row created |
| TC-LDA-04 | Unique constraint: different candidate_index → allowed | New row created |
| TC-LDA-05 | `renderedClips()` scope returns only clip_rendered type | Filters correctly |
| TC-LDA-06 | Render columns (configuration, parameters, error) cast to array | JSONB → array |
| TC-LDA-07 | Cascade delete: MediaAsset deleted → DerivedAsset deleted | Confirmed |

### 5. Laravel Feature/Integration Tests

#### 5.1 ProcessMediaAsset Render Stage — `ProcessMediaAssetRenderTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-PMA-01 | M5 completed/ranked, valid candidate_index → render invoked, completes | DerivedAsset completed |
| TC-PMA-02 | M5 completed/ranked, candidate_index out of bounds → failed attempt | DerivedAsset failed, error=invalid_candidate_index |
| TC-PMA-03 | M5 completed/ranked, candidate has null semantic_score → failed attempt | DerivedAsset failed, error=invalid_candidate_index |
| TC-PMA-04 | M5 pending → not_ready, job retries (max 3, 5s delay) | Job released, retry counted |
| TC-PMA-05 | M5 failed → render attempt claimed, marked failed | DerivedAsset failed, error=upstream_recommendation_failed |
| TC-PMA-06 | M5 unavailable → render attempt claimed, marked failed | DerivedAsset failed, error=upstream_recommendation_unavailable |
| TC-PMA-07 | M5 missing after resolved → failed attempt | DerivedAsset failed, error=upstream_recommendation_missing |
| TC-PMA-08 | Existing completed DerivedAsset (same candidate+profile) → reused, no worker call | Reused, no process spawn |
| TC-PMA-09 | Existing completed DerivedAsset, different configuration → version_conflict | Failed, error=render_version_conflict |
| TC-PMA-10 | Concurrent claim: first job locks, second gets busy | Second returns busy, no corruption |
| TC-PMA-11 | Lock timeout → busy return | Busy, no state change |
| TC-PMA-12 | Worker process crash → transaction rolls back, pending/failed recoverable | Retry works |
| TC-PMA-13 | Invalid duration (missing probe) → failed attempt | DerivedAsset failed, error=invalid_input |
| TC-PMA-14 | Asset finalization: clipRenderResolved=true only for completed/failed/terminal | Accessor logic correct |
| TC-PMA-15 | Asset completion requires clipRenderResolved + all upstream resolved | Transition logic correct |
| TC-PMA-16 | Controlled upstream failure (render failed) → asset can still complete | Asset completed, render failed recorded |

#### 5.2 Real Worker Integration — `ProcessMediaAssetRenderRealWorkerTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-PMR-01 | Full job with real worker subprocess, FFmpeg fixture | DerivedAsset completed, file in storage |
| TC-PMR-02 | Output file exists on configured disk at expected key | Storage::exists() true |
| TC-PMR-03 | DerivedAsset render_columns populated (configuration, parameters) | JSON matches spec |
| TC-PMR-04 | DerivedAsset output metadata matches probe (duration, resolution, codecs) | Within tolerance |
| TC-PMR-05 | Filter graph in parameters is non-empty | Confirmed |

### 6. E2E / Playwright Tests

#### 6.1 Regression — `existing-workflow.spec.ts`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-E2E-PW-01 | Upload video → process → complete (no render UI) | No console errors, no network errors, asset completes |
| TC-PW-02 | Responsive layout at 390x844, 768x1024, 1440x900 | No overflow, no layout shift |
| TC-PW-03 | No new UI elements for rendering | Confirmed absent |

## Test Data Requirements

| Fixture | Description |
|---|---|
| `render_source.mp4` | 1920x1080, 30s, H.264/AAC, horizontal content suitable for center-crop |
| M5 recommendation fixture | Completed, ranked, K=3 candidates with semantic_rank 1,2,3 and non-null scores |
| Probe fixture | duration_ms=30000, width=1920, height=1080, video_codec=h264, audio_codec=aac |

## Test Environment

- Worker CI: FFmpeg 6.x+, Python 3.12, pytest
- Laravel CI: PHP 8.3+, PostgreSQL 16, Pest/PHPUnit
- E2E CI: Playwright with Chromium, 3 viewports
- Storage: S3-compatible (MinIO in CI), `renders/` prefix

## Acceptance Gate

All test scenarios above must pass. Tester must approve running behavior via Playwright visual QA at 3 viewports with clean console/network. No test may be weakened, skipped, or removed to achieve pass. Full regression baseline must be preserved.

## Traceability Matrix

| Spec Section | Test Categories |
|---|---|
| Authoritative inputs & readiness | 5.1 (TC-PMA-01 through TC-PMA-16) |
| Algorithm: configuration | 1.1, 1.2, 4.1 |
| Algorithm: clip selection | 1.1 (TC-WCV-08 to TC-WCV-10), 2.2 |
| Algorithm: filter graph | 2.1 |
| Algorithm: output naming | 2.3 |
| Algorithm: result serialization | 1.1 (TC-WCV-21 to TC-WCV-26), 4.2 |
| Errors & privacy | 1.1, 2.4 |
| Persistence: DerivedAsset extension | 4.3, 5.1 (TC-PMA-08, TC-PMA-09) |
| Validation: independent Laravel | 4.2, 5.1 (TC-PMA-01 through TC-PMA-13) |
| Lifecycle & concurrency | 5.1 (TC-PMA-10, TC-PMA-11, TC-PMA-12) |
| ProcessMediaAsset integration | 5.1 (all) |
| Security & UX | 6.1 (TC-PW-01 through TC-PW-03) |

## Out of Scope Test Scenarios (Do Not Implement)

- Caption rendering validation (any)
- Face-aware crop filter graph tests
- Multi-clip selection (top-N) tests
- Transcript segment projection tests
- Smart crop configuration tests
- Candidate auto-selection by semantic_rank tests
- Clip review UI tests
- Social publishing integration tests