# Test Plan: Issue #69 — Durable Baseline Vertical Clip Render Pipeline

## Authority

This test plan derives exclusively from `specs/069-vertical-clip-render-pipeline/spec.md` and `specs/069-vertical-clip-render-pipeline/plan.md`. Tester executes; Planner owns this file.

## Test Scope

**In scope (M6.1 atomic):**
- Single candidate render by explicit `candidate_index` via `RenderMediaClip` job
- Center-crop only (9:16) vertical reframe
- Deterministic `vertical` algorithm, `vertical_v1` profile version
- No captions, no face tracking, no smart crop variants
- Persistence via `DerivedAsset` with `type=clip_rendered`, `render_status`, `render_started_at`, `render_completed_at`
- Unique constraint: `(media_asset_id, type, candidate_index, render_profile_version)` — `recommendation_id` NOT in unique key
- All readiness/failure cases for M5 upstream
- Idempotent reuse, version conflict, concurrency protection
- Explicit invocation — no automatic ProcessMediaAsset integration
- Worker contract: `render_clip` action (singular), NO database identifiers, precomputed output key

**Out of scope (explicit):**
- Captions, subtitle generation, burn-in, styling
- Face-aware crop, AI smart reframing, subject tracking
- Configurable focal point UI
- Multiple clips per render job (top-N, semantic_rank=1 auto-selection)
- Transcript segment handling
- Clip review UI, social publishing, frontend/API changes
- Automatic render stage in ProcessMediaAsset
- clipRenderResolved flag in asset completion
- Hardcoded candidate_index=0 / semantic_rank=1 default

## Test Categories

### 1. Worker Contract Validation (Python)

#### 1.1 Schema Validation — `test_render_clip_contract_validation.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-WCV-01 | Valid request with all required fields, single root `candidate_index` | Pass validation |
| TC-WCV-02 | Missing `version` field | Fail: `invalid_contract` |
| TC-WCV-03 | Invalid `version` (not "1.0.0") | Fail: `invalid_contract` |
| TC-WCV-04 | Invalid `action` (not "render_clip") | Fail: `invalid_contract` |
| TC-WCV-05 | Missing `media_asset_id` at root (legacy required field) | Fail: `invalid_contract` |
| TC-WCV-06 | Missing `media.duration_ms` | Fail: `invalid_contract` |
| TC-WCV-07 | `media.duration_ms` non-integer or out of bounds | Fail: `invalid_contract` |
| TC-WCV-08 | Missing `recommendation.candidates` array | Fail: `invalid_contract` |
| TC-WCV-09 | Missing root `candidate_index` | Fail: `invalid_contract` |
| TC-WCV-10 | Root `candidate_index` out of bounds (negative or >= K) | Fail: `invalid_contract` |
| TC-WCV-11 | Selected candidate has null `semantic_score` | Fail: `invalid_contract` |
| TC-WCV-12 | `recommendation.candidate_index` present (duplicate forbidden) | Fail: `invalid_contract` |
| TC-WCV-13 | Missing `configuration` object | Fail: `invalid_contract` |
| TC-WCV-14 | Invalid config: `target_width` odd, >4096, <1 | Fail: `invalid_contract` |
| TC-WCV-15 | Invalid config: `target_height` odd, >4096, <1 | Fail: `invalid_contract` |
| TC-WCV-16 | Invalid config: `target_fps` out of bounds | Fail: `invalid_contract` |
| TC-WCV-17 | Invalid config: `video_codec` not in enum | Fail: `invalid_contract` |
| TC-WCV-18 | Invalid config: `video_bitrate_kbps` out of bounds | Fail: `invalid_contract` |
| TC-WCV-19 | Invalid config: `audio_codec` not in enum | Fail: `invalid_contract` |
| TC-WCV-20 | Invalid config: `audio_bitrate_kbps` out of bounds | Fail: `invalid_contract` |
| TC-WCV-21 | Unknown field in request (strict schema) | Fail: `invalid_contract` |
| TC-WCV-22 | Input size > 8388608 bytes | Fail: `invalid_contract` |
| TC-WCV-23 | Database identifiers present (`recommendation_id`, `project_id`) | Fail: `invalid_contract` |
| TC-WCV-24 | Valid response with single clip, all required fields | Pass validation |
| TC-WCV-25 | Response missing `algorithm` or wrong value (not "vertical") | Fail: `invalid_contract` |
| TC-WCV-26 | Response missing `algorithm_version` or wrong value (not "vertical_v1") | Fail: `invalid_contract` |
| TC-WCV-27 | Response `clips` not array or length != 1 | Fail: `invalid_contract` |
| TC-WCV-28 | Clip missing required output fields | Fail: `invalid_contract` |
| TC-WCV-29 | NaN/Infinity in any numeric field (request or response) | Fail: `invalid_contract` |

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
| TC-ONM-01 | Worker receives precomputed output key: `projects/{project_id}/renders/{asset_id}/{cand_idx}_{timestamp}.mp4` | Uses key as-is |
| TC-ONM-02 | Timestamp format: `YYYYMMDDTHHMMSSZ` (UTC, no separators) | Key contains valid timestamp |
| TC-ONM-03 | Deterministic for same inputs (same second) | Identical keys |

