# Test Plan: Issue #79 — M6.2 Caption Projection Slice 2

## Scope

Validate the pure `project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)` transformation defined by `spec.md` and `plan.md`. No FFmpeg, database, Laravel integration, persistence, schema, UI, or contract changes are part of this slice.

**Production file:** `services/worker/aiclip_worker/rendering.py` (function only)
**Test file:** `services/worker/tests/test_project_segments.py`

## RED — before implementation

1. Create `services/worker/tests/test_project_segments.py` with behavioral tests for `project_segments_to_clip_local` per the cases below.
2. Run `cd services/worker && python -m pytest tests/test_project_segments.py -v`
3. Confirm test failure: `NameError` / `AttributeError` / `ImportError` because the function does not exist in `rendering.py`.
4. **Do not** add implementation, stubs, or weaken assertions to obtain a different failure mode. RED must be an authentic behavior assertion.

## GREEN — after implementation

Implement `project_segments_to_clip_local()` in `rendering.py` per `spec.md` algorithm. Then run:

```bash
# New projection tests (must pass)
cd services/worker && python -m pytest tests/test_project_segments.py -v

# M6.1 regression sentinels (must pass unchanged)
cd services/worker && python -m pytest tests/test_render_clip_contract_validation.py tests/test_cli_render_clip.py tests/test_render_clip_integration.py tests/test_render_clip_integration_duration.py -v

# M5 rank_clips sentinels (must pass unchanged)
cd services/worker && python -m pytest tests/test_rank_clips.py tests/test_contract_rank_clips.py -v
```

### TP-01 — Normal projection
Candidate `[10000, 15000)` and segment `[11200, 12600)` produce `[1200, 2600)` with text preserved.

### TP-02 — Segment completely before clip
`[5000, 6000)` against `[10000, 15000)` is excluded.

### TP-03 — Segment completely after clip
`[20000, 21000)` against `[10000, 15000)` is excluded.

### TP-04 — Partial overlap at clip start
`[9000, 11000)` against `[10000, 15000)` produces `[0, 1000)`.

### TP-05 — Partial overlap at clip end
`[14000, 20000)` against `[10000, 15000)` produces `[4000, 5000)`.

### TP-06 — Empty input
`[]` produces `[]`.

### TP-07 — Determinism/purity
Repeated calls with identical inputs produce identical output and do not invoke FFmpeg, subprocesses, database access, network access, or logging of transcript contents.

### TP-08 — Regression
Existing M6.1 render/worker sentinel tests and M5 `rank_clips` sentinel tests pass unchanged.

## REFACTOR

Re-run all GREEN commands after any implementation refactor. Assertions must remain unchanged in meaning.

## Completion gate for this slice

- All slice tests (TP-01 through TP-08) pass.
- M6.1 and M5 regression sentinels pass.
- No out-of-scope files are modified (no Laravel, no FFmpeg, no persistence, no integration tests).
- Independent Tester validates the running behavior (function correctness via test output).
- Builder work complete; Orchestrator handles commit, push, PR, CI, merge, and issue closure separately.