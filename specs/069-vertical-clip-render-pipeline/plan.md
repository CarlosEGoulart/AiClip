# Implementation Plan: Issue #69 — Durable Baseline Vertical Clip Render Pipeline

## Authority

This plan derives exclusively from `specs/069-vertical-clip-render-pipeline/spec.md`. Planner owns this file. Builder executes; Tester validates; Orchestrator coordinates.

## Phase 0 — Prerequisites (Human-gated, blocking)

- [ ] **P0.1** Operator confirms FFmpeg 6.x+ availability in worker container/image (`ffmpeg -version` in CI).
- [ ] **P0.2** Operator provisions/confirms S3-compatible bucket for render outputs (reuse media bucket with `renders/` prefix).
- [ ] **P0.3** Operator confirms `media.render_timeout_seconds` default 300s is acceptable for CI envelope (2 CPU, 2 GiB RAM, 4 GiB disk).
- [ ] **P0.4** Orchestrator confirms branch `@carlosegoulart/69/feat/vertical-clip-render-pipeline` exists and is clean.
- [ ] **P0.5** Orchestrator confirms `specs/069-vertical-clip-render-pipeline/` directory exists (created by Orchestrator).

> **Gate:** No implementation tasks may begin until all P0 items are checked by Operator/Orchestrator.

## Phase 1 — Database & Models (Laravel)

### 1.1 Migration: Extend `derived_assets` table

- [ ] **1.1.1** Create migration `add_render_columns_to_derived_assets_table`.
- [ ] **1.1.2** Add columns: `candidate_index` (integer, nullable), `render_profile_version` (string, nullable), `render_configuration` (JSONB, nullable), `render_parameters` (JSONB, nullable), `render_error` (string, nullable).
- [ ] **1.1.3** Add unique composite index on `(media_asset_id, type, candidate_index, render_profile_version)` where `type = 'clip_rendered'`.
- [ ] **1.1.4** Run migration; verify schema in PostgreSQL.

### 1.2 Model: `DerivedAsset` updates

- [ ] **1.2.1** Add `TYPE_RENDERED_CLIP = 'clip_rendered'` constant.
- [ ] **1.2.2** Add fillable for new render columns.
- [ ] **1.2.3** Add casts for JSONB columns.
- [ ] **1.2.4** Add `renderedClips()` scope on `MediaAsset` relationship (where `type = TYPE_RENDERED_CLIP`).

### 1.3 Model updates: `MediaAsset`

- [ ] **1.3.1** Add `renderedClips()` hasMany relationship scoped to `type = 'clip_rendered'`.
- [ ] **1.3.2** Add `clipRenderResolved` accessor/logic for asset finalization (Phase 5).

### 1.4 Model updates: `MediaClipRecommendation`

- [ ] **1.4.1** No new relationship needed (render metadata lives on DerivedAsset).

## Phase 2 — Worker Contract & Schema (Python)

### 2.1 Contract schema extension

- [ ] **2.1.1** Edit `services/worker/contracts/media_processing_v1.json`:
  - Add `render_clips_request` definition under `definitions`.
  - Add `render_clips_response` definition under `definitions`.
  - Request fields: version, action, media{duration_ms}, recommendation{candidates[], candidate_index}, configuration, source_media{disk, key, width, height, video_codec, audio_codec}.
  - Response: status, render{algorithm, algorithm_version, parameters, clips[]}.
- [ ] **2.1.2** Keep backward compatibility; v1.0.0 version unchanged.
- [ ] **2.1.3** Remove `transcript_segments` from request (captions out of scope).

### 2.2 Contract validation (`services/worker/aiclip_worker/contracts.py`)

- [ ] **2.2.1** Add `RENDER_CLIPS_VERSION = "1.0.0"`, `RENDER_CLIPS_ACTION = "render_clips"`.
- [ ] **2.2.2** Add `RENDER_CLIPS_REQUEST_KEYS`, `RENDER_CLIPS_RECOMMENDATION_KEYS`, `RENDER_CLIPS_MEDIA_KEYS`, `RENDER_CLIPS_CONFIG_KEYS`, `RENDER_CLIPS_SOURCE_MEDIA_KEYS`.
- [ ] **2.2.3** Add `validate_render_clips_contract(contract)` with schema + runtime validation (mirror `validate_rank_clips_contract`).
- [ ] **2.2.4** Update `validate_contract()` to route `render_clips` to new validator.

### 2.3 Configuration profile (`services/worker/aiclip_worker/rendering.py` — new file)

