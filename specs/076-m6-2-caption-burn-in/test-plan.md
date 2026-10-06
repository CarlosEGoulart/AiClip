# Test Plan: Issue #76 — Deterministic Caption Burn-In for Explicit Vertical Clip Rendering

## Authority

This test plan derives exclusively from `specs/076-m6-2-caption-burn-in/spec.md` and `specs/076-m6-2-caption-burn-in/plan.md`. Tester executes; Planner owns this file.

## Test Scope

**In scope (M6.2 atomic):**
- Single candidate render by explicit `candidate_index` via `RenderMediaClip` job
- Center-crop only (9:16) vertical reframe (M6.1 baseline)
- Caption burn-in via FFmpeg `drawtext` filter chain
- Caption segments derived from `MediaTranscript.segments`, mapped to candidate-local timebase
- Deterministic caption styling configuration
- Transcript text escaping for FFmpeg safety (Laravel-side)
- Graceful fallback when transcript unavailable (M6.1 behavior)
- Persistence via `DerivedAsset` with `type=clip_rendered`
- Unique constraint: `(media_asset_id, type, candidate_index, render_profile_version)`
- All readiness/failure cases for M5 upstream + transcript states
- Idempotent reuse, version conflict (including transcript changes), concurrency protection
- Explicit invocation — no automatic `ProcessMediaAsset` integration

**Out of scope (explicit):**
- SRT/VTT file generation, subtitle tracks
- Configurable caption editor UI
- Multiple caption styles per clip
- Per-candidate caption customization
- Live caption editing/preview
- Multi-language caption selection
- Face-aware caption placement
- Caption animation/transitions
- Smart crop, face tracking, multi-aspect, multi-clip
- Clip review UI, social publishing, frontend/API changes
- Automatic render stage in `ProcessMediaAsset`
- Hardcoded `candidate_index=0` / `semantic_rank=1` default

## Test Categories

### 1. Laravel Unit Tests

#### 1.1 RenderProfile Caption Configuration — `RenderProfileTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-LUP-CAP-01 | `configuration()` includes `captions` object with all defaults | Exact match with spec defaults |
| TC-LUP-CAP-02 | `captions.enabled` default `true` | Boolean true |
| TC-LUP-CAP-03 | `captions.font_file` default exists in container | Path string |
| TC-LUP-CAP-04 | `captions.font_size` default 72 | Integer 72 |
| TC-LUP-CAP-05 | `captions.font_color` default `ffffff` | **6-char hex without `#`** |
| TC-LUP-CAP-06 | `captions.outline_color` default `000000` | **6-char hex without `#`** |
| TC-LUP-CAP-07 | `captions.outline_width` default 3 | Integer 3 |
| TC-LUP-CAP-08 | `captions.background_color` default `000000` | **6-char hex without `#`** |
| TC-LUP-CAP-09 | `captions.background_opacity` default 0.5 | Float 0.5 |
| TC-LUP-CAP-10 | `captions.box_padding` default 10 | Integer 10 |
| TC-LUP-CAP-11 | `captions.margin_bottom` default 100 | Integer 100 |
| TC-LUP-CAP-12 | `captions.max_chars_per_line` default 32 | Integer 32 |
| TC-LUP-CAP-13 | Validation rejects `font_size` < 12 or > 200 | Exception |
| TC-LUP-CAP-14 | Validation rejects `outline_width` < 0 or > 10 | Exception |
| TC-LUP-CAP-15 | Validation rejects `background_opacity` < 0.0 or > 1.0 | Exception |
| TC-LUP-CAP-16 | Validation rejects `margin_bottom` < 0 or > 500 | Exception |
| TC-LUP-CAP-17 | Validation rejects `max_chars_per_line` < 10 or > 80 | Exception |
| TC-LUP-CAP-18 | Validation rejects invalid hex color format (not 6-char, or contains `#`) | Exception |

