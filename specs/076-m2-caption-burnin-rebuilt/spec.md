# Specification: Issue #76 — M6.2 Caption Burn-in Slice 1

## Authority and status

- Active issue: [#76](https://github.com/CarlosEGoulart/AiClip/issues/76), `feat(caption): add burn-in capability to vertical clip renderer`.
- Authorized recovery branch: `@carlosegoulart/76/feat/caption-burnin-rebuilt`.
- This is the first slice of M6.2: Caption Burn-in. M6.1 (`ffmpeg_vertical_baseline` v1.0.0) is completed and merged. M6.2+ are future milestones.
- Planner owns this spec.md, plan.md, and test-plan.md. Execution evidence, implementation, testing, and lifecycle operations belong to their assigned agents.

## Goal and architectural reconciliation

Add basic FFmpeg libsubtitles burn-in capability to the existing vertical clip renderer so that SRT caption files can be burned into rendered vertical clips. This is an additive extension to the `ffmpeg_vertical_baseline` v1.0.0 pipeline established by M6.1.

| Source inspected | Interpretation for this issue |
|---|---|
| `docs/prd.md`, Clip Creation Workflow | Step 7: "System renders vertical clips with captions" — caption system is Medium priority |
| `docs/roadmap.md`, M6 | M6 is Vertical Clip Rendering. M6.1 baseline complete; M6.2+ planned |
| `docs/architecture.md`, Media Processing Flow | Vertical reframing step 4; captions to be added in M6.2 |
| `docs/adr/0002-mvp-product-and-architecture.md` | Provider abstractions isolate external service dependencies; CI uses deterministic fakes |
| `docs/project-state.md`, Current Milestone/Next Architectural Goal | M6.1 completed; M6.2 explicit future, now authorized |
| Issue #69 (M6.1) | Explicitly excluded captions; M6.2 adds caption burn-in as next slice |

## Scope and inclusions

### Included:
- FFmpeg `subtitles` filter integration into the existing filter graph for vertical clip rendering
- SRT caption file path provided via worker contract `caption_file` field (optional — omitted when no captions)
- Caption burn-in positioned after vertical reframe crop/scale/pad/fps, before encode
- Backward compatibility: when `caption_file` is absent, output is identical to M6.1 baseline
- Unit, contract, integration, and E2E tests; authorized factual documentation reconciliation by Orchestrator

### Excluded:
- AI-powered auto-caption generation (caption files are externally provided SRT)
- Configurable focal point or positioning UI for captions
- Multi-language caption support (English-only SRT)
- Caption styling (font, size, color) — FFmpeg libsubtitles defaults applied
- Transcript segment handling or auto-generation
- Frontend UI for caption editing or preview
- General queue redesign, unrelated refactors, governance/control-plane changes

## Authoritative inputs and readiness

Inputs are read afresh by Laravel from persisted state, never supplied by a public request or taken directly from an unpersisted worker response. The asset must belong to its existing project/owner chain. No new ownership field or bypass is introduced.

1. **Source media** must have a valid probe result with `duration_ms`, `width`, `height`, `video_codec`, `audio_codec` — same as M6.1.
2. **Caption file** (when provided) is an SRT file path accessible via `MediaAsset.storage_disk` + `storage_key` path construction. The path is a string field in the contract; Laravel validates the file exists on the configured storage disk before dispatching the worker.
3. **Render profile** remains `vertical_v1`; caption filter is additive on top of the existing pipeline.
4. **Configuration** (target_width, target_height, target_fps, video_codec, video_bitrate_kbps, audio_codec, audio_bitrate_kbps) unchanged — same validation as M6.1.

## Deterministic algorithm: `ffmpeg_vertical_baseline` with `subtitles` filter v1.0.0

### Configuration and fixed policies

Same as M6.1 (see spec.md §Configurable field defaults). No new configurable fields introduced for Slice 1.

### A. Clip selection

Same as M6.1 — explicit `candidate_index` authority, no auto-selection.

### B. Worker request

The worker `render_clip` action expects a JSON object on stdin with exactly the following fields (all from M6.1, plus one optional addition):

- `version`: string, exactly "1.0.0"
- `action`: string, exactly "render_clip"
- `media`: object with exactly `{ "duration_ms": <integer> }` — the authoritative duration of the source media.
- `candidate_index`: non-negative integer, the ONLY index authority carried across the worker boundary.
- `candidate`: object with exactly `{ "start_ms": <integer>, "end_ms": <integer> }` — the selected candidate bounds. NO index field.
- `configuration`: object, the vertical profile object (e.g., `{ "target_width": 1080, "target_height": 1920, ... }`).
- `source_media`: object with exactly `{ "disk": <string>, "key": <string>, "width": <integer>, "height": <integer>, "video_codec": <string>, "audio_codec": <string> }`.
- `output_storage`: object with exactly `{ "disk": <string>, "key": <string>, "mime_type": "video/mp4" }`.
- `caption_file`: **optional** string — path to SRT file relative to storage root. When absent or null, no caption filter is applied and output is identical to M6.1 baseline. When present, FFmpeg libsubtitles filter is inserted into the filter graph.

No recommendation object, all candidates, recommendation_id, project_id, or media_asset_id is sent to the Python worker. Laravel independently re-reads M5/M4 authority and projects only the selected candidate timing.

### C. FFmpeg filter graph construction (per clip)

For the selected candidate with original bounds `[start_ms, end_ms)` on the source media:

1. **Trim**: `-ss <start_s> -t <duration_s> -i <input>` (input seeking for speed, `-ss` before `-i`).
2. **Vertical reframe** (9:16 = target_width:target_height):
    - `center` (baseline only): `crop=ih*9/16:ih:(iw-ih*9/16)/2:0` — center-crop horizontally to 9:16, then scale to target resolution.
3. **Scale**: `scale=target_width:target_height:force_original_aspect_ratio=decrease,pad=target_width:target_height:(ow-iw)/2:(oh-ih)/2` — fit within target, letterbox/pillarbox if needed.
4. **FPS**: `fps=target_fps` — constant frame rate output.
5. **Subtitles** (optional, Slice 1 addition): `subtitles={caption_file}` — if `caption_file` is present in contract, this filter is added after step 4 and before encode. The subtitle file is an SRT file path.
6. **Encode**: `-c:v <video_codec> -b:v <video_bitrate_kbps>k -c:a <audio_codec> -b:a <audio_bitrate_kbps>k -movflags +faststart`.

When `caption_file` is absent, step 5 is omitted and the filter graph is identical to M6.1 baseline (crop → scale → pad → fps → encode).

### D. Output naming and storage

- Output key: `projects/{project_id}/renders/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.mp4`
- UUID generated by Laravel when first creating the identity; retries/reclaims reuse the persisted key, do not generate a new UUID per retry.
- Storage: same disk as source `MediaAsset.storage_disk`, selected by Laravel.
- MIME type: `video/mp4`.
- Worker receives this precomputed `output_storage` and does not derive project paths.
- DerivedAsset record: `type=clip_rendered`, linked to `MediaAsset`, with `storage_key`, `mime_type`, `size_bytes`, `duration_ms`, `width`, `height`, `codec`, `bitrate`, and extended columns: `candidate_index` (integer), `render_profile_version` (string, e.g., `vertical_v1`), `render_status`, `render_started_at`, `render_completed_at`, `render_error`.
- No additional columns required for Slice 1; `caption_file` is a transient contract field, not persisted.

### E. Result serialization

Top-level object has only `status: "success"` and required `render object`:

- `algorithm`: nonblank string, exactly `ffmpeg_vertical_baseline`.
- `algorithm_version`: nonblank string, exactly `1.0.0`.
- `parameters`: required object with exactly:
  - `configuration`: complete validated request configuration.
  - `source_media`: `{disk, key, duration_ms, width, height, video_codec, audio_codec}` — from probe.
  - `ffmpeg_version`: string from `ffmpeg -version` first line.
  - `filter_graph`: string — the exact filter_complex used. When caption_file is present, the filter graph string includes `subtitles={path}`; when absent, identical to M6.1 filter graph.
- `clips`: required list with exactly one object containing exactly:
  - `candidate_index`: non-negative integer copied from the validated request.
  - `start_ms`, `end_ms`: strict integers matching the selected candidate bounds.
  - `duration_ms`: `end_ms - start_ms`.
  - `output`: object with `disk`, `key`, `size_bytes`, `duration_ms`, `width`, `height`, `video_codec`, `audio_codec`, `video_bitrate_kbps`, `audio_bitrate_kbps`.
- `semantic_rank` and `semantic_score` do not cross the worker boundary. Laravel retains those values in its authoritative recommendation snapshot.

## Errors, execution, and privacy

- CLI success emits exactly one strict JSON envelope to stdout, exit 0.
- Invalid JSON/contract/action/version/configuration/input emits `{status:"error",code:"invalid_contract",error:"Invalid render contract",stderr:""}`, exit 2.
- Runtime/result-validation failure (FFmpeg error, storage write error, validation mismatch) emits same shape with `code:"render_failed"` and `error:"Clip render failed"`, exit 1.
- Do not echo rejected values or schema-library exception messages. No partial success.
- Strict JSON rejects NaN/Infinity on input and output, including overflow to infinity.
- Laravel launches an argument-list process for `render-clip`, with the contract on **stdin**, not the command line. No shell interpolation.
- New action performs no database access or network requests (except S3 via configured Flysystem adapter if worker writes directly; baseline: Laravel writes via Flysystem after worker returns output path). Reading its contract stream and packaged schema is allowed.
- `media.render_timeout_seconds` defaults to 300, integer 30..300. Lock wait bound is timeout plus 10 seconds. Persist both as Laravel-owned `execution_parameters` for the attempt; they are operational settings, not score inputs or worker configuration.
- Do not forward raw stdout, stderr, payloads, media content, or arbitrary exception messages into exceptions, queue failure records, database errors, or logs for this stage. Use fixed error codes/messages.
- Logs may contain only existing local asset ID, stage, fixed error code, elapsed time, and counts. No filter graphs, criteria dump, or contract dump.

## Persistence, validation, and ownership

### Storage decision

No new `DerivedAsset` columns required for Slice 1. The `caption_file` field is a transient contract field; it is not persisted to the database. Existing render columns (`candidate_index`, `render_profile_version`, `render_status`, `render_error`, `render_started_at`, `render_completed_at`) remain unchanged.

### Independent Laravel success validation

Validate in PHP even when a test double or compromised worker reports `status=success`. Before any result/status write, validate the exact envelope/object shapes, algorithm/version, all parameter fields and equality with the locally selected configuration/policies, clips as a list with exactly one element, all types/finiteness/bounds, requested `candidate_index` equality, exact matching selected-candidate bounds, output file existence, and metadata consistency.

Independently verify:
- The clip's `candidate_index` exactly matches the explicitly selected index that Laravel already validated against the authoritative recommendation.
- The clip's `start_ms`/`end_ms` exactly match the selected authoritative candidate bounds.
- Output file exists on storage, size > 0, duration within +/- 50ms of expected, resolution matches configuration.
- FFmpeg version recorded.
- Filter graph is non-empty.
- Duration tolerance: candidate duration ±50ms.

Missing algorithm/parameters/clips must fail even for zero clips. Reject unsupported algorithms/versions rather than persisting misleading provenance.

Perform the same invariant checks at the model's completion boundary so another caller cannot bypass the service validator. Share PHP validation code between service/model, not worker implementation code. Do not mutate successful recommendation/analysis rows when rejecting results.

### Lifecycle and concurrent claim

Legal transitions only: `pending -> rendering -> completed`, `rendering -> failed`, `failed -> rendering`. Starting a retry clears stale error and any incomplete result fields. `completed` is terminal and reused unchanged, including after configuration changes. Invalid transitions do not mutate stored data; do not silently report a new successful render.

Use PostgreSQL transaction-scoped exclusive row locking, not merely `firstOrCreate`, an in-memory flag, or a unique index:

1. Safely establish the unique pending row (insert-on-conflict/reread); handle concurrent first creation and deletion without duplicate rows.
2. Begin a transaction, lock that row with `SELECT ... FOR UPDATE`, then re-read status and current upstream state. The row lock is the exclusive claim. A completed row is returned without worker invocation or timestamp/result changes.
3. For a ready attempt or controlled upstream failure, transition pending/failed to rendering, clear error, capture inputs/configuration, invoke at most one bounded render process if eligible to run, independently validate, and atomically complete or fail **inside the same transaction**. Catch expected worker/validation errors inside the transaction so the sanitized failure commits. Do not hold this lock during probe, scene detection, extraction, transcription, or recommendation.
4. A second invocation cannot enter the renderer until the first transaction releases its claim. After completion it must reuse the result; after failure a retry may run serially. A lock-timeout contender returns `busy`, never changes the owning attempt or claims success. The owning job remains responsible for finalization.
5. An unhandled exception/process crash rolls back the transaction, including rendering state and partial JSON. The prior pending/failed state remains recoverable by the existing queue retry policy. No committed unowned rendering row is created by this design. The queue exhaustion/failure path must resolve an unclaimed pending/failed render attempt with a sanitized failure (via rendering) and resolve the asset to a terminal state; it must not overwrite an independently completed/actively locked attempt.

The intentional trade-off is a database transaction held for at most one short metadata validation plus bounded FFmpeg process (default worker timeout 300 seconds), avoiding a new distributed lease/recovery subsystem. `rendering` is visible to the owning transaction; outside readers see the previous pending/failed state until commit. There is no new progress UI/API depending on committed rendering state. Test the claim with separate PostgreSQL connections/processes, not sequential Eloquent objects alone.

## Render job boundary: `RenderMediaClip`

M6.2 implements the existing `RenderMediaClip` job (already created in M6.1) with additive caption capability. The job is **explicitly invoked** with an authoritative candidate reference. This job is **not** an automatic stage in `ProcessMediaAsset`.

### Job: `RenderMediaClip`

- **Purpose**: Render exactly one clip from an explicitly selected M5 candidate, with optional caption burn-in.
- **Invocation**: Internal caller (service, command, or M7 workflow) provides:
  - `MediaAsset` ID
  - `MediaClipRecommendation` ID
  - `candidate_index` (integer, 0..K-1) — **no default, no auto-selection**
  - `caption_file` (optional string) — path to SRT file for burn-in; omitted when no captions
- **Behavior**:
  1. Independently re-reads M5 recommendation and M4 analysis authority from database.
  2. Verifies the selected candidate exists, has non-null `semantic_score`, and bounds match the persisted snapshot.
  3. Verifies source media is accessible (probe, storage).
  4. Builds render contract with explicit `candidate_index` and configuration from `RenderProfile`.
  5. **Optionally** includes `caption_file` in contract if a caption SRT file is available for this asset.
  6. Invokes worker `render_clip` action via `ProcessMediaAction`.
  7. Validates result independently via `RenderValidator`.
  8. Persists `DerivedAsset` with `type=clip_rendered`, `candidate_index`, `render_profile_version`, configuration, parameters.
  9. Returns `DerivedAsset` on success; throws sanitized exception on failure.
- **Idempotency**: Re-invocation with same `(media_asset_id, candidate_index, render_profile_version)` returns existing completed `DerivedAsset` without re-executing worker, provided the persisted authority snapshot matches the current request. If the same unique render identity exists but current recommendation authority/candidate timing differs, fail closed with `RenderVersionConflictException`; do not overwrite completed artifact and do not silently regenerate under same identity.
- **Concurrency**: Row-level lock on `DerivedAsset` (same pattern as M4/M5). Competing invocations return `busy`.
- **Failure modes**: All sanitized error codes per readiness table above. No partial results.

### ProcessMediaAsset integration

**`ProcessMediaAsset` does NOT automatically invoke rendering after M5.**  
**`ProcessMediaAsset` completion does NOT depend on `clipRenderResolved`.**  
**No `clipRenderResolved` flag is introduced in `ProcessMediaAsset`.**

The existing `ProcessMediaAsset` flow remains unchanged after M5:
- probe → scene detection → audio extraction/transcription → clip analysis (M4) → clip recommendation (M5) → asset finalization
- Asset finalization requires only the existing resolved stages (probe, scene, audio, M4, M5).
- Rendering is a **separate, explicitly invoked capability** that M7 (or an internal admin command) will drive.
- If an internal caller wishes to render after `ProcessMediaAsset` completes, it invokes `RenderMediaClip` separately. The asset's terminal state is unaffected by render success or failure.

### Rerun and reuse semantics

- `RenderMediaClip` reuses valid persisted `DerivedAsset` for the same `(media_asset_id, candidate_index, render_profile_version)`, comparing the persisted authority snapshot against the current request. If the snapshot differs (different recommendation authority or candidate timing), fail closed with `RenderVersionConflictException`; do not overwrite completed artifact and do not silently regenerate under same identity.
- A failed render attempt may be retried by re-invoking `RenderMediaClip` (clears error, re-attempts).
- Completed renders never re-execute.
- If the unique DB identity `(media_asset_id, type, candidate_index, render_profile_version)` matches but the persisted authority snapshot differs, `RenderVersionConflictException` is thrown; a new render row is not created under the same identity.
- Assets lacking a valid persisted probe must not be used to fabricate render input.

## Observable acceptance criteria

1. The real FFmpeg renderer with optional `subtitles` filter and metadata-only `render_clip` v1 action implement the exact algorithm, parameters, errors, and privacy boundary above.
2. When `caption_file` is provided, the filter graph includes `subtitles={caption_file}` after the visual pipeline and before encode; when absent, filter graph is identical to M6.1 baseline.
3. Required recommendations and candidate_index selection follow every readiness case; invalid candidate_index produces a controlled failure.
4. Python and Laravel independently reject malformed inputs/results and all clip/index/output invariants; no required success field defaults.
5. A unique, owner-scoped, cascade-deleted snapshot has legal retry/error-clearing/terminal semantics; concurrent claims cannot run competing renders or corrupt results.
6. Pipeline independence, reexecution, upstream-failure behavior, and explicit final resolution are tested without altering upstream successes.
7. No production AI smart-crop, multi-aspect, configurable focal point, multi-clip, multi-aspect, review UI, publishing, frontend/API addition, credentials, network (beyond S3), model download, or unsafe logging is introduced.
8. Authentic behavior-assertion RED precedes implementation, all new/retained tests pass without weakening/skips, full regression baseline is preserved, and independent Tester approves running behavior.
9. Exactly one executing-agent evidence file has actual `### RED`, `### GREEN`, and `### REFACTOR` sections. No fabricated counts or Planner execution claims.
10. Orchestrator reconciles docs as M6.1 active/in progress. Required final-head CI logs are reviewed. Stop at `CI_GREEN_WAITING_HUMAN_MERGE`; no merge-gate invocation, merge, issue closure, or next issue is authorized.

## Security and UX implications

Rendered clips are private owner-scoped application data. Enforce minimal projection, strict schemas, local ownership, bounded inputs/processes, sanitized errors, and no payload logging. No new authentication surface or secret provisioning is required. FFmpeg runs as a subprocess with no elevated privileges; input is a local file path or Flysystem-readable caption file.

There is no new UI or render API. Existing upload/list/delete/auth/project workflows must remain visually and functionally unchanged. Tester still exercises the running application and inspects console/network/API behavior. Do not present rendered clips to users until M7 (review experience).

## Dependencies and human-gated operations

### Hard dependencies (must exist before SPEC_READY)

- FFmpeg 6.x+ installed in worker container/image with `--enable-libsubtitle` (verified via `ffmpeg -filters | grep sub` or `ffmpeg -formats | grep SRT`)
- Python 3.12 with `ffmpeg-python` or direct `subprocess` (no new heavy deps)
- Laravel Flysystem S3 adapter configured for the render output disk
- PostgreSQL 16 with JSONB support
- SRT caption files stored on the same storage disk as source media (accessible via path construction)

### Human-gated operations (blocking SPEC_READY → implementation)

- Operator confirms FFmpeg availability and version with libsubtitle support in CI/worker image
- Operator confirms SRT caption file path construction works correctly (Laravel path + storage disk)
- Operator confirms `media.render_timeout_seconds` default (300) is acceptable for CI envelope (2 CPU, 2 GiB RAM, 4 GiB disk)
- Operator confirms that adding `subtitles` filter to the filter graph does not break existing vertical reframe pipeline when no caption_file is provided

These are explicit gates in `plan.md`, not claimed available or executed. If a required environment cannot be supplied, completion remains blocked rather than silently changing acceptance.