# Implementation Plan: Issue #69 — Durable Baseline Vertical Clip Render Pipeline (Recovery-v3)

## Authority

This plan derives exclusively from `specs/069-vertical-clip-render-pipeline/spec.md`. Planner owns this file. Builder executes; Tester validates; Orchestrator coordinates.

## Stage A — Laravel persistence/storage/render-specific lifecycle only

### Behavior
- RenderProfile: persisted version `vertical_v1`; timeout strict integer 30..300 default 300; lock wait = timeout + 10.
- DerivedAsset migrations: final schema must use render-specific lifecycle columns only (`render_status`: pending, rendering, completed, failed; `candidate_index`; `render_profile_version`; `render_error`; `render_started_at`; `render_completed_at`). The unmerged generic `status` migration already present in the recovery checkpoint must be removed or rewritten so the final PR does not add a generic lifecycle column to unrelated `DerivedAsset` rows.
- Preserve `render_configuration` and `render_parameters` as JSONB for immutable input/provenance.
- Ensure `storage_disk` and `storage_key` remain NOT NULL.
- Laravel StorageKeyBuilder: exact format `projects/{project_id}/renders/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.mp4`. UUID generated on first identity; retries reuse persisted key.
- Unique identity: (`media_asset_id`, `type`, `candidate_index`, `render_profile_version`). `recommendation_id` excluded from identity and unique index.
- Identity authority rule: persist the selected candidate timing/profile/output snapshot on first completion. A `completed` row for the same `(media_asset_id,type,candidate_index,render_profile_version)` identity is terminal and reused unchanged even if upstream recommendation/configuration later changes; retries apply only to non-completed rows.
- PostgreSQL constraints: unique index on (`media_asset_id`, `type`, `candidate_index`, `render_profile_version`); check constraints for timeout range and enum values.
- No worker changes in Stage A.

### Likely files/categories
- `apps/api/app/Services/RenderProfile.php`
- `apps/api/app/Services/StorageKeyBuilder.php`
- `apps/api/app/Models/DerivedAsset.php`
- `apps/api/database/migrations/2026_09_29_145810_add_render_columns_to_derived_assets_table.php`
- `apps/api/database/migrations/2026_09_29_153622_add_status_to_derived_assets_table.php` (remove/repurpose the unmerged generic `status` addition so the final schema is render-specific)
- `apps/api/app/Jobs/RenderMediaClip.php` (persistence/claim portion only)
- `apps/api/tests/Unit/RenderProfileTest.php`
- `apps/api/tests/Feature/Models/DerivedAssetRenderedClipTest.php`
- `apps/api/tests/Unit/StorageKeyBuilderTest.php`

### Targeted RED
- `php artisan test --filter=RenderProfileTest` (expect failure on version/timeout)
- `php artisan test --filter=DerivedAssetRenderedClipTest` (expect failure on new columns/constraints)
- `php artisan test --filter=StorageKeyBuilderTest` (expect failure on key format)

### Targeted GREEN commands
```bash
# Run RenderProfile tests
php artisan test --filter=RenderProfileTest

# Run DerivedAssetRenderedClip tests
php artisan test --filter=DerivedAssetRenderedClipTest

# Run StorageKeyBuilder tests
php artisan test --filter=StorageKeyBuilderTest

# Run migration (if needed)
php artisan migrate --force
```

### Regression sentinels
- `DerivedAssetRenderedClipTest`: TC-LDA-01 through TC-LDA-07 (existing clip_rendered tests)
- `RenderProfileTest`: TC-LUP-01 (version), TC-LUP-02 (timeout range), TC-LUP-06 (lockWaitSeconds = timeout + 10)
- `StorageKeyBuilderTest`: TC-SKB-01 (format), TC-SKB-02 (UUID persistence)
- M5 rank_clips tests (`test_rank_clips.py`, `test_contract_rank_clips.py`) must remain green

### Stop condition before Stage B
- All Stage A tests pass
- `render_profile_version` = `'vertical_v1'`
- `timeout` integer 30..300 default 300
- `lockWaitSeconds()` returns `timeout + 10`
- Unique index (`media_asset_id`, `type`, `candidate_index`, `render_profile_version`) enforced
- `storage_disk`/`storage_key` NOT NULL
- M5 rank_clips tests green