- [ ] **2.3.1** Create `RenderConfiguration` dataclass with all 7 configurable fields + validation (`__post_init__`).
- [ ] **2.3.2** Add `from_dict()` classmethod.
- [ ] **2.3.3** Add fixed limits/policies as module constants.

## Phase 3 — Renderer Implementation (Python)

### 3.1 Renderer interface & FFmpeg implementation

- [ ] **3.1.1** Create `services/worker/aiclip_worker/rendering.py`:
  - `VerticalClipRenderer` ABC with `render(validated_input, configuration) -> RenderResult`.
  - `FFmpegVerticalClipRenderer` implementation.
  - `RenderInput` dataclass: `duration_ms`, `recommendation` (with candidates), `candidate_index`, `source_media{disk, key, width, height, video_codec, audio_codec}`.
  - `RenderResult` dataclass: `parameters`, `clips[]` (single element), `algorithm`, `algorithm_version`.
  - `RenderCandidate` dataclass for input candidates.
  - `RenderedClip` dataclass for output clip.

### 3.2 FFmpeg filter graph builder

- [ ] **3.2.1** Private method `_build_filter_graph(candidate, config, source_width, source_height) -> str`.
- [ ] **3.2.2** Implement center-crop: `crop=ih*9/16:ih:(iw-ih*9/16)/2:0`.
- [ ] **3.2.3** Implement scale+pad to target resolution.
- [ ] **3.2.4** Implement fps filter.
- [ ] **3.2.5** No caption filter chain (captions out of scope).
- [ ] **3.2.6** No `face_aware` or `smart_crop` logic (not in scope).

### 3.3 FFmpeg execution

- [ ] **3.3.1** Method `_run_ffmpeg(input_path, output_path, filter_graph, config) -> dict` returning output metadata.
- [ ] **3.3.2** Use `subprocess.run()` with timeout, capture stdout/stderr.
- [ ] **3.3.3** Probe output file for duration, width, height, codecs, bitrate (ffprobe).
- [ ] **3.3.4** Handle FFmpeg errors → raise `RenderFailed` (custom exception).

### 3.4 Clip selection logic

- [ ] **3.4.1** Validate `candidate_index` is within bounds of candidates array.
- [ ] **3.4.2** Validate selected candidate has non-null `semantic_score`.
- [ ] **3.4.3** If validation fails → raise `InvalidCandidateIndex` (custom exception).

### 3.5 Output naming & metadata

- [ ] **3.5.1** Generate output key: `renders/{media_asset_id}/{recommendation_id}/{candidate_index}_{timestamp}.mp4`.
- [ ] **3.5.2** Timestamp: UTC ISO8601 without separators.
- [ ] **3.5.3** Build single clip metadata object per spec (no caption fields).

### 3.6 CLI action: `render-clips`

- [ ] **3.6.1** Create `services/worker/aiclip_worker/actions/render_clips.py`.
- [ ] **3.6.2** `run_cli(argv)` mirrors `analyze_clips.py` pattern: stdin JSON, bounded input, strict JSON output, exit codes 0/1/2.
- [ ] **3.6.3** Register subcommand in `services/worker/aiclip_worker/cli.py`.

## Phase 4 — Laravel Orchestration & Validation

### 4.1 Configuration profile (`app/Services/RenderProfile.php` — new)

- [ ] **4.1.1** Static `configuration()` returning the 7 configurable fields with defaults.
- [ ] **4.1.2** Static `timeoutSeconds()` → `config('media.render_timeout_seconds', 300)` with strict integer 30..1800 validation (canonical decimal string grammar per M5 pattern).
- [ ] **4.1.3** Static `lockWaitSeconds()` = `timeoutSeconds() + 5`.

### 4.2 Validator (`app/Services/RenderValidator.php` — new)

- [ ] **4.2.1** `request(array $data)` — validate request before process creation (mirror `ClipRecommendationValidator::request()`).
- [ ] **4.2.2** `result(object $output, array $request)` — validate worker response, compare to captured request, SHA256 binding.
- [ ] **4.2.3** `validateCompletion(array $result, array $inputSnapshot, array $executionParameters)` — model-level validation for `DerivedAsset` completion.
- [ ] **4.2.4** Independent rederivation: verify clip's candidate_index, bounds, output file metadata, filter graph presence.

### 4.3 MediaProcessingContract extensions

- [ ] **4.3.1** Add `toRenderMetadataArray()` method (privacy-safe payload).
- [ ] **4.3.2** Add static `renderClipsRequest()` building request from authoritative inputs (duration, recommendation, candidate_index, configuration, source media info from probe).
- [ ] **4.3.3** Update `validate()` to handle `render_clips` action.
- [ ] **4.3.4** Update `fromArray()` to parse `render_clips` via `RenderValidator::request()`.

