# Implementation Plan: Issue #76 — Deterministic Caption Burn-In for Explicit Vertical Clip Rendering

## Authority

This plan derives exclusively from `specs/076-m6-2-caption-burn-in/spec.md`. Planner owns this file. Builder executes; Tester validates; Orchestrator coordinates.

## Stage A — Laravel Caption Projection & Configuration Extensions

### Behavior

- Extend `RenderProfile` with caption configuration defaults and validation (already done in M6.1 baseline — verify alignment with resolved spec).
- Extend `RenderValidator` to validate the optional **top-level** `captions` object in worker request and response.
- Add caption projection logic to `RenderMediaClip` job: load `MediaTranscript`, map segments to candidate-local timebase, build `captions.segments` array with **escaped text**.
- Extend `MediaProcessingContract::renderClipRequest()` to include optional **top-level** `captions` object when transcript is available and enabled.
- Update `StorageKeyBuilder` — no change (same identity format; captions don't affect identity).
- Add transcript state to authority snapshot for version conflict detection (transcript completed/absent/failed + content hash).

### Likely files/categories

- `apps/api/app/Services/RenderProfile.php` (caption config defaults, validation — verify against spec)
- `apps/api/app/Services/RenderValidator.php` (caption request/result validation)
- `apps/api/app/Jobs/RenderMediaClip.php` (caption projection, authority snapshot extension)
- `apps/api/app/Contracts/MediaProcessingContract.php` (caption inclusion in renderClipRequest — top-level object)
- `apps/api/app/Services/StorageKeyBuilder.php` (no change)
- `apps/api/app/Models/MediaTranscript.php` (verify `escapeCaptionText()` and `projectSegmentsToCandidate()`)
- `apps/api/tests/Unit/RenderProfileTest.php` (caption config tests)
- `apps/api/tests/Unit/RenderValidatorTest.php` (caption validation tests)
- `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php` (caption projection tests)

### Targeted RED

- `php artisan test --filter=RenderProfileTest` (expect failure on caption config defaults/validation)
- `php artisan test --filter=RenderValidatorTest` (expect failure on caption validation)
- `php artisan test --filter=RenderMediaClipTest` (expect failure on caption projection/authority)

### Targeted GREEN commands

```bash
# Run RenderProfile tests
php artisan test --filter=RenderProfileTest

# Run RenderValidator tests
php artisan test --filter=RenderValidatorTest

# Run RenderMediaClip tests (caption projection)
php artisan test --filter=RenderMediaClipTest

# Run migration (if needed)
php artisan migrate --force
```

### Regression sentinels

- `RenderProfileTest`: TC-LUP-01 through TC-LUP-06 (existing M6.1 config) + new caption tests
- `RenderValidatorTest`: TC-LRV-01 through TC-LRV-18 (existing M6.1 validation) + new caption tests
- `RenderMediaClipTest`: TC-RMJ-01 through TC-RMJ-15 (existing M6.1 job tests) + new caption tests
- M6.1 `RenderMediaClip` job tests must remain green
- M5 `rank_clips` tests must remain green

### Stop condition before Stage B

- All Stage A tests pass
- `render_profile_version` = `'vertical_v1'` (unchanged)
- Caption configuration defaults applied and validated (match spec exactly)
- Caption projection maps segments correctly to candidate-local timebase with **escaped text**
- Authority snapshot includes transcript state + content hash
- M6.1 regression sentinels green

---

## Stage B — Worker Contract Extension & Singular Caption Handling

### Behavior

- **Update `media_processing_v1.json`**: 
  - `render_clip_request` definition: move `captions` OUT of `configuration.properties` to **top-level** optional object.
  - Top-level `captions` object: `{ "enabled": boolean, "segments": [...] }` — segments contain `{start_ms, end_ms, text}`.
  - `configuration.captions` retains styling only (enabled, font_file, font_size, font_color, outline_color, outline_width, background_color, background_opacity, box_padding, margin_bottom, max_chars_per_line).
- **Update `contracts.py`**:
  - Validate top-level `captions` object (schema + runtime).
  - Validate `configuration.captions` styling object (without segments).
  - Color validation: accept **6-char hex WITHOUT `#`** (e.g., `ffffff`, `000000`).
  - Time validation: segment timing in milliseconds (candidate-local).
- **Update `render_clip.py` action**: 
  - Accept and pass through top-level `captions` object to renderer.
  - Pass `configuration.captions` (styling) to renderer.
- **Update `rendering.py` `FFmpegVerticalClipRenderer.render_singular()`**:
  - Build caption filter graph when top-level `captions` present AND `captions.enabled=true` AND `captions.segments` non-empty.
  - Caption filter graph: chain `drawtext` filters after FPS, before encode.
  - Combine styling from `configuration.captions` with segments from `captions.segments`.
  - Convert segment ms → seconds for FFmpeg `enable='between(t,start_s,end_s)'`.
  - Use pre-escaped caption text as-is (Laravel already escaped).
  - Color values: pass 6-char hex to FFmpeg (FFmpeg accepts both with/without `#`; use without per spec).
  - Graceful fallback: if `captions` absent or `captions.enabled=false`, behave as M6.1.

### Likely files/categories

- `services/worker/contracts/media_processing_v1.json` (schema extension — move captions to top-level)
- `services/worker/aiclip_worker/contracts.py` (caption validation — top-level + config.captions styling; 6-char hex color)
- `services/worker/aiclip_worker/actions/render_clip.py` (pass-through both config.captions and top-level captions)
- `services/worker/aiclip_worker/rendering.py` (filter graph extension — combine styling + segments; ms→s conversion; **fix output metadata to return probed values from ffprobe, not configuration values**)
- `services/worker/tests/test_render_clip_contract_validation.py` (caption contract tests)
- `services/worker/tests/test_ffmpeg_vertical_renderer.py` (caption filter graph tests)
- `services/worker/tests/test_render_configuration.py` (caption config validation)
- `services/worker/tests/test_render_clip_integration_duration.py` (**update assertions to expect probed `video_codec` (e.g., `h264`) not configuration value (`libx264`)**)

### Targeted RED

- `cd services/worker && python -m pytest tests/test_render_clip_contract_validation.py -v` (expect failure on caption schema)
- `cd services/worker && python -m pytest tests/test_ffmpeg_vertical_renderer.py -v` (expect failure on caption filter graph)
- `cd services/worker && python -m pytest tests/test_render_configuration.py -v` (expect failure on caption config)
- `cd services/worker && python -m pytest tests/test_render_clip_integration_duration.py -v` (expect failure on output metadata assertions)
- `cd services/worker && python -m pytest tests/test_rank_clips.py tests/test_contract_rank_clips.py -v` (must pass; if fails, revert)

### Targeted GREEN commands

```bash
# Run render_clip contract validation
cd services/worker && python -m pytest tests/test_render_clip_contract_validation.py -v

# Run renderer unit tests (caption filter graph)
cd services/worker && python -m pytest tests/test_ffmpeg_vertical_renderer.py -v

# Run render configuration tests
cd services/worker && python -m pytest tests/test_render_configuration.py -v

# Run M5 rank_clips sentinels (must pass)
cd services/worker && python -m pytest tests/test_rank_clips.py tests/test_contract_rank_clips.py -v

# Run full worker test suite (excluding Stage C integration)
cd services/worker && python -m pytest tests/ -v --ignore=tests/test_render_clip_integration.py --ignore=tests/test_render_clip_integration_duration.py
```

### Regression sentinels

- All M5 `rank_clips` tests pass unchanged
- `test_render_clips_contract_validation.py` + `test_cli_render_clips.py` (legacy plural) remain green
- M6.1 singular `test_render_clip_contract_validation.py` + `test_cli_render_clip.py` pass
- `test_render_configuration.py` passes
- No regression in `test_contract_detect_scenes.py`, `test_cli.py`

### Stop condition before Stage C

- Caption schema validation works (top-level optional object, correct structure)
- Caption filter graph generates correct `drawtext` chain with combined styling + segments
- ms→seconds conversion correct in `enable` filter
- 6-char hex color format accepted and passed to FFmpeg
- Caption configuration validated at boundaries (styling in config.captions, segments in top-level)
- M5 sentinels green
- All worker unit tests green

---

## Stage C — Laravel-to-Worker Integration + Real FFmpeg Caption Burn-In

### Behavior

- Laravel `RenderMediaClip` job projects captions from `MediaTranscript` (with escaped text) and includes in worker contract as **top-level `captions` object**.
- `ProcessMediaAction::renderClips()` sends extended contract to `render-clip` subcommand. **Laravel computes SHA256 from exact JSON bytes sent to worker stdin (`json_encode($request, JSON_THROW_ON_ERROR)`).**
- Real `FFmpegVerticalClipRenderer.render_singular()` executes FFmpeg with caption filter graph.
- **Worker probes output file with ffprobe and returns probed metadata in `output` object (video_codec, audio_codec, width, height, duration_ms, video_bitrate_kbps, audio_bitrate_kbps), NOT configuration values.**
- Worker computes SHA256 from raw stdin bytes read; must match Laravel's computed hash exactly (identical serialization, deterministic key ordering).
- FFprobe validation includes checking output duration, resolution, codec, and filter graph presence.
- Idempotency/concurrency: retry uses persisted storage key; durable pending claim already has `storage_disk`/`storage_key`.
- Caption filter graph recorded in result `parameters.filter_graph`.
- Caption config recorded in `parameters.configuration.captions`.

### Likely files/categories

- `apps/api/app/Jobs/RenderMediaClip.php` (full integration with captions)
- `apps/api/app/Services/ProcessMediaAction.php` (renderClips timeout/config unchanged; **ensure SHA256 computed from exact stdin bytes**)
- `services/worker/aiclip_worker/rendering.py` (real FFmpeg caption rendering; **fix output metadata to return probed values; ensure SHA256 computed from raw stdin**)
- `services/worker/aiclip_worker/errors.py` (sanitized error mapping for caption failures)
- `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php` (real worker integration)
- `apps/api/tests/Feature/Jobs/ProcessMediaAssetRenderRealWorkerTest.php` (regression)
- `services/worker/tests/test_render_clip_integration.py` (real FFmpeg caption integration)
- `services/worker/tests/test_render_clip_integration_duration.py` (duration tolerance with captions; **update assertions for probed output metadata**)
- `apps/api/tests/Unit/RenderValidatorTest.php` (completion validation with captions)

### Targeted RED

- `php artisan test tests/Feature/Jobs/RenderMediaClipTest.php` (expect failure on caption integration)
- `php artisan test tests/Feature/Jobs/ProcessMediaAssetRenderRealWorkerTest.php` (regression)
- `cd services/worker && python -m pytest tests/test_render_clip_integration.py -v` (expect failure on FFmpeg caption integration)
- `cd services/worker && python -m pytest tests/test_render_clip_integration_duration.py -v` (expect failure on duration tolerance with captions / output metadata)
- `php artisan test --filter=RenderValidatorTest` (completion validation with captions)

### Targeted GREEN commands

```bash
# Run explicit RenderMediaClip job tests
php artisan test tests/Feature/Jobs/RenderMediaClipTest.php

# Run existing real-worker regression/integration test
php artisan test tests/Feature/Jobs/ProcessMediaAssetRenderRealWorkerTest.php

# Run worker real integration tests (requires FFmpeg with drawtext)
cd services/worker && python -m pytest tests/test_render_clip_integration.py -v

# Run worker duration tolerance tests
cd services/worker && python -m pytest tests/test_render_clip_integration_duration.py -v

# Run RenderValidator tests
php artisan test --filter=RenderValidatorTest
```

### Regression sentinels

- `apps/api/tests/Feature/Jobs/ProcessMediaAssetRenderTest.php`: corrected regression coverage proves ProcessMediaAsset completes without a render stage
- `apps/api/tests/Feature/Jobs/ProcessMediaAssetRenderRealWorkerTest.php`: no automatic render coupling remains
- `apps/api/tests/Feature/Models/DerivedAssetRenderedClipTest.php`: all existing/corrected render persistence tests pass
- M5 `rank_clips` tests remain green after any worker contract/schema/CLI change
- `WorkerBoundaryTest` (Laravel/worker boundary) passes
- Full applicable backend suite against PostgreSQL passes
- Real FFmpeg/FFprobe integration with captions: verified in worker integration tests

### Stop condition before Stage D

- End-to-end render with real FFmpeg produces valid `DerivedAsset` (status completed) with captions burned in
- Duration tolerance ±50ms validated by both worker (FFprobe) and Laravel (RenderValidator)
- Storage key format correct: `projects/{project_id}/renders/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.mp4`
- Unique identity constraint enforced (re-read authority on retry, including transcript state)
- M5 `rank_clips` tests still green
- A+B+C targeted tests green

---

## Stage D — Full Regression/Evidence

### Behavior

- Run full test suite: Laravel unit/feature, worker unit/integration.
- No new UI/public API; API review N/A.
- No new Playwright tests; Visual/Playwright review N/A for this issue (regression only).
- Frontend/E2E existing CI regression suites must be green.
- Update `evidence.md` with ACTUAL `### RED`, `### GREEN`, `### REFACTOR` sections only after execution.
- Stop at `CI_GREEN_WAITING_HUMAN_MERGE`; no merge, no issue closure.

### Likely files/categories

- All Laravel test files under `apps/api/tests/`
- All Python worker tests under `services/worker/tests/`
- Existing Playwright E2E tests (no new)
- `evidence.md`

### Targeted RED

- Any test that fails due to delta changes (run full suites to detect)

### Targeted GREEN commands

```bash
# Full Laravel test suite
php artisan test

# Full worker test suite
cd services/worker && python -m pytest tests/ -v

# Existing Frontend/E2E regression suites (if any)
# (e.g., npm test or playwright test for existing scenarios)
```

### Regression sentinels

- Targeted M5 `rank_clips` tests: `test_rank_clips.py`, `test_contract_rank_clips.py`
- All singular M6 worker tests: `test_render_clip_*`, `test_cli_render_clip_*`, `test_render_clip_integration_*`
- Full worker pytest: `services/worker/tests/`
- `RenderValidator` tests: `apps/api/tests/Unit/RenderValidatorTest.php`
- `RenderMediaClip` tests: `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php`
- Real-worker/no-auto-render regression: `apps/api/tests/Feature/Jobs/ProcessMediaAssetRenderRealWorkerTest.php`
- `DerivedAsset` rendered clip tests: `apps/api/tests/Feature/Models/DerivedAssetRenderedClipTest.php`
- `ProcessMediaAsset` regression: `apps/api/tests/Feature/Jobs/ProcessMediaAssetRenderTest.php`
- `WorkerBoundaryTest`: `apps/api/tests/Unit/WorkerBoundaryTest.php`
- Full applicable backend suite against PostgreSQL: `php artisan test --group=backend`
- Real FFmpeg/FFprobe integration with captions: verified in worker integration tests
- Governance: `git diff --check` passes, exact-scope review (only files in plan modified)
- Frontend/E2E existing CI regression suites: green

### Stop condition before commit/push

- All regression sentinels green
- `evidence.md` updated with actual RED/GREEN/REFACTOR
- Fresh independent Tester APPROVE
- Orchestrator makes small atomic commits, normal push, one replacement PR with `Closes #76`
- Monitor CI, stop at `CI_GREEN_WAITING_HUMAN_MERGE` (do not merge automatically)