---

## Stage B — Additive singular worker contract/action preserving M5

### Behavior
- Add singular JSON action `render_clip` and CLI command `render-clip`.
- Exact minimal request shape:
  ```json
  {
    "version": "1.0.0",
    "action": "render_clip",
    "media": {"duration_ms": 5000},
    "candidate_index": 2,
    "candidate": {"start_ms": 1000, "end_ms": 4000},
    "configuration": {
      "target_width": 1080,
      "target_height": 1920,
      "target_fps": 30,
      "video_codec": "libx264",
      "video_bitrate_kbps": 5000,
      "audio_codec": "aac",
      "audio_bitrate_kbps": 128
    },
    "source_media": {"disk": "local", "key": "source/video.mp4", "width": 1920, "height": 1080, "video_codec": "h264", "audio_codec": "aac"},
    "output_storage": {"disk": "local", "key": "projects/1/renders/2/2/vertical_v1/550e8400-e29b-41d4-a716-446655440000.mp4", "mime_type": "video/mp4"}
  }
  ```
- `candidate` object has NO `index` field; root `candidate_index` is the authority.
- NO `recommendation` object; NO `recommendation_id`, `project_id`, `media_asset_id` sent to Python worker.
- Preserve all existing `rank_clips` production functions, contracts, providers, and tests.
- Do not delete old plural M6 files/tests during initial singular replacement.
- After any edit to `media_processing_v1.json`, `contracts.py`, or `cli.py`: immediately run M5 sentinel tests (`test_contract_rank_clips.py`, `test_rank_clips.py`) and ranking provider tests if present.
- Then run targeted singular M6 worker tests.

### Likely files/categories
- `services/worker/aiclip_worker/contracts.py`
- `services/worker/aiclip_worker/cli.py`
- `services/worker/aiclip_worker/actions/render_clip.py` (new)
- `services/worker/aiclip_worker/rendering.py` (minimal changes for singular)
- `services/worker/contracts/media_processing_v1.json` (update)
- `services/worker/tests/test_render_clip_contract_validation.py` (new)
- `services/worker/tests/test_cli_render_clip.py` (new)
- `services/worker/tests/test_rank_clips.py` (existing)
- `services/worker/tests/test_contract_rank_clips.py` (existing)

### Targeted RED
- `cd services/worker && python -m pytest tests/test_render_clip_contract_validation.py -v` (expect failure on missing fields/wrong shape)
- `cd services/worker && python -m pytest tests/test_cli_render_clip.py -v` (expect failure on CLI parsing)
- `cd services/worker && python -m pytest tests/test_rank_clips.py tests/test_contract_rank_clips.py -v` (must pass; if fails, revert)

### Targeted GREEN commands
```bash
# Run render_clip contract validation
cd services/worker && python -m pytest tests/test_render_clip_contract_validation.py -v

# Run CLI render_clip tests
cd services/worker && python -m pytest tests/test_cli_render_clip.py -v

# Run M5 rank_clips sentinels (must pass)
cd services/worker && python -m pytest tests/test_rank_clips.py tests/test_contract_rank_clips.py -v

# Run ranking provider tests if present
cd services/worker && python -m pytest tests/test_*_rank_clips_provider.py -v
```

### Regression sentinels
- All M5 rank_clips tests pass unchanged (`test_rank_clips.py`, `test_contract_rank_clips.py`)
- `test_contract_rank_clips.py` full suite green
- `test_rank_clips.py` full suite green

### Stop condition before Stage C
- Candidate_index is sole authority in render request (no recommendation_id/project_id/media_asset_id in worker input)
- No auto-semantic_rank=1 or candidate-0 defaults
- Worker contract minimal: uses `source_media` from probe, not recommendation_id/project_id/media_asset_id
- M5 rank_clips tests all green
- Singular contract/CLI tests green
- After any edit to contracts.py/cli.py/media_processing_v1.json, M5 sentinels re-run and pass

---

## Stage C — Laravel-to-worker integration + real FFmpeg/FFprobe

