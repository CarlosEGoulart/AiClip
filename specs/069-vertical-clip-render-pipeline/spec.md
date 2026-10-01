# Specification: Issue #69 — Durable Baseline Vertical Clip Render Pipeline

## Authority and status

- Active issue: [#69](https://github.com/CarlosEGoulart/AiClip/issues/69), `feat(rendering): add durable baseline vertical clip render pipeline`.
- Authorized recovery branch: `@carlosegoulart/69/feat/vertical-clip-render-pipeline-recovery-v3`.
- Recovery baseline checkpoint: `3099837778056bf69d603d49bdc771546aaddd81`; historical PRs #70, #71, and #72 are closed unmerged while Issue #69 remains active.
- This is an M6.1 foundation specification. The issue body was read directly during planning.
- Planner owns only this file, `plan.md`, and `test-plan.md`. Execution evidence, documentation reconciliation, implementation, testing, and lifecycle operations belong to their assigned agents.

## Goal and architectural reconciliation

Produce a durable, versioned, rendered vertical clip artifact and metadata from a single explicitly selected M5 recommendation candidate. Laravel owns input selection, authorization context, orchestration, validation, PostgreSQL persistence, and S3-compatible object storage coordination. Python computes structured rendering commands and executes FFmpeg outside HTTP requests; Python never accesses PostgreSQL.

| Source inspected | Interpretation for this issue |
|---|---|
| `docs/prd.md`, Clip Creation Workflow | Rendering is step 7: "System renders vertical clips" — this is the first slice of rendering. |
| `docs/roadmap.md`, M6 | M6 is Vertical Clip Rendering. M6.1 is the first slice: baseline vertical render pipeline with FFmpeg. |
| `docs/architecture.md`, Media Processing Flow | Step 4: Reframing → creates vertical clips. This issue covers baseline reframing only. |
| `docs/architecture.md`, Python Media Worker capabilities | Vertical reframing with center-crop, FFmpeg rendering. |
| `docs/adr/0002-mvp-product-and-architecture.md` | Preserve Laravel persistence/business validation and worker computational boundaries. |
| `docs/project-state.md`, Current Milestone/Next Architectural Goal | M6 is next but not active/authorized/implemented. This issue authorizes the M6.1 slice. |
| #58 (M4 candidate analysis), #64 (M5 semantic ranking) | M6.1 consumes M5 recommendations (`media_clip_recommendations` with `semantic_rank` and `semantic_score`). One explicitly selected candidate is rendered. |
| Existing worker topology | Keep Laravel job-to-Python CLI completion contract. Python receives metadata-only contracts via stdin; no object storage reads. |

**Abstraction decision:** introduce one Python `VerticalClipRenderer` interface with `render(validated_input, configuration) -> RenderResult` and one real `FFmpegVerticalClipRenderer`. Laravel's independent validator is a trust-boundary validator. Future M7 (Clip Review Experience) may consume rendered clips through review/approval UI; that integration is not implemented here.

## Scope and exclusions

Included:
- Metadata-only worker action `render_clip` v1.0.0 with CLI subcommand `render-clip`
- FFmpeg-based vertical reframing (9:16) with deterministic center-crop only
- Single render job producing exactly one output file for one explicitly selected candidate (by `candidate_index`)
- Additive v1 contract extension; no v2
- Persistence via `DerivedAsset` extension with `type=clip_rendered`, `candidate_index`, `render_profile_version`
- Configuration/provenance snapshot (exact FFmpeg filter graph, codec, bitrate, resolution)
- Retry/terminal semantics, concurrency protection, orchestration integration
- Unit, contract, integration, and E2E tests; authorized factual documentation reconciliation by Orchestrator

Excluded:
- Captions, subtitle generation, SRT/VTT, caption burn-in, styling, editor
- Face tracking, MediaPipe, OpenCV, face-aware crop, AI smart reframing, subject tracking
- Configurable focal point UI
- **Automatic `semantic_rank=1` rendering (or top-N) — candidate is explicitly selected by index**
- Multiple clips per render job
- Multiple aspect ratios (9:16 only for baseline)
- Multi-segment concatenation / B-roll insertion
- Clip review/approval UI — M7
- Social publishing — M10+
- Candidate endpoints, frontend changes
- General queue redesign, unrelated refactors, governance/control-plane changes
- Real-time progress streaming, webhooks
- Transcription re-generation, scene re-detection

## Authoritative inputs and readiness

Inputs are read afresh by Laravel from persisted state, never supplied by a public request or taken directly from an unpersisted worker response. The asset must belong to its existing project/owner chain. No new ownership field or bypass is introduced.

1. Authoritative duration is the persisted `MediaAsset.duration_ms` (integer 1..2147483647). Require a persisted probe result and consistency with its integer `duration_ms`; missing/invalid/conflicting values fail rendering. No fallback probe duration.
2. A completed `MediaClipRecommendation` with `status=completed` and `outcome=ranked` is required. Must have at least one candidate with `semantic_rank` and `semantic_score`. Revalidate the M5 candidate snapshot before projection: K candidates with index 0..K-1, unique M4 ranks, unchanged bounds/scores/criteria, source-scene references intact.
3. **Transcript segments are not used** — captions are out of scope for M6.1.
4. Source video file must be accessible via `MediaAsset.storage_disk` + `storage_key` (S3-compatible). No signed URLs or temporary credentials cross the worker boundary; worker receives only the storage path/key for the source media.
5. Rendering is requested for **exactly one** candidate identified by `candidate_index` (0..K-1). The candidate must have a non-null `semantic_score`.

| Upstream situation at the render-stage boundary | Required outcome |
|---|---|
| M5 completed/ranked with >=1 candidate, video available, valid `candidate_index` | Render the selected clip, complete with metadata |
| M5 completed/ranked but `candidate_index` out of bounds or candidate has null `semantic_score` | Failed attempt with `invalid_candidate_index` |
| M5 completed/unavailable (any reason) | Claim render attempt, transition to failed with `upstream_recommendation_unavailable` |
| M5 pending/ranking/not_ready | Controlled `not_ready`; do not invoke renderer or mark render completed |
| M5 failed | Claim render attempt, transition to failed with `upstream_recommendation_failed` |
| M5 missing after M5 stage resolved | Failed attempt with `upstream_recommendation_missing` |
| Invalid persisted duration/recommendations/configuration | Failed attempt with `invalid_input` or `invalid_configuration`; preserve upstream records |

The metadata worker accepts ready inputs only; upstream statuses and database identifiers are not sent to it.

## Deterministic algorithm: `ffmpeg_vertical_baseline`, version `1.0.0`

### Configuration and fixed policies

Laravel supplies a fully expanded `configuration` object; worker code must not consult hidden environment/model settings or infer missing configuration values. No client-controlled overrides are added.

| Configurable field | Default | Validation |
|---|---:|---|
| `target_width` | 1080 | Strict integer, 1..4096, even (FFmpeg requirement) |
| `target_height` | 1920 | Strict integer, 1..4096, even |
| `target_fps` | 30 | Strict integer, 1..120 |
| `video_codec` | `libx264` | Enum: `libx264`, `libx265`, `h264_videotoolbox`, `hevc_videotoolbox` |
| `video_bitrate_kbps` | 5000 | Strict integer, 500..50000 |
| `audio_codec` | `aac` | Enum: `aac`, `libfdk_aac`, `copy` |
| `audio_bitrate_kbps` | 128 | Strict integer, 32..320 |

Fixed versioned limits/policies: at most 1000 recommendations, 8388608 UTF-8 input bytes, duration at most 2147483647 ms; FFmpeg process timeout default 300s, configurable strict integer 30..300. Exceeding a limit fails closed; inputs are never silently truncated. These constants are recorded in parameters, not undisclosed heuristics.

### A. Clip selection

For a completed M5 recommendation with K candidates (index 0..K-1):
1. The caller provides exactly one `candidate_index` (integer 0..K-1).
2. The selected candidate must have a non-null `semantic_score`.
3. The selected candidate produces exactly one output clip.
4. If `candidate_index` is out of bounds or the candidate has null `semantic_score`, the render fails with `invalid_candidate_index`.

### B. Worker request

The worker `render_clip` action (CLI: `render-clip`) expects a JSON object on stdin with exactly the following fields:
- `version`: string, exactly "1.0.0"
- `action`: string, exactly "render_clip"
- `media`: object with exactly `{ "duration_ms": <integer> }` — the authoritative duration of the source media.
- `candidate_index`: non-negative integer, the ONLY index authority carried across the worker boundary. Laravel has already validated that it is within the authoritative M5 candidate range (0..K-1).
- `candidate`: object with exactly `{ "start_ms": <integer>, "end_ms": <integer> }` — the selected candidate bounds. NO index field.
- `configuration`: object, the vertical profile object (e.g., `{ "target_width": 1080, "target_height": 1920, ... }`).
- `source_media`: object with exactly `{ "disk": <string>, "key": <string>, "width": <integer>, "height": <integer>, "video_codec": <string>, "audio_codec": <string> }`.
- `output_storage`: object with exactly `{ "disk": <string>, "key": <string>, "mime_type": "video/mp4" }`.

No recommendation object, all candidates, recommendation_id, project_id, or media_asset_id is sent to the Python worker. Laravel independently re-reads M5/M4 authority and projects only the selected candidate timing.

### C. FFmpeg filter graph construction (per clip)

For the selected candidate with original bounds `[start_ms, end_ms)` on the source media:

1. **Trim**: `-ss <start_s> -t <duration_s> -i <input>` (input seeking for speed, `-ss` before `-i`).
2. **Vertical reframe** (9:16 = target_width:target_height):
    - `center` (baseline only): `crop=ih*9/16:ih:(iw-ih*9/16)/2:0` — center-crop horizontally to 9:16, then scale to target resolution.
3. **Scale**: `scale=target_width:target_height:force_original_aspect_ratio=decrease,pad=target_width:target_height:(ow-iw)/2:(oh-ih)/2` — fit within target, letterbox/pillarbox if needed (should not occur with crop).
4. **FPS**: `fps=target_fps` — constant frame rate output.
5. **Encode**: `-c:v <video_codec> -b:v <video_bitrate_kbps>k -c:a <audio_codec> -b:a <audio_bitrate_kbps>k -movflags +faststart`.

No caption filter chain. No face-aware crop. No smart crop variants.

### D. Output naming and storage

- Output key: `projects/{project_id}/renders/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.mp4`
- UUID generated by Laravel when first creating the identity; retries/reclaims reuse the persisted key, do not generate a new UUID per retry.
- Storage: same disk as source `MediaAsset.storage_disk`, selected by Laravel.
- MIME type: `video/mp4`.
- Worker receives this precomputed `output_storage` and does not derive project paths.
- DerivedAsset record: `type=clip_rendered`, linked to `MediaAsset`, with `storage_key`, `mime_type`, `size_bytes`, `duration_ms`, `width`, `height`, `codec`, `bitrate`, and extended columns: `candidate_index` (integer), `render_profile_version` (string, e.g., `vertical_v1`), `render_status`, `render_started_at`, `render_completed_at`, `render_error`.

### E. Result serialization

Top-level object has only `status: "success"` and required `render` object:

- `algorithm`: nonblank string, exactly `ffmpeg_vertical_baseline`.
- `algorithm_version`: nonblank string, exactly `1.0.0`.
- `parameters`: required object with exactly:
  - `configuration`: complete validated request configuration.
  - `source_media`: `{disk, key, duration_ms, width, height, video_codec, audio_codec}` — from probe.
  - `ffmpeg_version`: string from `ffmpeg -version` first line.
  - `filter_graph`: string — the exact filter_complex used.
  - `limits`: `{max_recommendations: 1000, max_input_bytes: 8388608, max_duration_ms: 2147483647}`.
- `clips`: required list with exactly one object containing exactly:
  - `candidate_index`: non-negative integer copied from the validated request.
  - `start_ms`, `end_ms`: strict integers matching the selected candidate bounds.
  - `duration_ms`: `end_ms - start_ms`.
  - `output`: object with `disk`, `key`, `size_bytes`, `duration_ms`, `width`, `height`, `video_codec`, `audio_codec`, `video_bitrate_kbps`, `audio_bitrate_kbps`.
- `semantic_rank` and `semantic_score` do not cross the worker boundary. Laravel retains those values in its authoritative recommendation snapshot and may persist them as Laravel-owned provenance if needed.
- No timestamps, request IDs, prose, storage information beyond above, or unknown output fields.

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

Extend `DerivedAsset` / `derived_assets` with render-specific columns:
- `candidate_index` (integer, nullable) — M5 candidate index (0..K-1)
- `render_profile_version` (string, nullable) — e.g., `vertical_v1`
- `render_status` (string, nullable) — e.g., `pending`, `rendering`, `completed`, `failed`
- `render_error` (string, nullable) — sanitized error code for failed renders
- `render_started_at` (datetime, nullable) — when render began
- `render_completed_at` (datetime, nullable) — when render finished

Unique composite index on `(media_asset_id, type, candidate_index, render_profile_version)` where `type = 'clip_rendered'` — one render per asset per candidate per profile version. If the same candidate is re-rendered with a different profile version, a new row is created.

`MediaAsset::renderedClips()` has-many scoped to `type = 'clip_rendered'`.

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

M6.1 implements a **dedicated, reusable render job** that is **explicitly invoked** with an authoritative candidate reference. This job is **not** an automatic stage in `ProcessMediaAsset`.

### Job: `RenderMediaClip`

- **Purpose**: Render exactly one clip from an explicitly selected M5 candidate.
- **Invocation**: Internal caller (service, command, or future M7 workflow) provides:
  - `MediaAsset` ID
  - `MediaClipRecommendation` ID
  - `candidate_index` (integer, 0..K-1) — **no default, no auto-selection**
- **Behavior**:
  1. Independently re-reads M5 recommendation and M4 analysis authority from database.
  2. Verifies the selected candidate exists, has non-null `semantic_score`, and bounds match the persisted snapshot.
  3. Verifies source media is accessible (probe, storage).
  4. Builds render contract with explicit `candidate_index` and configuration from `RenderProfile`.
  5. Invokes worker `render_clip` action via `ProcessMediaAction`.
  6. Validates result independently via `RenderValidator`.
  7. Persists `DerivedAsset` with `type=clip_rendered`, `candidate_index`, `render_profile_version`, configuration, parameters.
  8. Returns `DerivedAsset` on success; throws sanitized exception on failure.
- **Idempotency**: Re-invocation with same `(media_asset_id, candidate_index, render_profile_version)` returns existing completed `DerivedAsset` without re-executing worker, provided the persisted authority snapshot matches the current request. If the same unique render identity exists but current recommendation authority/candidate timing differs, fail closed with `RenderVersionConflictException`; do not overwrite completed artifact and do not silently regenerate under same identity.
- **Concurrency**: Row-level lock on `DerivedAsset` (same pattern as M4/M5). Competing invocations return `busy`.
- **Failure modes**: All sanitized error codes per readiness table above. No partial results.

### ProcessMediaAsset integration

**`ProcessMediaAsset` does NOT automatically invoke rendering after M5.**  
**`ProcessMediaAsset` completion does NOT depend on `clipRenderResolved`.**  
**No `clipRenderResolved` flag is introduced in `ProcessMediaAsset`.**

The existing `ProcessMediaAsset` flow remains unchanged after M5:
- probe → scene detection → audio extraction/transcription → clip analysis (M4) → clip recommendation (M5) → asset finalization
- Asset finalization requires only the existing resolved stages (probe, scene, audio, clip analysis, clip recommendation).
- Rendering is a **separate, explicitly invoked capability** that M7 (or an internal admin command) will drive.

If an internal caller wishes to render after `ProcessMediaAsset` completes, it invokes `RenderMediaClip` separately. The asset's terminal state is unaffected by render success or failure.

### Rerun and reuse semantics

- `RenderMediaClip` reuses valid persisted `DerivedAsset` for the same `(media_asset_id, candidate_index, render_profile_version)`, comparing the persisted authority snapshot against the current request. If the snapshot differs (different recommendation authority or candidate timing), fail closed with `RenderVersionConflictException`; do not overwrite completed artifact and do not silently regenerate under same identity.
- A failed render attempt may be retried by re-invoking `RenderMediaClip` (clears error, re-attempts).
- Completed renders never re-execute.
- If the unique DB identity `(media_asset_id, type, candidate_index, render_profile_version)` matches but the persisted authority snapshot differs, `RenderVersionConflictException` is thrown; a new render row is not created under the same identity.
- Assets lacking a valid persisted probe must not be used to fabricate render input.

## Observable acceptance criteria

1. The real FFmpeg renderer and metadata-only `render_clip` v1 action implement the exact algorithm, parameters, errors, and privacy boundary above.
2. Required recommendations and candidate_index selection follow every readiness case; invalid candidate_index produces a controlled failure.
3. Python and Laravel independently reject malformed inputs/results and all clip/index/output invariants; no required success field defaults.
4. A unique, owner-scoped, cascade-deleted snapshot has legal retry/error-clearing/terminal semantics; concurrent claims cannot run competing renders or corrupt results.
5. Pipeline independence, reexecution, upstream-failure behavior, and explicit final resolution are tested without altering upstream successes.
6. No production AI smart-crop, multi-aspect, captions, face tracking, review UI, publishing, frontend/API addition, credentials, network (beyond S3), model download, or unsafe logging is introduced.
7. Authentic behavior-assertion RED precedes implementation, all new/retained tests pass without weakening/skips, full regression baseline is preserved, and independent Tester approves running behavior.
8. Exactly one executing-agent evidence file has actual `### RED`, `### GREEN`, and `### REFACTOR` sections. No fabricated counts or Planner execution claims.
9. Orchestrator reconciles docs as M6.1 active/in progress. Required final-head CI logs are reviewed. Stop at `CI_GREEN_WAITING_HUMAN_MERGE`; no merge-gate invocation, merge, issue closure, or next issue is authorized.

## Security and UX implications

Rendered clips are private owner-scoped application data. Enforce minimal projection, strict schemas, local ownership, bounded inputs/processes, sanitized errors, and no payload logging. No new authentication surface or secret provisioning is required. FFmpeg runs as a subprocess with no elevated privileges; input is a local file path or Flysystem-readable stream.

There is no new UI or render API. Existing upload/list/delete/auth/project workflows must remain visually and functionally unchanged. Tester still exercises the running application and inspects console/network/API behavior. Do not present rendered clips to users until M7 (review experience).

## Dependencies and human-gated operations

### Hard dependencies (must exist before SPEC_READY)

- FFmpeg 6.x+ installed in worker container/image (verified via `ffmpeg -version`)
- Python 3.12 with `ffmpeg-python` or direct `subprocess` (no new heavy deps)
- Laravel Flysystem S3 adapter configured for the render output disk
- PostgreSQL 16 with JSONB support

### Human-gated operations (blocking SPEC_READY → implementation)

- Operator confirms FFmpeg availability and version in CI/worker image
- Operator provisions/confirms S3-compatible bucket for render outputs (can reuse media bucket with `renders/` prefix)
- Operator confirms `media.render_timeout_seconds` default (300) is acceptable for CI envelope (2 CPU, 2 GiB RAM, 4 GiB disk)

These are explicit gates in `plan.md`, not claimed available or executed. If a required environment cannot be supplied, completion remains blocked rather than silently changing acceptance.