#### 2.4 CLI Transport — `test_cli_render_clip.py`

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

#### 3.1 End-to-End Render — `test_render_clip_integration.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-E2E-01 | Real FFmpeg on fixture horizontal video (1920x1080, 30s, audio) | Output 1080x1920 MP4 |
| TC-E2E-02 | Output duration matches candidate bounds (±50ms) | Duration within tolerance |
| TC-E2E-03 | Output has video codec libx264, audio codec aac | Probe confirms |
| TC-E2E-04 | Output bitrate matches configuration (±10%) | Probe confirms |
| TC-E2E-05 | Output file size > 0 | Confirmed |
| TC-E2E-06 | Filter graph recorded in result parameters | Non-empty string |
| TC-E2E-07 | FFmpeg version recorded | Non-empty string |
| TC-E2E-08 | Source media metadata in parameters | Matches probe |
| TC-E2E-09 | Limits object present with correct values | Matches spec |
| TC-E2E-10 | Different candidate_index produces different output key | Keys differ by candidate_index |
| TC-E2E-11 | Response algorithm = "vertical", algorithm_version = "vertical_v1" | Exact match |

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
| TC-LUP-06 | `lockWaitSeconds()` = timeout + 10 | Integer 310 |

#### 4.2 RenderValidator — `RenderValidatorTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-LRV-01 | `request()` accepts valid render contract | Returns validated array |
| TC-LRV-02 | `request()` rejects missing candidate_index | Exception |
| TC-LRV-03 | `request()` rejects invalid candidate_index | Exception |
| TC-LRV-04 | `request()` rejects missing configuration fields | Exception |
| TC-LRV-05 | `result()` accepts valid worker success response | Returns validated result |
| TC-LRV-06 | `result()` rejects missing algorithm | Exception |
| TC-LRV-07 | `result()` rejects wrong algorithm value (not "vertical") | Exception |
| TC-LRV-08 | `result()` rejects wrong algorithm_version (not "vertical_v1") | Exception |
| TC-LRV-09 | `result()` rejects clips array length != 1 | Exception |
| TC-LRV-10 | `result()` rejects clip candidate_index mismatch | Exception |
| TC-LRV-11 | `result()` rejects clip bounds mismatch | Exception |
| TC-LRV-12 | `result()` rejects missing output metadata fields | Exception |
| TC-LRV-13 | `result()` SHA256 binding mismatch → fail | Exception |
| TC-LRV-14 | `validateCompletion()` accepts valid completed DerivedAsset | Pass |
| TC-LRV-15 | `validateCompletion()` rejects missing render columns | Exception |
| TC-LRV-16 | `validateCompletion()` rejects output file missing | Exception |
| TC-LRV-17 | `validateCompletion()` rejects duration mismatch >50ms | Exception |
| TC-LRV-18 | `validateCompletion()` rejects resolution mismatch | Exception |

#### 4.3 DerivedAsset Rendered Clip — `DerivedAssetRenderedClipTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-LDA-01 | Create DerivedAsset with type=clip_rendered, candidate_index, render_profile_version=vertical_v1, render_status, render_started_at, render_completed_at | Persists correctly |
| TC-LDA-02 | Unique constraint: same asset, type, candidate_index, profile_version → conflict | DB exception |
| TC-LDA-03 | Unique constraint: different profile_version → allowed | New row created |
| TC-LDA-04 | Unique constraint: different candidate_index → allowed | New row created |
| TC-LDA-05 | Unique constraint: different recommendation_id (same candidate_index, profile_version) → conflict | DB exception (recommendation_id NOT in unique key) |
| TC-LDA-06 | `renderedClips()` scope returns only clip_rendered type | Filters correctly |
| TC-LDA-07 | Render columns (configuration, parameters, error) cast to array | JSONB → array |
| TC-LDA-08 | Date casts for render_started_at, render_completed_at work | Carbon instances |
| TC-LDA-09 | Cascade delete: MediaAsset deleted → DerivedAsset deleted | Confirmed |

### 5. Laravel Feature/Integration Tests

