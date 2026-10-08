# Specification: Issue #79 — M6.2 Caption Projection Slice 2

## Authority and status

- Active issue: [#79](https://github.com/CarlosEGoulart/AiClip/issues/79), `feat(transcript): project segments to clip-local time`.
- Authorized branch: `@carlosegoulart/79/feat/caption-projection`.
- This is the second slice of M6.2: Segment Projection. Slice 1 (`caption_file` burn-in) is completed and merged via PR #78. Slice 2 is the next atomic slice.
- Planner owns this spec.md, plan.md, and test-plan.md. Execution evidence, implementation, testing, and lifecycle operations belong to their assigned agents.

## Goal and architectural reconciliation

Project/normalize `MediaTranscript.segments` from absolute source-media timestamps to the clip's local time coordinate `[start_ms, end_ms)` defined by the selected M5 candidate. This is a pure data transformation step that enables correct caption timing when FFmpeg subtitles filter (Slice 1) is applied.

### Included:

- `project_segments_to_clip_local()` function in `services/worker/aiclip_worker/rendering.py`
- Pure Python transformation: segments[{start_ms, end_ms, text}] → [{local_start_ms, local_end_ms, text}]
- Filter-out segments entirely outside [clip_start, clip_end)
- Clamp segments to [0, clip_duration] when partially overlapping
- No FFmpeg calls, no database writes, no worker subprocess execution
- No new schema fields, no persistence changes, no UI
- **Production code: exactly one function in `rendering.py`; no classes, no constants beyond what exists**
- **Test code: new test file `services/worker/tests/test_project_segments.py` for behavioral validation**

### Excluded:

- FFmpeg `subtitles` filter integration (Slice 1 territory)
- AI-powered auto-caption generation
- Configurable focal point or positioning UI for captions
- Multi-language caption support
- Caption styling (font, size, color)
- Transcript segment auto-generation
- Frontend UI for caption editing or preview
- Laravel RenderMediaClip job changes
- DerivedAsset persistence or schema changes
- ProcessMediaAsset automatic render integration
- M5 ranking or recommendation changes
- Smart crop or focal point configuration
- Any new JSON schema fields or contract changes
- General queue redesign, unrelated refactors, governance changes

## Algorithm: `project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)`

### Input:

- `segments`: `list[dict]` where each dict has `{start_ms: int, end_ms: int, text: str}`
  - Timestamps are absolute source-media milliseconds
  - `start_ms < end_ms` always (validated upstream in MediaTranscript)
- `clip_start_ms`: `int` — the selected candidate's start bound in source media coordinates
- `clip_end_ms`: `int` — the selected candidate's end bound in source media coordinates

### Output:

- `list[dict]` where each dict has `{local_start_ms: int, local_end_ms: int, text: str}`
  - Timestamps are relative to clip local time: [0, clip_duration)
  - Segments entirely outside [clip_start_ms, clip_end_ms) are excluded
  - Segments partially overlapping are clamped to clip bounds
  - Empty input list → empty output list

### Algorithm steps (per segment):

1. Compute `local_start = segment.start_ms - clip_start_ms`
2. Compute `local_end = segment.end_ms - clip_start_ms`
3. Clamp `local_start = max(0, local_start)`
4. Clamp `local_end = min(clip_end_ms - clip_start_ms, local_end)`
5. If `local_end <= local_start` after clamping, discard segment (entirely outside)
6. Append `{local_start_ms: local_start, local_end_ms: local_end, text: segment.text}` to result

### Deterministic policies:

- No randomness, no model dependence, no external API calls
- Pure function: same inputs → same outputs always
- No state, no side effects, no logging of segment contents
- Integer arithmetic only — no floating-point in timestamp computation

### Edge cases handled:

- Empty segments list → empty result
- Segment completely before clip: filtered out
- Segment completely after clip: filtered out
- Segment starting before clip start: local_start clamped to 0 
- Segment ending after clip end: local_end clamped to clip_duration
- Segment exactly at clip bounds: included with zero-length local extent (filtered by local_end <= local_start check)
- Segments with empty text string: included (text preservation)

## Authoritative inputs and readiness

1. **MediaTranscript.segments** must be available from the transcript model (already persisted with {start_ms, end_ms, text} structure)
2. **Selected candidate bounds** [start_ms, end_ms) come from M5 recommendation authority — re-read from database per RenderMediaClip job pattern
3. **clip_start_ms** and **clip_end_ms** are integers from the validated render contract — same source as M6.1 candidate bounds
4. No new configuration fields introduced — same validation as M6.1
5. Function is pure and can be unit-tested in isolation without FFmpeg, database, or worker subprocess

### Deterministic algorithm: `project_segments_to_clip_local` v1.0.0

Same algorithmic policies as described above. No new configurable fields.

## Persistence, validation, and ownership

- No new `DerivedAsset` columns required. The projected segments are computed on-the-fly per render request and are NOT persisted.
- The function is a pure Python transformation validated by the Python test suite — no PHP/Laravel runtime validation in this slice.
- The projected segments are transient per render invocation; they do not affect any persisted state.
- No new ownership field or bypass is introduced.

## Observable acceptance criteria

1. **Projection correctness**: Given candidate [10000, 15000) and transcript segment [11200, 12600) → local [1200, 2600)
2. **Filter-out boundary**: Segment [5000, 6000) with clip [10000, 15000) → excluded from result
3. **Filter-out boundary**: Segment [20000, 21000) with clip [10000, 15000) → excluded from result
4. **Clamp boundary**: Segment [9000, 11000) with clip [10000, 15000) → local [0, 1000) (clamped start)
5. **Clamp boundary**: Segment [14000, 20000) with clip [10000, 15000) → local [4000, 5000) (clamped end, clip_duration=5000)
6. **Empty input → empty output**: [] → []
7. **Pure function**: No FFmpeg invocation, no DB write, no subprocess, no side effects observable
8. **Integration readiness**: Projected segments, when fed into FFmpeg subtitles filter (Slice 1), produce captions aligned to rendered clip's local timeline
9. **No production AI smart-crop, multi-aspect, configurable focal point, multi-clip, multi-aspect, review UI, publishing, frontend/API addition, credentials, network (beyond S3), model download, or unsafe logging is introduced**
10. **Authentic behavior-assertion RED precedes implementation, all new tests pass without weakening/skips, full regression baseline is preserved, and independent Tester approves running behavior**

## Security and UX implications

- Rendered clips are private owner-scoped application data
- No new authentication surface or secret provisioning required
- FFmpeg runs as subprocess with no elevated privileges; input is local file path or Flysystem-readable caption file
- Projected segments are pure data transformation — no security boundary concerns
- No new UI or render API; existing upload/list/delete/auth/project workflows unchanged
- Tester still exercises the running application and inspects console/network/API behavior
- Do not present rendered clips to users until M7 (review experience)

## Dependencies and human-gated operations

### Hard dependencies (must exist before SPEC_READY)

- Python 3.12 available in worker environment (already present)
- `services/worker/aiclip_worker/rendering.py` must exist and be importable
- No new external Python dependencies required — stdlib only (`typing`, dataclasses patterns already used)
- `services/worker/tests/` directory must exist for test placement

### Human-gated operations (blocking SPEC_READY → implementation)

- Operator confirms `project_segments_to_clip_local()` is a pure function with no FFmpeg, no DB, no subprocess
- Operator confirms existing M6.1 test suites pass without modification after adding the new function
- Operator confirms the function handles all edge cases listed in the algorithm specification
- Operator confirms that projected segments integrate correctly with Slice 1 FFmpeg subtitles filter (to be verified after both slices complete)

These are explicit gates in `plan.md`, not claimed available or executed. If a required environment cannot be supplied, completion remains blocked rather than silently changing acceptance.