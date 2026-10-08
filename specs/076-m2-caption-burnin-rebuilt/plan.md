# Implementation Plan: Issue #76 — M6.2 Caption Burn-in Slice 1

## Authority

This plan derives exclusively from `specs/76-m2-caption-burnin-rebuilt/spec.md`. Planner owns this file. Builder executes; Tester validates; Orchestrator coordinates.

## Stage A — Additive caption filter to FFmpeg vertical clip renderer

### Behavior

- Add `subtitles={caption_file}` filter to the FFmpeg filter graph in `FFmpegVerticalClipRenderer.render_singular()`, positioned after the existing vertical reframe pipeline (crop → scale → pad → fps) and before the encode step.
- When `caption_file` is absent or null in the contract, the filter graph is identical to M6.1 baseline (no subtitle filter).
- When `caption_file` is present, the filter graph includes `subtitles={path_to_srt}` after fps and before the encode video codec filter.
- No changes to `RenderProfile`, `DerivedAsset` schema, or any persistence layer for Slice 1 — `caption_file` is a transient contract field only.
- Preserve all existing `rank_clips` production functions, contracts, providers, and tests.
- No worker changes beyond the renderer filter graph addition.

### Likely files/categories

- `services/worker/aiclip_worker/rendering.py` — Add `render_singular` caption filter branch; add method to construct filter graph with optional subtitles
- `services/worker/aiclip_worker/actions/render_clip.py` — Pass `caption_file` from contract through to renderer; no schema changes (optional field)
- `services/worker/contracts/media_processing_v1.json` — Add optional `caption_file` field to `render_clip_request` definition
- `apps/api/app/Services/RenderValidator.php` — Add optional `caption_file` to valid request fields; do not reject when absent
- `apps/api/app/Jobs/RenderMediaClip.php` — Pass `caption_file` from contract through to worker; no behavior change when absent

### Targeted RED

- `python -m pytest services/worker/tests/test_render_clip_caption_contract.py -v` (expect failure on missing caption field handling/absent is valid)
- `python -m pytest services/worker/tests/test_ffmpeg_vertical_renderer_caption.py -v` (expect failure on filter graph construction without caption support)
- `php artisan test --filter=RenderValidatorTest` (expect failure on caption_file validation if not yet updated)
- Existing M6.1 tests must all pass (regression check)

### Targeted GREEN commands

```bash
# Run caption contract validation (new tests, expect some RED initially)
cd services/worker && python -m pytest tests/test_render_clip_caption_contract.py -v

# Run filter graph caption tests (new tests, expect some RED initially)
cd services/worker && python -m pytest tests/test_ffmpeg_vertical_renderer_caption.py -v

# Run RenderValidator tests
php artisan test --filter=RenderValidatorTest

# Run existing M6.1 regression sentinels (must pass unchanged)
cd services/worker && python -m pytest tests/test_render_clip_contract_validation.py tests/test_cli_render_clip.py tests/test_render_clip_integration.py tests/test_render_clip_integration_duration.py -v

# Run M5 rank_clips sentinels (must pass)
cd services/worker && python -m pytest tests/test_rank_clips.py tests/test_contract_rank_clips.py -v
```

### Stop condition before Stage B

- `render_singular` method added to `FFmpegVerticalClipRenderer` with optional `subtitles` filter branch
- When `caption_file` absent, filter graph identical to M6.1 baseline (verified by test)
- When `caption_file` present, filter graph includes `subtitles={path}` after fps filter
- `caption_file` optional field added to JSON schema in `media_processing_v1.json`
- `RenderValidator.php` accepts optional `caption_file` field
- All existing M6.1 test suites pass without modification (regression gate)
- New test files created and initially failing (RED phase accepted)

---

## Stage B — Caption contract and CLI transport

### Behavior

- Add `caption_file` as optional field to `render_clip_request` JSON schema (string path to SRT file).
- Update `render_clip.py` to pass `caption_file` through to renderer when present; when absent, renderer produces M6.1 baseline output.
- Update `cli.py` `render-clip` subcommand to pass `caption_file` through stdin JSON; no argument-list or file transport changes.
- Update `RenderValidator.php` request validation to include optional `caption_file` field (string, path format, optional).
- Update `RenderMediaClip.php` to pass `caption_file` to worker contract when provided.

### Likely files/categories

- `services/worker/contracts/media_processing_v1.json` — Add optional `caption_file` to `render_clip_request` definition
- `services/worker/aiclip_worker/actions/render_clip.py` — Extract `caption_file` from contract; pass to renderer; no failure when absent
- `services/worker/aiclip_worker/cli.py` — No structural changes; `render-clip` already handles stdin JSON; ensure `caption_file` passes through
- `apps/api/app/Services/RenderValidator.php` — Add `caption_file` as optional field in `request()` validation
- `apps/api/app/Jobs/RenderMediaClip.php` — Read `caption_file` from contract and include in worker request

### Targeted RED

- `python -m pytest services/worker/tests/test_render_clip_caption_contract.py -v` (expect failure on caption_file absent/present handling)
- `php artisan test --filter=RenderValidatorTest` (expect failure on caption_file validation rules if not yet updated)
- Existing M6.1 tests must all pass

### Targeted GREEN commands

