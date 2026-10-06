# Specification: Issue #76 — Deterministic Caption Burn-In for Explicit Vertical Clip Rendering

## Authority and status

- Active issue: [#76](https://github.com/CarlosEGoulart/AiClip/issues/76), `feat(rendering): add deterministic caption burn-in for explicit vertical clip rendering`.
- Authorized branch: `@carlosegoulart/76/feat/caption-burn-in`.
- This is an M6.2 specification. The issue body was read directly during planning.
- Planner owns only this file, `plan.md`, and `test-plan.md`. Execution evidence, documentation reconciliation, implementation, testing, and lifecycle operations belong to their assigned agents.

## Goal and architectural reconciliation

Extend the M6.1 `ffmpeg_vertical_baseline` v1.0.0 render pipeline to deterministically burn in captions derived from the already-persisted `MediaTranscript.segments` array. Laravel owns input selection, authorization context, orchestration, validation, PostgreSQL persistence, and S3-compatible object storage coordination. Python computes structured rendering commands and executes FFmpeg outside HTTP requests; Python never accesses PostgreSQL.

| Source inspected | Interpretation for this issue |
|---|---|
| `docs/prd.md`, Clip Creation Workflow | Rendering is step 7; captions are a natural enhancement to vertical clips. |
| `docs/roadmap.md`, M6 | M6.2 is the next slice after M6.1 baseline: caption burn-in. |
| `docs/architecture.md`, Media Processing Flow | Step 4: Reframing → creates vertical clips. Captions are additive to reframing. |
| `docs/adr/0002-mvp-product-and-architecture.md` | Preserve Laravel persistence/business validation and worker computational boundaries. |
| `docs/project-state.md`, Next Architectural Goal | M6.2+ — Vertical Clip Rendering enhancements (captions, multi-candidate batch rendering, render profiles beyond `vertical_v1`). |
| M6.1 Issue #69 spec/plan | `render_clip` v1.0.0 singular contract, `FFmpegVerticalClipRenderer`, `RenderMediaClip` job, `DerivedAsset` persistence. Captions explicitly excluded from M6.1. |
| M4 Issue #51 spec | `MediaTranscript` model with `segments` array: `{start_ms, end_ms, text}`. Timing on original media millisecond axis. |
| M5 Issue #64 spec | `MediaClipRecommendation` candidates have `start_ms`, `end_ms`, `semantic_rank`, `semantic_score`. |

**Abstraction decision:** Extend the existing `FFmpegVerticalClipRenderer` with caption burn-in capability via an extended filter graph. The worker contract `render_clip` v1.0.0 is extended additively with an optional **top-level** `captions` object; no v2 contract change. Laravel projects caption segments from the authoritative `MediaTranscript` into the selected candidate's time range.

## Scope and exclusions

Included:
- Optional **top-level** `captions` object in `render_clip` v1.0.0 worker contract (additive, backward compatible)
- FFmpeg filter graph extension with `drawtext` filter chain for caption burn-in
- Deterministic caption styling configuration (font, size, color, position, outline, background)
- Caption segment mapping: `MediaTranscript.segments` → selected candidate time range (`start_ms`..`end_ms`)
- Escaping of untrusted transcript text for FFmpeg `drawtext` safety
- Rendering behavior when transcript/captions unavailable (graceful fallback to M6.1 behavior)
- Idempotency: same render identity produces same captioned output
- Render identity/version implications: `render_profile_version` remains `vertical_v1`; algorithm remains `ffmpeg_vertical_baseline` v1.0.0; caption config is part of `parameters.configuration`
- Persisted provenance/configuration in `DerivedAsset.render_parameters`
- FFprobe validation of output (duration, resolution, codec, caption presence via stream inspection)
- Unit, contract, integration, and E2E tests

Excluded:
- SRT/VTT file generation, subtitle tracks (separate from burn-in)
- Configurable caption editor UI (M7+)
- Multiple caption styles per clip
- Per-candidate caption customization
- Live caption editing/preview
- Multi-language caption selection (uses transcript's detected `language`)
- Real-time caption streaming
- AI-generated caption styling (deterministic config only)
- Face-aware caption placement (avoiding faces) — M6.3+
- Caption animation/transitions

## Authoritative inputs and readiness

Inputs are read afresh by Laravel from persisted state, never supplied by a public request or taken directly from an unpersisted worker response. The asset must belong to its existing project/owner chain.

1. Authoritative duration is the persisted `MediaAsset.duration_ms` (integer 1..2147483647). Require a persisted probe result and consistency with its integer `duration_ms`; missing/invalid/conflicting values fail rendering. No fallback probe duration.
2. A completed `MediaClipRecommendation` with `status=completed` and `outcome=ranked` is required. Must have at least one candidate with `semantic_rank` and `semantic_score`. Revalidate the M5 candidate snapshot before projection.
3. **Transcript is optional but preferred.** If a `MediaTranscript` with `status=completed` exists for the `MediaAsset`, its `segments` array is used for caption generation. If transcript is missing, failed, or unavailable, rendering proceeds without captions (M6.1 behavior).
4. Source video file must be accessible via `MediaAsset.storage_disk` + `storage_key` (S3-compatible).
5. Rendering is requested for exactly one candidate identified by `candidate_index` (0..K-1). The candidate must have a non-null `semantic_score`.

| Upstream situation at the render-stage boundary | Required outcome |
|---|---|
| M5 completed/ranked, transcript completed, valid `candidate_index` | Render selected clip WITH caption burn-in |
| M5 completed/ranked, transcript absent/failed, valid `candidate_index` | Render selected clip WITHOUT captions (M6.1 behavior) |
| M5 completed/ranked but `candidate_index` out of bounds or candidate has null `semantic_score` | Failed attempt with `invalid_candidate_index` |
| M5 completed/unavailable (any reason) | Claim render attempt, transition to failed with `upstream_recommendation_unavailable` |
| M5 pending/ranking/not_ready | Controlled `not_ready`; do not invoke renderer or mark render completed |
| M5 failed | Claim render attempt, transition to failed with `upstream_recommendation_failed` |
| M5 missing after M5 stage resolved | Failed attempt with `upstream_recommendation_missing` |
| Invalid persisted duration/recommendations/configuration | Failed attempt with `invalid_input` or `invalid_configuration`; preserve upstream records |

The metadata worker accepts ready inputs only; upstream statuses and database identifiers are not sent to it.

## Deterministic algorithm: `ffmpeg_vertical_baseline`, version `1.0.0` (extended)

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
| **`captions.enabled`** | `true` | Boolean; when `false`, behaves as M6.1 |
| **`captions.font_file`** | `/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf` | String, absolute path; must exist in worker container |
| **`captions.font_size`** | 72 | Strict integer, 12..200 (pts) |
| **`captions.font_color`** | `ffffff` | **Hex color without `#`, 6 chars (e.g., `ffffff`, `000000`)** |
| **`captions.outline_color`** | `000000` | **Hex color without `#`, 6 chars** |
| **`captions.outline_width`** | 3 | Strict integer, 0..10 |
| **`captions.background_color`** | `000000` | **Hex color without `#`, 6 chars** |
| **`captions.background_opacity`** | 0.5 | Float, 0.0..1.0 |
| **`captions.box_padding`** | 10 | Strict integer, 0..50 |
| **`captions.margin_bottom`** | 100 | Strict integer, 0..500 (pixels from bottom) |
| **`captions.max_chars_per_line`** | 32 | Strict integer, 10..80 |

Fixed versioned limits/policies: at most 1000 recommendations, 8388608 UTF-8 input bytes, duration at most 2147483647 ms; FFmpeg process timeout default 300s, configurable strict integer 30..300. Exceeding a limit fails closed; inputs are never silently truncated. These constants are recorded in parameters, not undisclosed heuristics.

### A. Clip selection (unchanged from M6.1)

For a completed M5 recommendation with K candidates (index 0..K-1):
1. The caller provides exactly one `candidate_index` (integer 0..K-1).
2. The selected candidate must have a non-null `semantic_score`.
3. The selected candidate produces exactly one output clip.
4. If `candidate_index` is out of bounds or the candidate has null `semantic_score`, the render fails with `invalid_candidate_index`.

### B. Worker request (extended v1.0.0 — additive)

The worker `render_clip` action (CLI: `render-clip`) expects a JSON object on stdin with exactly the following fields:

- `version`: string, exactly "1.0.0"
- `action`: string, exactly "render_clip"
- `media`: object with exactly `{ "duration_ms": <integer> }` — the authoritative duration of the source media.
- `candidate_index`: non-negative integer, the ONLY index authority carried across the worker boundary.
- `candidate`: object with exactly `{ "start_ms": <integer>, "end_ms": <integer> }` — the selected candidate bounds. NO index field.
- `configuration`: object, the vertical profile object (7 base fields + `captions` styling object).
- `source_media`: object with exactly `{ "disk": <string>, "key": <string>, "width": <integer>, "height": <integer>, "video_codec": <string>, "audio_codec": <string> }`.
- `output_storage`: object with exactly `{ "disk": <string>, "key": <string>, "mime_type": "video/mp4" }`.
- **`captions` (optional, top-level):** object with exactly `{ "enabled": <boolean>, "segments": [ { "start_ms": <integer>, "end_ms": <integer>, "text": <string> }, ... ] }` — caption segments already mapped to the candidate's local timebase (0 = candidate start). **Only present when transcript is available AND `captions.enabled=true`. When present, `enabled` MUST be `true` and `segments` MUST be a non-empty array.**

**Key contract structure clarification:**
- `configuration.captions` contains **styling only** (enabled, font_file, font_size, font_color, outline_color, outline_width, background_color, background_opacity, box_padding, margin_bottom, max_chars_per_line). It does NOT contain segments.
- Top-level `captions` contains **enabled flag + segments array** (timing + pre-escaped text).
- The Python worker combines styling from `configuration.captions` with segments from top-level `captions.segments` to build the drawtext filter chain.

No recommendation object, all candidates, recommendation_id, project_id, or media_asset_id is sent to the Python worker. Laravel independently re-reads M5/M4/transcript authority and projects only the selected candidate timing and caption segments.

### C. FFmpeg filter graph construction (per clip)

For the selected candidate with original bounds `[start_ms, end_ms)` on the source media:

1. **Trim**: `-ss <start_s> -t <duration_s> -i <input>` (input seeking for speed, `-ss` before `-i`).
2. **Vertical reframe** (9:16 = target_width:target_height):
   - `center` (baseline only): `crop=ih*9/16:ih:(iw-ih*9/16)/2:0` — center-crop horizontally to 9:16, then scale to target resolution.
3. **Scale**: `scale=target_width:target_height:force_original_aspect_ratio=decrease,pad=target_width:target_height:(ow-iw)/2:(oh-ih)/2` — fit within target, letterbox/pillarbox if needed (should not occur with crop).
4. **FPS**: `fps=target_fps` — constant frame rate output.
5. **Caption burn-in (NEW — conditional on `captions.enabled` and `captions.segments` non-empty):**
   - For each caption segment, generate a `drawtext` filter with:
     - `text='<escaped_text>'` — transcript text **already escaped by Laravel** (see Section E)
     - `fontfile=<font_file>`
     - `fontsize=<font_size>`
     - `fontcolor=<font_color>` — **6-char hex without `#`** (e.g., `ffffff`)
     - `borderw=<outline_width>`
     - `bordercolor=<outline_color>` — **6-char hex without `#`**
     - `box=1` (enable background box)
     - `boxcolor=<background_color>@<background_opacity>` — **6-char hex without `#`** + opacity
     - `boxborderw=<box_padding>`
     - `x=(w-text_w)/2` (horizontal center)
     - `y=h-text_h-<margin_bottom>` (bottom margin)
     - `enable='between(t,<seg_start_s>,<seg_end_s>)'` — show only during segment; **worker converts segment ms to seconds**
   - Multiple caption segments are chained: `drawtext=...,drawtext=...`
   - Caption filter is inserted **after FPS filter** and **before encode**.
6. **Encode**: `-c:v <video_codec> -b:v <video_bitrate_kbps>k -c:a <audio_codec> -b:a <audio_bitrate_kbps>k -movflags +faststart`.

If `captions.enabled=false` or top-level `captions` is absent/empty, steps 1-4 and 6 execute (M6.1 behavior).

### D. Caption segment mapping (Laravel-side)

Laravel is responsible for projecting `MediaTranscript.segments` into the selected candidate's local timebase:

1. Load `MediaTranscript` for the `MediaAsset` (must have `status=completed`).
2. For each segment in `transcript.segments` (original media timebase):
   - `segment_start_ms`, `segment_end_ms`, `segment_text`
3. Map to candidate-local timebase:
   - `local_start_ms = max(0, segment_start_ms - candidate_start_ms)`
   - `local_end_ms = min(candidate_duration_ms, segment_end_ms - candidate_start_ms)`
   - Only include if `local_end_ms > local_start_ms` (segment overlaps candidate)
4. Sort by `local_start_ms` ascending.
5. **Escape segment text** using `MediaTranscript::escapeCaptionText()` (see Section E).
6. Pass mapped segments as `captions.segments` in worker contract (top-level `captions` object).

If no completed transcript exists, or transcript has no segments, omit top-level `captions` object entirely from worker contract.

### E. Caption text escaping (security)

Transcript text is untrusted user content. Must escape for FFmpeg `drawtext` filter **in Laravel before sending to worker**:

1. Escape backslashes: `\` → `\\`
2. Escape colons: `:` → `\:`
3. Escape single quotes: `'` → `\'`
4. Escape percent signs: `%` → `\%` (FFmpeg format strings)
5. Remove or replace control characters (ASCII < 32) with space
6. Truncate to `captions.max_chars_per_line` per line; if text exceeds, split into multiple lines using `\n` in drawtext (each line gets its own drawtext or use `drawtext` with line breaks)

Escaping is performed by Laravel (`MediaTranscript::escapeCaptionText()`) before sending to worker. Worker treats caption text as already-escaped opaque strings and passes them directly to FFmpeg.

### F. Output naming and storage (unchanged)

- Output key: `projects/{project_id}/renders/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.mp4`
- UUID generated by Laravel when first creating the identity; retries/reclaims reuse the persisted key.
- Storage: same disk as source `MediaAsset.storage_disk`, selected by Laravel.
- MIME type: `video/mp4`.
- Worker receives this precomputed `output_storage` and does not derive project paths.
- DerivedAsset record: `type=clip_rendered`, linked to `MediaAsset`, with `storage_key`, `mime_type`, `size_bytes`, `duration_ms`, `width`, `height`, `codec`, `bitrate`, and extended columns: `candidate_index`, `render_profile_version`, `render_status`, `render_started_at`, `render_completed_at`, `render_error`.

### G. Result serialization (extended)

Top-level object has only `status: "success"` and required `render` object:

- `algorithm`: nonblank string, exactly `ffmpeg_vertical_baseline`.
- `algorithm_version`: nonblank string, exactly `1.0.0`.
- `parameters`: required object with exactly:
  - `configuration`: complete validated request configuration (including `captions` styling object from `configuration.captions`).
  - `source_media`: `{disk, key, duration_ms, width, height, video_codec, audio_codec}` — from probe.
  - `ffmpeg_version`: string from `ffmpeg -version` first line.
  - `filter_graph`: string — the exact filter_complex used (including caption drawtext chain if applicable).
  - `limits`: `{max_recommendations: 1000, max_input_bytes: 8388608, max_duration_ms: 2147483647}`.
- `clips`: required list with exactly one object containing exactly:
  - `candidate_index`: non-negative integer copied from the validated request.
  - `start_ms`, `end_ms`: strict integers matching the selected candidate bounds.
  - `duration_ms`: `end_ms - start_ms`.
  - `output`: object with `disk`, `key`, `size_bytes`, `duration_ms`, `width`, `height`, `video_codec`, `audio_codec`, `video_bitrate_kbps`, `audio_bitrate_kbps`.
- No timestamps, request IDs, prose, storage information beyond above, or unknown output fields.

### H. Errors, execution, and privacy (extended)

- CLI success emits exactly one strict JSON envelope to stdout, exit 0.
- Invalid JSON/contract/action/version/configuration/input emits `{status:"error",code:"invalid_contract",error:"Invalid render contract",stderr:""}`, exit 2.
- Runtime/result-validation failure (FFmpeg error, storage write error, validation mismatch, caption filter error) emits same shape with `code:"render_failed"` and `error:"Clip render failed"`, exit 1.
- Do not echo rejected values or schema-library exception messages. No partial success.
- Strict JSON rejects NaN/Infinity on input and output, including overflow to infinity.
- Laravel launches an argument-list process for `render-clip`, with the contract on **stdin**, not the command line. No shell interpolation.
- New action performs no database access or network requests (except S3 via configured Flysystem adapter if worker writes directly; baseline: Laravel writes via Flysystem after worker returns output path). Reading its contract stream and packaged schema is allowed.
- `media.render_timeout_seconds` defaults to 300, integer 30..300. Lock wait bound is timeout plus 10 seconds. Persist both as Laravel-owned `execution_parameters` for the attempt; they are operational settings, not score inputs or worker configuration.
- Do not forward raw stdout, stderr, payloads, media content, or arbitrary exception messages into exceptions, queue failure records, database errors, or logs for this stage. Use fixed error codes/messages.
- Logs may contain only existing local asset ID, stage, fixed error code, elapsed time, and counts. No filter graphs, criteria dump, or contract dump.
- **Caption text must never appear in logs, exceptions, or error envelopes.**

## Persistence, validation, and ownership

### Storage decision (unchanged schema, extended data)

`DerivedAsset` / `derived_assets` with render-specific columns (from M6.1):
- `candidate_index` (integer, nullable) — M5 candidate index (0..K-1)
- `render_profile_version` (string, nullable) — e.g., `vertical_v1`
- `render_status` (string, nullable) — e.g., `pending`, `rendering`, `completed`, `failed`
- `render_error` (string, nullable) — sanitized error code for failed renders
- `render_started_at` (datetime, nullable) — when render began
- `render_completed_at` (datetime, nullable) — when render finished

Unique composite index on `(media_asset_id, type, candidate_index, render_profile_version)` where `type = 'clip_rendered'` — one render per asset per candidate per profile version.

`MediaAsset::renderedClips()` has-many scoped to `type = 'clip_rendered'`.

### Independent Laravel success validation (extended)

Validate in PHP even when a test double or compromised worker reports `status=success`. Before any result/status write, validate the exact envelope/object shapes, algorithm/version, all parameter fields and equality with the locally selected configuration/policies, clips as a list with exactly one element, all types/finiteness/bounds, requested `candidate_index` equality, exact matching selected-candidate bounds, output file existence, and metadata consistency.

Independently verify:
- The clip's `candidate_index` exactly matches the explicitly selected index that Laravel already validated against the authoritative recommendation.
- The clip's `start_ms`/`end_ms` exactly match the selected authoritative candidate bounds.
- Output file exists on storage, size > 0, duration within +/- 50ms of expected, resolution matches configuration.
- FFmpeg version recorded.
- Filter graph is non-empty.
- **If captions were requested: filter graph contains `drawtext` filter chain.**
- Duration tolerance: candidate duration ±50ms.

Missing algorithm/parameters/clips must fail even for zero clips. Reject unsupported algorithms/versions rather than persisting misleading provenance.

Perform the same invariant checks at the model's completion boundary so another caller cannot bypass the service validator. Share PHP validation code between service/model, not worker implementation code. Do not mutate successful recommendation/analysis/transcript rows when rejecting results.

### Lifecycle and concurrent claim (unchanged from M6.1)

Legal transitions only: `pending -> rendering -> completed`, `rendering -> failed`, `failed -> rendering`. Starting a retry clears stale error and any incomplete result fields. `completed` is terminal and reused unchanged, including after configuration changes. Invalid transitions do not mutate stored data; do not silently report a new successful render.

Use PostgreSQL transaction-scoped exclusive row locking, not merely `firstOrCreate`, an in-memory flag, or a unique index:
1. Safely establish the unique pending row (insert-on-conflict/reread); handle concurrent first creation and deletion without duplicate rows.
2. Begin a transaction, lock that row with `SELECT ... FOR UPDATE`, then re-read status and current upstream state. The row lock is the exclusive claim. A completed row is returned without worker invocation or timestamp/result changes.
3. For a ready attempt or controlled upstream failure, transition pending/failed to rendering, clear error, capture inputs/configuration, invoke at most one bounded render process if eligible to run, independently validate, and atomically complete or fail **inside the same transaction**. Catch expected worker/validation errors inside the transaction so the sanitized failure commits. Do not hold this lock during probe, scene detection, extraction, transcription, or recommendation.
4. A second invocation cannot enter the renderer until the first transaction releases its claim. After completion it must reuse the result; after failure a retry may run serially. A lock-timeout contender returns `busy`, never changes the owning attempt or claims success. The owning job remains responsible for finalization.
5. An unhandled exception/process crash rolls back the transaction, including rendering state and partial JSON. The prior pending/failed state remains recoverable by the existing queue retry policy. No committed unowned rendering row is created by this design. The queue exhaustion/failure path must resolve an unclaimed pending/failed render attempt with a sanitized failure (via rendering) and resolve the asset to a terminal state; it must not overwrite an independently completed/actively locked attempt.

## Render job boundary: `RenderMediaClip` (extended)

M6.2 extends the existing `RenderMediaClip` job to include caption projection.

### Job: `RenderMediaClip`

- **Purpose**: Render exactly one clip from an explicitly selected M5 candidate, with optional caption burn-in.
- **Invocation**: Internal caller (service, command, or future M7 workflow) provides:
  - `MediaAsset` ID
  - `MediaClipRecommendation` ID
  - `candidate_index` (integer, 0..K-1) — **no default, no auto-selection**
- **Behavior**:
  1. Independently re-reads M5 recommendation, M4 analysis, and M4 transcript authority from database.
  2. Verifies the selected candidate exists, has non-null `semantic_score`, and bounds match the persisted snapshot.
  3. Verifies source media is accessible (probe, storage).
  4. **Projects caption segments**: If `MediaTranscript` with `status=completed` exists, maps segments to candidate-local timebase; builds `captions.segments` array with **escaped text**.
  5. Builds render contract with explicit `candidate_index`, caption segments (if available), and configuration from `RenderProfile`.
  6. Invokes worker `render_clip` action via `ProcessMediaAction`.
  7. Validates result independently via `RenderValidator`.
  8. Persists `DerivedAsset` with `type=clip_rendered`, `candidate_index`, `render_profile_version`, configuration, parameters (including caption config and filter graph).
  9. Returns `DerivedAsset` on success; throws sanitized exception on failure.
- **Idempotency**: Re-invocation with same `(media_asset_id, candidate_index, render_profile_version)` returns existing completed `DerivedAsset` without re-executing worker, provided the persisted authority snapshot matches the current request. If the same unique render identity exists but current recommendation authority/candidate timing/transcript differs, fail closed with `RenderVersionConflictException`; do not overwrite completed artifact and do not silently regenerate under same identity.
- **Concurrency**: Row-level lock on `DerivedAsset` (same pattern as M4/M5/M6.1). Competing invocations return `busy`.
- **Failure modes**: All sanitized error codes per readiness table above. No partial results.

### ProcessMediaAsset integration (unchanged)

**`ProcessMediaAsset` does NOT automatically invoke rendering after M5.**  
**`ProcessMediaAsset` completion does NOT depend on `clipRenderResolved`.**  
**No `clipRenderResolved` flag is introduced in `ProcessMediaAsset`.**

The existing `ProcessMediaAsset` flow remains unchanged after M5:
- probe → scene detection → audio extraction/transcription → clip analysis (M4) → clip recommendation (M5) → asset finalization
- Asset finalization requires only the existing resolved stages (probe, scene, audio, clip analysis, clip recommendation).
- Rendering is a **separate, explicitly invoked capability** that M7 (or an internal admin command) will drive.

### Rerun and reuse semantics (extended)

- `RenderMediaClip` reuses valid persisted `DerivedAsset` for the same `(media_asset_id, candidate_index, render_profile_version)`, comparing the persisted authority snapshot against the current request. The snapshot now includes **transcript state (completed/absent/failed) and transcript content hash**. If the snapshot differs (different recommendation authority, candidate timing, or transcript content), fail closed with `RenderVersionConflictException`; a new render row is not created under the same identity.
- A failed render attempt may be retried by re-invoking `RenderMediaClip` (clears error, re-attempts).
- Completed renders never re-execute.
- Assets lacking a valid persisted probe must not be used to fabricate render input.

## Observable acceptance criteria

1. The real FFmpeg renderer and metadata-only `render_clip` v1.0.0 action implement the exact extended algorithm, parameters, errors, and privacy boundary above.
2. Required recommendations and candidate_index selection follow every readiness case; invalid candidate_index produces a controlled failure.
3. Python and Laravel independently reject malformed inputs/results and all clip/index/output invariants; no required success field defaults.
4. A unique, owner-scoped, cascade-deleted snapshot has legal retry/error-clearing/terminal semantics; concurrent claims cannot run competing renders or corrupt results.
5. Caption burn-in works deterministically: same transcript + same candidate + same config = same output.
6. Graceful fallback: when transcript unavailable, rendering produces valid clip without captions (M6.1 behavior).
7. Caption text is properly escaped; no FFmpeg injection possible.
8. Pipeline independence, reexecution, upstream-failure behavior, and explicit final resolution are tested without altering upstream successes.
9. No production AI smart-crop, multi-aspect, face tracking, review UI, publishing, frontend/API addition, credentials, network (beyond S3), model download, or unsafe logging is introduced.
10. Authentic behavior-assertion RED precedes implementation, all new/retained tests pass without weakening/skips, full regression baseline is preserved, and independent Tester approves running behavior.
11. Exactly one executing-agent evidence file has actual `### RED`, `### GREEN`, and `### REFACTOR` sections. No fabricated counts or Planner execution claims.
12. Orchestrator reconciles docs as M6.2 active/in progress. Required final-head CI logs are reviewed. Stop at `CI_GREEN_WAITING_HUMAN_MERGE`; no merge-gate invocation, merge, issue closure, or next issue is authorized.

## Security and UX implications

Rendered clips are private owner-scoped application data. Enforce minimal projection, strict schemas, local ownership, bounded inputs/processes, sanitized errors, and no payload logging. No new authentication surface or secret provisioning is required. FFmpeg runs as a subprocess with no elevated privileges; input is a local file path or Flysystem-readable stream.

**Caption-specific security:**
- Transcript text is untrusted; escaping is mandatory and validated.
- No transcript text in logs, errors, or worker output beyond filter graph (which is internal provenance).
- Font file path is configuration-only; worker validates file exists.

There is no new UI or render API. Existing upload/list/delete/auth/project workflows must remain visually and functionally unchanged. Tester still exercises the running application and inspects console/network/API behavior. Do not present rendered clips to users until M7 (review experience).

## Dependencies and human-gated operations

### Hard dependencies (must exist before SPEC_READY)

- FFmpeg 6.x+ installed in worker container/image with `drawtext` filter support (verified via `ffmpeg -filters | grep drawtext`)
- FreeType font rendering support in FFmpeg build (required for `drawtext`)
- DejaVu Sans Bold font at `/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf` in worker container/image (or configurable alternative)
- Python 3.12 with `ffmpeg-python` or direct `subprocess` (no new heavy deps)
- Laravel Flysystem S3 adapter configured for the render output disk
- PostgreSQL 16 with JSONB support
- Existing M6.1 infrastructure: `RenderProfile`, `RenderValidator`, `StorageKeyBuilder`, `DerivedAsset` render columns, `RenderMediaClip` job, `ProcessMediaAction::renderClips()`

### Human-gated operations (blocking SPEC_READY → implementation)

- Operator confirms FFmpeg availability, version, and `drawtext` filter support in CI/worker image
- Operator confirms FreeType/font rendering support in FFmpeg build
- Operator provisions/confirms DejaVu Sans Bold font (or alternative) in worker container/image
- Operator provisions/confirms S3-compatible bucket for render outputs (can reuse media bucket with `renders/` prefix)
- Operator confirms `media.render_timeout_seconds` default (300) is acceptable for CI envelope (2 CPU, 2 GiB RAM, 4 GiB disk) — caption burn-in adds minimal overhead

These are explicit gates in `plan.md`, not claimed available or executed. If a required environment cannot be supplied, completion remains blocked rather than silently changing acceptance.

## Contradiction Resolution Log (Planner authority)

The following contradictions existed across spec, JSON schema, Laravel, and Python implementations. This spec is the **single authoritative resolution**.

| # | Contradiction | Resolution (authoritative) |
|---|---|---|
| A | Configuration shape: where do segments live? | `configuration.captions` = styling ONLY. Top-level `captions` = `{ "enabled": bool, "segments": [...] }`. |
| B | Segment payload shape: top-level captions structure | Top-level `captions` object MUST be `{ "enabled": true, "segments": [...] }`. Only present when transcript available AND `captions.enabled=true`. |
| C | Worker composition: how does Python combine style + segments? | Python reads styling from `configuration.captions`, segments from top-level `captions.segments`. Builds drawtext chain using both. |
| D | Color representation: hex format | **Canonical: 6-char hex WITHOUT `#`** (e.g., `ffffff`, `000000`). Used in spec, Laravel, JSON schema. Python validator/renderer MUST accept this format. |
| E | Time representation: ms vs seconds | Contract uses **milliseconds** (candidate-local). Python worker converts to seconds for FFmpeg `enable='between(t,start_s,end_s)'`. |
| F | Text escaping: who escapes? | **Laravel escapes** via `MediaTranscript::escapeCaptionText()` before sending. Python treats text as pre-escaped opaque strings. |
| G | Provenance: what goes where | `parameters.configuration` includes full caption styling config. `filter_graph` includes drawtext chain. Authority snapshot includes transcript state + content hash. |
| H | Render identity: what triggers conflict | **Caption config changes** (in `configuration.captions`) OR **transcript changes** (state or content hash) → `RenderVersionConflictException`. |
| I | **Validation contract: worker output metadata authority** | **Worker is authoritative for actual output (probed from file).** Worker MUST return **probed values** for `output.video_codec`, `output.audio_codec`, `output.width`, `output.height`, `output.duration_ms`, `output.video_bitrate_kbps`, `output.audio_bitrate_kbps` from `ffprobe` of the output file. **Critical: `video_codec` MUST be the probed codec name (e.g., `h264`, `hevc`), NOT the FFmpeg encoder name from configuration (e.g., `libx264`, `libx265`).** Configuration values (e.g., `libx264`) are for encoding only. Laravel `RenderValidator` validates: (1) SHA256 binding matches exact request bytes sent to worker; (2) filter graph contains `drawtext` iff captions requested; (3) clip candidate_index/start_ms/end_ms match request; (4) output resolution matches configuration target; (5) output metadata types/bounds are valid; (6) configuration echoed in parameters matches request. Worker's current behavior of returning configuration values instead of probed values is a contract violation. |
| J | **SHA256 binding computation** | **Canonical JSON required.** Laravel computes SHA256 from the exact JSON bytes sent to worker stdin. Worker computes SHA256 from raw stdin bytes read. Both MUST use identical canonical serialization: JSON with **sorted keys, no whitespace**, strict UTF-8, no trailing newline. In PHP: `json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` after recursively sorting all object keys. In Python: `json.dumps(request, separators=(',', ':'), sort_keys=True, ensure_ascii=False)`. Any difference (key order, whitespace, encoding, trailing newline) breaks the binding and MUST fail validation. |