### 4.4 ProcessMediaAction::renderClips()

- [ ] **4.4.1** Add `renderClips(MediaProcessingContract $contract): array` method.
- [ ] **4.4.2** Validate contract, timeout config (strict integer 30..1800).
- [ ] **4.4.3** Create process with `render-clips` subcommand, stdin contract JSON.
- [ ] **4.4.4** Set timeout, run, capture output (bounded).
- [ ] **4.4.5** Decode JSON (object not list), validate via `RenderValidator::result()`.
- [ ] **4.4.6** Error handling: classified failures → `ProcessMediaException` with fixed codes; unexpected → `clip_render_aborted`.

## Phase 5 — ProcessMediaAsset Integration

### 5.1 Clip Render stage in job

- [ ] **5.1.1** Add `clipRenderResolved` flag and logic after M5 stage (around line 1055 in `ProcessMediaAsset.php`).
- [ ] **5.1.2** Check existing `DerivedAsset` for this asset with `type='clip_rendered'` and matching `candidate_index` + `render_profile_version`.
- [ ] **5.1.3** Terminal reuse: if completed and matches current configuration/recommendation authority → skip.
- [ ] **5.1.4** Version conflict: different M5 authority or configuration → `render_version_conflict` (non-retryable).
- [ ] **5.1.5** Readiness checks:
  - M5 completed/ranked with candidates and valid `candidate_index` → ready.
  - M5 completed/unavailable/failed/missing → claim attempt, mark failed with appropriate error.
  - M5 pending/ranking/not_ready → `not_ready` retry (max 3, 5s delay).
  - No M5 row after M5 resolved → `upstream_recommendation_missing`.
  - Invalid `candidate_index` or null `semantic_score` → `invalid_candidate_index`.
- [ ] **5.1.6** Build render contract: duration, recommendation (with candidates), candidate_index, configuration from `RenderProfile::configuration()`, source media info from probe.
- [ ] **5.1.7** Atomic claim transaction (mirror M4/M5 pattern):
  - Insert-or-ignore pending row on `DerivedAsset` (status=pending).
  - Lock for update, reread.
  - If completed → reuse.
  - Transition to rendering, capture input_snapshot, execution_parameters.
  - Invoke `ProcessMediaAction::renderClips()`.
  - Validate result, mark completed or mark failed inside transaction.
  - Handle lock timeout → `busy` return.
  - Handle abort → `clip_render_aborted`.

### 5.2 Asset finalization update

- [ ] **5.2.1** Update asset completion condition to include `clipRenderResolved`.
- [ ] **5.2.2** Preserve existing behavior: controlled upstream failures are resolved stages; asset can complete while failed render records reason.

## Phase 6 — Configuration

### 6.1 Config file (`config/media.php`)

- [ ] **6.1.1** Add `render_timeout_seconds` env default `'300'` (string, no cast).
- [ ] **6.1.2** Document all 7 render configuration keys in comments.

### 6.2 Environment example (`.env.example`)

- [ ] **6.2.1** Add `MEDIA_RENDER_TIMEOUT_SECONDS=300`.

## Phase 7 — Tests

### 7.1 Worker unit tests (`services/worker/tests/`)

- [ ] **7.1.1** `test_render_clips_contract_validation.py` — schema + runtime validation (valid, invalid version, invalid action, missing fields, unknown fields, invalid config, invalid recommendation, invalid candidate_index, limits).
- [ ] **7.1.2** `test_ffmpeg_vertical_renderer.py` — filter graph construction (center crop, scale, fps), candidate selection by index, output naming.
- [ ] **7.1.3** `test_cli_render_clips.py` — CLI transport (stdin, stdout, exit codes, error envelopes, NaN rejection, size bounds).
- [ ] **7.1.4** `test_render_configuration.py` — configuration validation, defaults, bounds, enums.

### 7.2 Worker integration tests (real FFmpeg)

- [ ] **7.2.1** `test_render_clips_integration.py` — real FFmpeg on fixture video, verify output file exists, correct resolution (1080x1920), duration matches candidate bounds.
- [ ] **7.2.2** Fixture: `tests/fixtures/render_source.mp4` (short horizontal video with audio).
- [ ] **7.2.3** Test with different candidate indices.

### 7.3 Laravel unit tests (`apps/api/tests/`)