```bash
# Run caption contract tests
cd services/worker && python -m pytest tests/test_render_clip_caption_contract.py -v

# Run CLI render-clip tests (should pass through caption_file)
cd services/worker && python -m pytest tests/test_cli_render_clip.py -v

# Run RenderValidator tests with caption_file fixtures
php artisan test --filter=RenderValidatorTest

# Run existing M6.1 regression sentinels (must pass unchanged)
cd services/worker && python -m pytest tests/test_render_clip_contract_validation.py tests/test_cli_render_clip.py tests/test_render_clip_integration.py tests/test_render_clip_integration_duration.py -v

# Run M5 rank_clips sentinels (must pass)
cd services/worker && python -m pytest tests/test_rank_clips.py tests/test_contract_rank_clips.py -v
```

### Stop condition before Stage C

- `caption_file` optional field added to JSON schema
- `RenderValidator.php` accepts optional `caption_file` 
- `render_clip.py` passes `caption_file` through; renderer handles absent case gracefully
- All existing M6.1 tests pass unchanged
- New caption-specific tests pass (contract validation, filter graph, CLI transport)

---

## Stage C — Real FFmpeg libsubtitle integration + integration tests

### Behavior

- Real FFmpeg rendering with `subtitles` filter burning SRT captions into the vertical clip output.
- When `caption_file` is present: filter graph includes `subtitles={caption_file}`; FFmpeg produces output with burned-in captions; output validated via FFprobe for subtitle stream presence.
- When `caption_file` is absent: identical to M6.1 baseline, no caption filter, same output.
- Duration tolerance ±50ms validated by both worker (FFprobe) and Laravel (RenderValidator) — applies to total output duration including caption overlay.
- Sanitized failures: worker exits with clean error codes; Laravel maps to `render_error`; no raw stderr/path/payload leakage.
- Idempotency/concurrency: retry uses persisted storage key; durable pending claim already has `storage_disk`/`storage_key`.
- Targeted PHP->Python real integration tests + regression tests for existing functionality.

### Likely files/categories

- `services/worker/aiclip_worker/rendering.py` — Real FFmpeg `_run_ffmpeg` with `subtitles` filter; temp file handling; final output validation with subtitle stream probe
- `services/worker/aiclip_worker/actions/render_clip.py` — No changes beyond Stage B (pass-through)
- `services/worker/tests/test_render_clip_integration_caption.py` — New real FFmpeg integration tests with SRT captions
- `services/worker/tests/test_render_clip_integration_duration.py` — Existing duration tolerance (already covers total output)
- `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php` — New feature tests with caption_file (requires PostgreSQL)
- `apps/api/tests/Unit/RenderValidatorTest.php` — Updated with caption_result fixtures

### Targeted RED

- `cd services/worker && python -m pytest tests/test_render_clip_integration_caption.py -v` (expect failure on real FFmpeg with subtitles filter)
- `php artisan test tests/Feature/Jobs/RenderMediaClipTest.php` (expect failure on new caption_file fixtures if DB not ready)
- Existing M6.1 integration tests must pass (regression)

### Targeted GREEN commands

```bash
# Run new caption integration tests (requires FFmpeg with libsubtitle)
cd services/worker && python -m pytest tests/test_render_clip_integration_caption.py -v

# Run RenderMediaClip feature tests (requires PostgreSQL)
php artisan test tests/Feature/Jobs/RenderMediaClipTest.php

# Run RenderValidator tests
php artisan test --filter=RenderValidatorTest

# Run existing M6.1 regression sentinels (must pass unchanged)
cd services/worker && python -m pytest tests/test_render_clip_contract_validation.py tests/test_cli_render_clip.py tests/test_render_clip_integration.py tests/test_render_clip_integration_duration.py -v

# Run M5 rank_clips sentinels (must pass)
cd services/worker && python -m pytest tests/test_rank_clips.py tests/test_contract_rank_clips.py -v
```

### Stop condition before Stage D

- Real FFmpeg with `subtitles` filter produces valid output with burned-in captions (FFprobe confirms subtitle stream)
- Duration tolerance ±50ms validated on output with captions
- Storage key format correct: `projects/{project_id}/renders/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.mp4`
- Unique identity constraint enforced (re-read authority on retry)
- M5 rank_clips tests still green
- A+B+C targeted tests green
- Rendered output without caption_file identical to M6.1 baseline

---

## Stage D — Full regression/evidence

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

- Targeted M5 rank_clips tests: `test_rank_clips.py`, `test_contract_rank_clips.py`
- All singular M6 worker tests: test_render_clip_*, test_cli_render_clip_*, test_render_clip_integration_*
- Full worker pytest: `services/worker/tests/`
- RenderValidator tests: `apps/api/tests/Unit/RenderValidatorTest.php`
- RenderMediaClip tests: `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php`
- Real-worker/no-auto-render regression: `apps/api/tests/Feature/Jobs/ProcessMediaAssetRenderRealWorkerTest.php`
- DerivedAsset rendered clip tests: `apps/api/tests/Feature/Models/DerivedAssetRenderedClipTest.php`
- ProcessMediaAsset regression: `apps/api/tests/Feature/Jobs/ProcessMediaAssetRenderTest.php`
- WorkerBoundaryTest: `apps/api/tests/Unit/WorkerBoundaryTest.php`
- Full applicable backend suite against PostgreSQL: `php artisan test --group=backend`
- Real FFmpeg/FFprobe integration: verified in worker integration tests
- Governance: git diff --check passes, exact-scope review (only files in plan modified)
- Frontend/E2E existing CI regression suites: green

### Stop condition before commit/push

- All regression sentinels green
- `evidence.md` updated with actual RED/GREEN/REFACTOR
- Fresh independent Tester APPROVE
- Orchestrator makes small atomic commits, normal push, one replacement PR with `Closes #76`
- Monitor CI, stop at `CI_GREEN_WAITING_HUMAN_MERGE` (do not merge automatically)