### Behavior
- Laravel re-reads `MediaAsset` + `MediaClipRecommendation` + selected candidate authority (by `candidate_index`).
- No default candidate 0; no auto `semantic_rank=1`; no `ProcessMediaAsset` auto-render.
- Laravel projects only selected timing (`start_ms`, `end_ms`) into singular worker contract and sends precomputed `output_storage`.
- `ProcessMediaAction` invokes CLI `render-clip` using stdin (JSON); argv/no shell.
- Real renderer: isolated temp output (`/tmp/renders/{uuid}`), bounded timeout (from RenderProfile), atomic finalization (rename), cleanup on failure, 1080x1920, libx264/yuv420p, AAC 128k only with source audio, MP4 container.
- FFprobe duration: selected candidate duration (`end_ms - start_ms`) ±50ms absolute tolerance (no percentage).
- Sanitized failures: worker exits with clean error codes; Laravel maps to `render_error`; no raw stderr/path/payload leakage.
- Idempotency/concurrency: retry uses persisted storage key; durable pending claim already has `storage_disk`/`storage_key`.
- Targeted PHP->Python real integration tests + regression tests for existing functionality.

### Likely files/categories
- `apps/api/app/Jobs/RenderMediaClip.php` (full invocation)
- `apps/api/app/Services/RenderValidator.php` (duration tolerance validation)
- `services/worker/aiclip_worker/cli.py` (stdin JSON parsing)
- `services/worker/aiclip_worker/rendering.py` (FFmpeg/FFprobe invocation, temp handling, duration validation)
- `services/worker/aiclip_worker/errors.py` (sanitized error mapping)
- `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php`
- `apps/api/tests/Feature/Jobs/ProcessMediaAssetRenderRealWorkerTest.php` (rewrite as a regression/integration sentinel for the corrected explicit render boundary; do not preserve auto-render semantics)
- `apps/api/tests/Feature/Jobs/ProcessMediaAssetRenderTest.php`
- `services/worker/tests/test_render_clip_integration.py` (new singular real FFmpeg fixture coverage)
- `services/worker/tests/test_render_clip_integration_duration.py` (new singular duration tolerance coverage)
- `apps/api/tests/Unit/RenderValidatorTest.php`

### Targeted RED
- `php artisan test tests/Feature/Jobs/RenderMediaClipTest.php` (expect failure on corrected explicit invocation/integration)
- `php artisan test tests/Feature/Jobs/ProcessMediaAssetRenderRealWorkerTest.php` (expect failure until legacy auto-render assumptions are removed/replaced)
- `cd services/worker && python -m pytest tests/test_render_clip_integration.py -v` (expect failure on FFmpeg integration)
- `cd services/worker && python -m pytest tests/test_render_clip_integration_duration.py -v` (expect failure on duration tolerance)

### Targeted GREEN commands
```bash
# Run explicit RenderMediaClip job tests
php artisan test tests/Feature/Jobs/RenderMediaClipTest.php

# Run existing real-worker regression/integration test after removing auto-render assumptions
php artisan test tests/Feature/Jobs/ProcessMediaAssetRenderRealWorkerTest.php

# Run ProcessMediaAsset no-auto-render regression
php artisan test tests/Feature/Jobs/ProcessMediaAssetRenderTest.php

# Run worker real integration tests (requires FFmpeg)
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
- M5 rank_clips tests remain green after any worker contract/schema/CLI change
- `WorkerBoundaryTest` (Laravel/worker boundary) passes
- Full applicable backend suite against PostgreSQL passes

### Stop condition before Stage D
- End-to-end render with real FFmpeg fixture produces valid `DerivedAsset` (status completed)
- Duration tolerance ±50ms validated by both worker (FFprobe) and Laravel (RenderValidator)
- Storage key format correct: `projects/{project_id}/renders/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.mp4`
- Unique identity constraint enforced (re-read authority on retry)
- M5 rank_clips tests still green
- A+B+C targeted tests green

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
- Orchestrator makes small atomic commits, normal push, one replacement PR with `Closes #69`
- Monitor CI, stop at `CI_GREEN_WAITING_HUMAN_MERGE` (do not merge automatically)