#### 5.1 RenderMediaClip Job — `RenderMediaClipJobTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-RMJ-01 | M5 completed/ranked, valid candidate_index → job completes, DerivedAsset created | DerivedAsset completed, output persisted, render_status=completed, render_started_at/render_completed_at set |
| TC-RMJ-02 | M5 completed/ranked, candidate_index out of bounds → job fails | DerivedAsset failed, render_error=invalid_candidate_index, render_status=failed |
| TC-RMJ-03 | M5 completed/ranked, candidate has null semantic_score → job fails | DerivedAsset failed, render_error=invalid_candidate_index, render_status=failed |
| TC-RMJ-04 | M5 pending/ranking/not_ready → job throws UpstreamRecommendationUnavailableException | Exception thrown, no DerivedAsset created |
| TC-RMJ-05 | M5 failed → job throws UpstreamRecommendationFailedException | Exception thrown |
| TC-RMJ-06 | M5 unavailable/missing → job throws UpstreamRecommendationUnavailableException | Exception thrown |
| TC-RMJ-07 | M5 missing after resolved → job throws UpstreamRecommendationMissingException | Exception thrown |
| TC-RMJ-08 | Existing completed DerivedAsset (same candidate_index + profile_version) → reused, no worker call | Returns existing, no process spawn |
| TC-RMJ-09 | Existing completed DerivedAsset, different M5 authority bounds/config → version_conflict | Throws RenderVersionConflictException |
| TC-RMJ-10 | Different recommendation_id, same candidate_index + profile_version → reuses existing (recommendation_id NOT in unique key) | Returns existing, no new row |
| TC-RMJ-11 | Concurrent claim: first job locks, second gets busy | Second throws RenderBusyException, no corruption |
| TC-RMJ-12 | Lock timeout → RenderBusyException | Busy, no state change |
| TC-RMJ-13 | Worker process crash → transaction rolls back, pending/failed recoverable | Retry works |
| TC-RMJ-14 | Invalid duration (missing probe) → job throws InvalidInputException | Exception thrown |
| TC-RMJ-15 | Job idempotency: re-dispatch same params → returns same DerivedAsset | No duplicate, no re-render |
| TC-RMJ-16 | Failed attempt retry: re-dispatch after failure → new attempt, clears error, render_started_at updated | New rendering attempt, render_status=rendering |

#### 5.2 Real Worker Integration — `RenderMediaClipJobRealWorkerTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-RMR-01 | Full job with real worker subprocess, FFmpeg fixture | DerivedAsset completed, file in storage |
| TC-RMR-02 | Output file exists on configured disk at expected key (projects/{project_id}/renders/...) | Storage::exists() true |
| TC-RMR-03 | DerivedAsset render_columns populated (configuration, parameters, render_status, render_started_at, render_completed_at) | JSON matches spec |
| TC-RMR-04 | DerivedAsset output metadata matches probe (duration within ±50ms, resolution, codecs) | Within tolerance |
| TC-RMR-05 | Filter graph in parameters is non-empty | Confirmed |
| TC-RMR-06 | Different candidate_index produces distinct DerivedAsset row | Two rows, different candidate_index |
| TC-RMR-07 | Worker response algorithm="vertical", algorithm_version="vertical_v1" | Exact match |

#### 5.3 ProcessMediaAsset Regression — `ProcessMediaAssetRegressionTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-PMA-REG-01 | ProcessMediaAsset completes after M5 without render stage | Asset completes, no render attempted |
| TC-PMA-REG-02 | No clipRenderResolved flag exists on MediaAsset | Confirmed absent |
| TC-PMA-REG-03 | Existing upstream stages (probe, scene, audio, M4, M5) unchanged | All pass as before |
| TC-PMA-REG-04 | Asset finalization does not require render | Asset completes with only existing resolved stages |

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
- Storage: S3-compatible (MinIO in CI), `projects/{project_id}/renders/` prefix

## Acceptance Gate

All test scenarios above must pass. Tester must approve running behavior via Playwright visual QA at 3 viewports with clean console/network. No test may be weakened, skipped, or removed to achieve pass. Full regression baseline must be preserved.

## Traceability Matrix

| Spec Section | Test Categories |
|---|---|
| Authoritative inputs & readiness | 5.1 (TC-RMJ-01 through TC-RMJ-16) |
| Algorithm: configuration | 1.1, 1.2, 4.1 |
| Algorithm: clip selection | 1.1 (TC-WCV-09 to TC-WCV-11, TC-WCV-12), 2.2 |
| Algorithm: filter graph | 2.1 |
| Algorithm: output naming | 2.3 |
| Algorithm: result serialization | 1.1 (TC-WCV-24 to TC-WCV-29), 4.2 |
| Errors & privacy | 1.1, 2.4 |
| Persistence: DerivedAsset extension | 4.3, 5.1 (TC-RMJ-08, TC-RMJ-09, TC-RMJ-10) |
| Validation: independent Laravel | 4.2, 5.1 (TC-RMJ-01 through TC-RMJ-14) |
| Lifecycle & concurrency | 5.1 (TC-RMJ-11, TC-RMJ-12, TC-RMJ-13) |
| RenderMediaClip job boundary | 5.1 (all), 5.2 |
| ProcessMediaAsset non-integration | 5.3 (all) |
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
- ProcessMediaAsset automatic render stage tests
- clipRenderResolved flag tests
- Hardcoded candidate_index=0 / semantic_rank=1 default tests