- [ ] **7.3.1** `RenderProfileTest.php` — timeout validation (canonical decimal string, range, null rejection), lock wait derivation, configuration shape.
- [ ] **7.3.2** `RenderValidatorTest.php` — request validation, response validation (success, missing fields, type mismatches, candidate mismatch, SHA256 mismatch), completion validation.
- [ ] **7.3.3** `DerivedAssetRenderedClipTest.php` — status transitions, unique constraint, scope, render column persistence.

### 7.4 Laravel feature/integration tests

- [ ] **7.4.1** `ProcessMediaAssetRenderTest.php` — full job execution with mocked worker (recording action), all readiness states, retry logic, terminal reuse, version conflict, invalid candidate_index, concurrency (lock contention).
- [ ] **7.4.2** `ProcessMediaAssetRenderRealWorkerTest.php` — real worker subprocess (requires FFmpeg fixture), validates end-to-end persistence, DerivedAsset creation, output file in storage.

### 7.5 E2E / Playwright tests

- [ ] **7.5.1** No new UI — existing workflows unchanged. Add regression test confirming upload→process→complete still works and no console/network errors.

## Phase 8 — Documentation & Reconciliation

### 8.1 Project state update

- [ ] **8.1.1** Update `docs/project-state.md`: M6.1 in progress, rendering pipeline added.
- [ ] **8.1.2** Update `docs/architecture.md`: Current execution topology includes render stage.

### 8.2 Architecture decision record (if needed)

- [ ] **8.2.1** No new ADR required; follows existing patterns.

## Phase 9 — CI & Governance

### 9.1 CI pipeline updates

- [ ] **9.1.1** Ensure worker CI installs FFmpeg (already present for scene detection).
- [ ] **9.1.2** Add render integration test to worker test suite.
- [ ] **9.1.3** Verify Laravel test suite includes new feature tests.

### 9.2 Governance

- [ ] **9.2.1** No governance changes required.

## Phase 10 — Evidence & Handoff

### 10.1 Evidence file

- [ ] **10.1.1** Builder creates `specs/069-vertical-clip-render-pipeline/evidence.md` with actual `### RED`, `### GREEN`, `### REFACTOR` sections per TDD.

### 10.2 Final review

- [ ] **10.2.1** All tests pass (backend, frontend, worker, E2E, governance).
- [ ] **10.2.2** Tester approves running behavior (Playwright visual QA at 3 viewports, console/network clean).
- [ ] **10.2.3** Orchestrator reviews CI logs: Backend CI, Worker CI, E2E CI, Governance, PR Enforcement.
- [ ] **10.2.4** Stop at `CI_GREEN_WAITING_HUMAN_MERGE`.

## Task Dependency Graph (critical path)

```
P0.1–P0.5
    ↓
1.1 → 1.2 → 1.3/1.4
    ↓
2.1 → 2.2 → 2.3
    ↓
3.1 → 3.2 → 3.3 → 3.4 → 3.5 → 3.6
    ↓
4.1 → 4.2 → 4.3 → 4.4
    ↓
5.1 → 5.2
    ↓
6.1 → 6.2
    ↓
7.1–7.5 (parallel, after respective implementation phases)
    ↓
8.1–8.2
    ↓
9.1–9.2
    ↓
10.1 → 10.2
```

## Estimated effort (relative)

| Phase | Scope | Est. complexity |
|---|---|---|
| 1 | DB/Models | Medium |
| 2 | Contract/Schema | Low |
| 3 | Renderer/FFmpeg | High (core logic) |
| 4 | Laravel Orchestration | Medium-High |
| 5 | Job Integration | Medium |
| 6 | Config | Low |
| 7 | Tests | High (coverage breadth) |
| 8 | Docs | Low |
| 9 | CI | Low |
| 10 | Evidence | Low |

## Risk mitigation

| Risk | Mitigation |
|---|---|
| FFmpeg version mismatch in CI | Pin version in Dockerfile; test `ffmpeg -version` in CI setup |
| Storage permission / bucket policy | Operator confirms write access in P0.2; integration test writes real file |
| Long FFmpeg runtime in CI | Timeout 300s; fixture video <30s; CI resource envelope verified in P0.3 |
| Concurrent claim race | Follow proven M4/M5 pattern; test with separate DB connections |
| Configuration drift between Laravel/Python | Single source of truth in `RenderProfile::configuration()`; both sides validate |

## Out of scope for this plan (explicit)

- Face-aware smart crop implementation
- Multi-aspect rendering (9:16 only)
- Captions, subtitle generation, burn-in
- Clip review UI (M7)
- Social publishing (M10+)
- Progress streaming / webhooks
- Transcription/scene re-generation
- Multiple clips per render job
- Automatic top-N candidate selection