#### 1.2 RenderValidator Caption Request/Result — `RenderValidatorTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-LRV-CAP-01 | Valid request with **top-level** `captions` object (enabled + segments array) | Pass validation |
| TC-LRV-CAP-02 | Valid request without `captions` object | Pass validation (M6.1 compat) |
| TC-LRV-CAP-03 | Request with top-level `captions.enabled=false` | Pass validation, no segments required |
| TC-LRV-CAP-04 | Request with `captions` but missing `segments` when enabled=true | Fail: invalid contract |
| TC-LRV-CAP-05 | Request with `captions.segments` not an array | Fail: invalid contract |
| TC-LRV-CAP-06 | Segment missing `start_ms`, `end_ms`, or `text` | Fail: invalid contract |
| TC-LRV-CAP-07 | Segment `start_ms` < 0 or `end_ms` <= `start_ms` | Fail: invalid contract |
| TC-LRV-CAP-08 | Segment `text` not a string | Fail: invalid contract |
| TC-LRV-CAP-09 | Result validation: filter graph contains `drawtext` when captions requested | Pass |
| TC-LRV-CAP-10 | Result validation: filter graph lacks `drawtext` when captions requested | Fail |
| TC-LRV-CAP-11 | Result validation: filter graph contains `drawtext` when no captions requested | Fail (unexpected) |
| TC-LRV-CAP-12 | Completion validation: caption **styling** config in `parameters.configuration.captions` | Pass |
| TC-LRV-CAP-13 | Completion validation: caption config absent when not requested | Pass |
| TC-LRV-CAP-14 | Request validation: `configuration.captions` contains styling only (no segments) | Pass |
| TC-LRV-CAP-15 | Request validation: `configuration.captions` contains segments → reject | Fail: invalid contract |
| TC-LRV-CAP-16 | **Result validation: SHA256 binding matches exact request bytes sent to worker** | Pass (identical serialization) |
| TC-LRV-CAP-17 | **Result validation: SHA256 binding mismatch fails** | Fail: render_failed |
| TC-LRV-CAP-18 | **Result validation: output metadata uses probed values (video_codec from ffprobe, not config)** | Pass |

#### 1.3 MediaTranscript Segment Projection — `MediaTranscriptTest.php` (new or existing)

| Test ID | Scenario | Expected |
|---|---|---|
| TC-MTS-01 | Project segments overlapping candidate start/end | Segments clipped to candidate bounds |
| TC-MTS-02 | Project segments entirely before candidate | Empty result |
| TC-MTS-03 | Project segments entirely after candidate | Empty result |
| TC-MTS-04 | Project segments with zero-length overlap | Excluded (local_end > local_start) |
| TC-MTS-05 | Multiple segments mapped, sorted by local_start_ms | Ascending order |
| TC-MTS-06 | Transcript status != completed returns empty | Empty array |
| TC-MTS-07 | Transcript with empty segments returns empty | Empty array |
| TC-MTS-08 | Local timebase: segment.start_ms = candidate.start_ms → local 0 | Correct mapping |
| TC-MTS-09 | **Text escaping**: special chars (quotes, colons, backslashes, %) escaped | Escaped per spec rules |
| TC-MTS-10 | **Text wrapping**: text exceeding max_chars_per_line split with `\n` | Multi-line text with `\n` |
| TC-MTS-11 | **Control characters** (ASCII < 32) replaced with space | No control chars in output |

#### 1.4 StorageKeyBuilder — `StorageKeyBuilderTest.php` (regression)

| Test ID | Scenario | Expected |
|---|---|---|
| TC-SKB-CAP-01 | Same identity with/without captions produces same key | Key unchanged (captions don't affect identity) |

### 2. Worker Contract Validation (Python)

#### 2.1 Schema Validation — `test_render_clip_contract_validation.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-WCV-CAP-01 | Valid request with **top-level** `captions` object (enabled + segments) | Pass validation |
| TC-WCV-CAP-02 | Valid request without `captions` object | Pass validation |
| TC-WCV-CAP-03 | Top-level `captions.enabled` = false, no segments required | Pass validation |
| TC-WCV-CAP-04 | Top-level `captions.enabled` = true but `segments` missing | Fail: invalid contract |
| TC-WCV-CAP-05 | Top-level `captions.segments` not a list | Fail: invalid contract |
| TC-WCV-CAP-06 | Segment missing required fields (start_ms, end_ms, text) | Fail: invalid contract |
| TC-WCV-CAP-07 | Segment timing invalid (negative, end <= start) | Fail: invalid contract |
| TC-WCV-CAP-08 | Segment `text` not string | Fail: invalid contract |
| TC-WCV-CAP-09 | Unknown field in top-level `captions` object | Fail: invalid contract (additionalProperties: false) |
| TC-WCV-CAP-10 | **`configuration.captions` contains segments** → reject | Fail: invalid contract |
| TC-WCV-CAP-11 | **`configuration.captions` missing styling fields** → reject | Fail: invalid contract |
| TC-WCV-CAP-12 | Response validation: filter_graph contains drawtext when captions in request | Pass |

#### 2.2 Configuration Validation — `test_render_configuration.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-WCF-CAP-01 | Caption styling config with all defaults accepted | Pass |
| TC-WCF-CAP-02 | Caption styling config with custom values at boundaries | Pass |
| TC-WCF-CAP-03 | Caption styling config just outside boundaries | Fail with clear error |
| TC-WCF-CAP-04 | **Color format**: 6-char hex without `#` accepted (e.g., `ffffff`, `000000`) | Pass |
| TC-WCF-CAP-05 | **Color format**: `#FFFFFF` format rejected | Fail: invalid format |
| TC-WCF-CAP-06 | `font_file` path validation (if implemented) | Pass/fail appropriately |

### 3. Renderer Unit Tests (Python)

#### 3.1 Filter Graph Construction — `test_ffmpeg_vertical_renderer.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-FGR-CAP-01 | No captions: filter graph matches M6.1 exactly | No `drawtext` in graph |
| TC-FGR-CAP-02 | Captions enabled, one segment: single `drawtext` filter | Contains `drawtext` with correct params |
| TC-FGR-CAP-03 | Captions enabled, multiple segments: chained `drawtext` | Multiple `drawtext` filters in sequence |
| TC-FGR-CAP-04 | Caption filter position: after `fps`, before encode | Correct order in filter graph |
| TC-FGR-CAP-05 | `drawtext` params: fontfile, fontsize, fontcolor, borderw, bordercolor, box, boxcolor, boxborderw, x, y, enable | All present and correct |
| TC-FGR-CAP-06 | `enable='between(t,start_s,end_s)'` uses **seconds** (ms→s converted) | Correct time values in seconds |
| TC-FGR-CAP-07 | `captions.enabled=false`: no drawtext even if segments present | No `drawtext` |
| TC-FGR-CAP-08 | Empty segments array: no drawtext | No `drawtext` |
| TC-FGR-CAP-09 | Font file path from config used in drawtext | Matches config |
| TC-FGR-CAP-10 | Background opacity formatted as `color@opacity` (e.g., `000000@0.5`) | Correct syntax |
| TC-FGR-CAP-11 | **Color values passed to FFmpeg as 6-char hex without `#`** | `fontcolor=ffffff`, `bordercolor=000000`, `boxcolor=000000@0.5` |
| TC-FGR-CAP-12 | Pre-escaped caption text passed through unchanged to drawtext | Text not re-escaped |

#### 3.2 Selected Candidate Bounds with Captions — `test_ffmpeg_vertical_renderer.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-CSL-CAP-01 | Valid caption segments within candidate bounds | Renderer uses exactly those segments |
| TC-CSL-CAP-02 | Caption segments exceeding candidate duration | Clipped by enable filter (handled by Laravel projection) |
| TC-CSL-CAP-03 | Caption text with special chars (pre-escaped by Laravel) | Passed through unchanged |

#### 3.3 CLI Transport — `test_cli_render_clip.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-CLI-CAP-01 | Valid stdin JSON with top-level captions → stdout JSON, exit 0 | Success envelope |
| TC-CLI-CAP-02 | Invalid caption contract → exit 2, error envelope | `invalid_contract` |
| TC-CLI-CAP-03 | Runtime caption filter error (e.g., missing font) → exit 1 | `render_failed` |

### 4. Worker Integration Tests (Real FFmpeg)

#### 4.1 End-to-End Render with Captions — `test_render_clip_integration.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-E2E-CAP-01 | Real FFmpeg on fixture with caption segments | Output 1080x1920 MP4 with captions |
| TC-E2E-CAP-02 | Output duration matches candidate bounds (±50ms) | Duration within tolerance |
| TC-E2E-CAP-03 | **Output metadata uses probed values: video_codec from ffprobe (e.g., h264), NOT config value (libx264)** | Probe confirms |
| TC-E2E-CAP-04 | Filter graph in result parameters contains `drawtext` | Non-empty, contains drawtext |
| TC-E2E-CAP-05 | FFmpeg version recorded | Non-empty string |
| TC-E2E-CAP-06 | Source media metadata in parameters | Matches probe |
| TC-E2E-CAP-07 | **Caption styling configuration** in `parameters.configuration.captions` | Matches request config.captions |
| TC-E2E-CAP-08 | Without transcript: output matches M6.1 (no drawtext) | Regression: no captions |
| TC-E2E-CAP-09 | **SHA256 binding: worker computes from raw stdin, matches Laravel's json_encode(request)** | Pass (identical) |

**Fixture:** `tests/fixtures/render_source.mp4` — 1920x1080, 30 seconds, H.264/AAC, with audio.
**Caption fixture:** Deterministic transcript segments mapped to candidate timebase.

#### 4.2 Duration Accuracy with Captions — `test_render_clip_integration_duration.py`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-DUR-CAP-01 | Caption burn-in does not affect duration accuracy | ±50ms tolerance maintained |
| TC-DUR-CAP-02 | Multiple caption segments, no duration drift | ±50ms tolerance maintained |
| TC-DUR-CAP-03 | **Output metadata uses probed values (video_codec from ffprobe, e.g., h264, not config libx264)** | Assertions updated to expect probed values |

### 5. Laravel Feature/Integration Tests

#### 5.1 RenderMediaClip Job with Captions — `RenderMediaClipTest.php`

| Test ID | Scenario | Expected |
|---|---|---|
| TC-RMJ-CAP-01 | M5 completed, transcript completed, valid candidate_index → clip with captions | DerivedAsset completed, filter_graph has drawtext |
| TC-RMJ-CAP-02 | M5 completed, transcript absent/failed → clip without captions | DerivedAsset completed, filter_graph no drawtext |
| TC-RMJ-CAP-03 | M5 completed, transcript completed but empty segments → clip without captions | DerivedAsset completed, filter_graph no drawtext |
| TC-RMJ-CAP-04 | Transcript status pending → job throws UpstreamRecommendationUnavailableException | Exception thrown |
| TC-RMJ-CAP-05 | Version conflict: same render identity, transcript content changed | RenderVersionConflictException |
| TC-RMJ-CAP-06 | Version conflict: same render identity, transcript status changed completed→failed | RenderVersionConflictException |
| TC-RMJ-CAP-07 | **Version conflict: same render identity, caption config changed** | RenderVersionConflictException |
| TC-RMJ-CAP-08 | Idempotency: re-dispatch same params (with transcript) → returns same DerivedAsset | No duplicate, no re-render |
| TC-RMJ-CAP-09 | Retry after failure with transcript → new attempt, clears error | New rendering attempt |
| TC-RMJ-CAP-10 | Concurrent claim with captions: first locks, second gets busy | Second throws RenderBusyException |
| TC-RMJ-CAP-11 | Caption text with special chars (quotes, colons, backslashes) → escaped correctly | No FFmpeg injection, render succeeds |
| TC-RMJ-CAP-12 | Caption max_chars_per_line truncation → text split correctly | Render succeeds, text fits |

#### 5.2 Real Worker Integration — `RenderMediaClipRealWorkerTest.php` (new or extended)

| Test ID | Scenario | Expected |
|---|---|---|
| TC-RMR-CAP-01 | Full job with real worker, FFmpeg, transcript | DerivedAsset completed, file in storage, captions burned |
| TC-RMR-CAP-02 | Output file exists at expected key | Storage::exists() true |
| TC-RMR-CAP-03 | DerivedAsset render_parameters includes caption config and filter_graph | JSON matches spec |
| TC-RMR-CAP-04 | DerivedAsset output metadata matches probe | Within tolerance |
| TC-RMR-CAP-05 | Different candidate_index produces distinct DerivedAsset row | Two rows, different candidate_index |
| TC-RMR-CAP-06 | Without transcript: M6.1 regression still works | DerivedAsset completed, no drawtext |

#### 5.3 ProcessMediaAsset Regression — existing tests

| Test ID | Scenario | Expected |
|---|---|---|
| TC-PMA-REG-01 | ProcessMediaAsset completes after M5 without render stage | Asset completes, no render attempted |
| TC-PMA-REG-02 | No clipRenderResolved flag exists on MediaAsset | Confirmed absent |
| TC-PMA-REG-03 | Existing upstream stages unchanged | All pass as before |
| TC-PMA-REG-04 | Asset finalization does not require render | Asset completes with only existing resolved stages |

### 6. Existing Frontend / E2E Regression

No new frontend behavior, public render API, browser interaction, or visual surface is introduced by M6.2.

| Test ID | Scenario | Expected |
|---|---|---|
| TC-E2E-REG-01 | Existing frontend/E2E CI suite | Remains green with no M6.2 regressions |
| TC-E2E-REG-02 | Browser visual review for new rendering UI | N/A — no rendering UI exists in this issue |
| TC-E2E-REG-03 | New Playwright scenarios / viewport matrix | N/A — no new browser behavior |

## Test Data Requirements

| Fixture | Description |
|---|---|
| `render_source.mp4` | 1920x1080, 30s, H.264/AAC, horizontal content suitable for center-crop |
| M5 recommendation fixture | Completed, ranked, K=3 candidates with semantic_rank 1,2,3 and non-null scores |
| Probe fixture | duration_ms=30000, width=1920, height=1080, video_codec=h264, audio_codec=aac |
| MediaTranscript fixture | Completed, segments with varied timing overlapping candidate bounds, text with special chars |
| Caption config fixture | All defaults + overrides for boundary testing |

## Test Environment

- Worker CI: FFmpeg 6.x+ with `drawtext` and FreeType support, Python 3.12, pytest, DejaVu Sans Bold font
- Laravel CI: PHP 8.3+, PostgreSQL 16, Pest/PHPUnit
- Existing Frontend/E2E CI: run unchanged repository-required checks; no new Playwright/viewport requirement for M6.2
- Storage: S3-compatible (MinIO in CI), `renders/` prefix

## Acceptance Gate

All applicable test scenarios above must pass. Browser visual review and new Playwright coverage are N/A because M6.2 introduces no UI/public API; existing Frontend/E2E required checks must remain green. No test may be weakened, skipped, or removed to achieve pass. Full M1-M6.1 regression baseline must be preserved.

## Traceability Matrix

| Spec Section | Test Categories |
|---|---|
| Authoritative inputs & readiness | 5.1 (TC-RMJ-CAP-01 through TC-RMJ-CAP-12) |
| Algorithm: configuration (base + captions styling) | 1.1, 2.2, 4.1 |
| Caption segment projection (Laravel) | 1.3, 5.1 |
| Worker contract: top-level captions object | 2.1 |
| Worker contract: configuration.captions styling only | 2.1 (TC-WCV-CAP-10, TC-WCV-CAP-11) |
| Filter graph: drawtext chain | 3.1 |
| Text escaping security (Laravel) | 1.3 (TC-MTS-09, TC-MTS-10, TC-MTS-11), 5.1 (TC-RMJ-CAP-11) |
| Color format: 6-char hex no `#` | 1.1 (TC-LUP-CAP-05,06,08,18), 2.2 (TC-WCF-CAP-04,05), 3.1 (TC-FGR-CAP-11) |
| Time: ms in contract, s in FFmpeg | 3.1 (TC-FGR-CAP-06) |
| Laravel-owned output naming | 1.4 (regression) |
| Worker output-storage passthrough | 4.1 |
| Result serialization: filter_graph with captions | 1.2, 4.1 |
| Errors & privacy (caption text never logged) | 5.1, 3.3 |
| Persistence: DerivedAsset extension | 5.1, 5.2 |
| Validation: independent Laravel | 1.2, 5.1 |
| **Validation contract: SHA256 binding (identical serialization)** | 1.2 (TC-LRV-CAP-16, TC-LRV-CAP-17), 4.1 (TC-E2E-CAP-09) |
| **Validation contract: output metadata from probe (not config)** | 1.2 (TC-LRV-CAP-18), 4.1 (TC-E2E-CAP-03), 4.2 (TC-DUR-CAP-03) |
| Lifecycle & concurrency | 5.1 (TC-RMJ-CAP-10) |
| RenderMediaClip job boundary | 5.1, 5.2 |
| ProcessMediaAsset non-integration | 5.3 |
| Version conflict: transcript changes | 5.1 (TC-RMJ-CAP-05, TC-RMJ-CAP-06) |
| Version conflict: caption config changes | 5.1 (TC-RMJ-CAP-07) |

## Out of Scope Test Scenarios (Do Not Implement)

- SRT/VTT generation validation
- Caption editor UI tests
- Multi-style caption tests
- Face-aware caption placement tests
- Caption animation tests
- Multi-language caption selection tests
- Smart crop / face tracking tests
- Multi-clip / batch rendering tests
- Clip review UI tests
- Social publishing integration tests
- ProcessMediaAsset automatic render stage tests
- Hardcoded candidate_index=0 / semantic_rank=1 default tests