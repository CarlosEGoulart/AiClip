# Implementation Plan: Issue #79 — M6.2 Caption Projection Slice 2

## Authority

This plan derives exclusively from `specs/079-m2-caption-projection/spec.md`. Planner owns this file. Builder executes; Tester validates; Orchestrator coordinates.

## Stage A — RED: Write and run behavioral tests (expect failure)

### Behavior

- Create `services/worker/tests/test_project_segments.py` with tests for `project_segments_to_clip_local()` per `test-plan.md`
- Tests must fail because the function does not yet exist
- No implementation code written yet — RED must be authentic

### Targeted RED command

```bash
cd services/worker && python -m pytest tests/test_project_segments.py -v
```

### Stop condition

- `test_project_segments.py` exists and contains tests for all acceptance criteria (TP-01 through TP-08)
- Running the test file fails with `NameError`/`AttributeError` / `ImportError` because `project_segments_to_clip_local` is not defined
- No implementation has been added to mask the failure
- Existing M6.1 tests still pass (regression baseline confirmed before implementation)

---

## Stage B — GREEN: Implement only the projection function

### Behavior

- Add `project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)` to `services/worker/aiclip_worker/rendering.py`
- Pure Python function — no FFmpeg, no database, no subprocess, no side effects
- Implement exactly the algorithm specified in `spec.md`
- No other production files modified

### Targeted GREEN commands

```bash
# Run new projection tests (expect pass)
cd services/worker && python -m pytest tests/test_project_segments.py -v

# Run M6.1 regression sentinels (must pass unchanged)
cd services/worker && python -m pytest tests/test_render_clip_contract_validation.py tests/test_cli_render_clip.py tests/test_render_clip_integration.py tests/test_render_clip_integration_duration.py -v

# Run M5 rank_clips sentinels (must pass)
cd services/worker && python -m pytest tests/test_rank_clips.py tests/test_contract_rank_clips.py -v
```

### Stop condition

- `project_segments_to_clip_local()` function added to `rendering.py`
- All tests in `test_project_segments.py` pass (TP-01 through TP-08)
- All existing M6.1 test suites pass without modification (regression gate)
- All M5 `rank_clips` sentinel tests pass without modification (regression gate)
- No other test suites required for this slice

---

## Stage C — REFACTOR (if needed)

### Behavior

- Improve implementation quality without altering behavior
- Re-run Stage B GREEN commands to confirm all tests remain green
- No scope expansion

### Stop condition

- All Stage B tests still pass
- Code is clean, readable, and follows existing patterns in `rendering.py`
- No out-of-scope changes introduced

---

## Notes on scope boundaries

**This slice does NOT include:**

- Laravel/PHP test execution
- FFmpeg invocation or integration tests
- DerivedAsset persistence tests
- RenderMediaClip job integration
- ProcessMediaAsset integration tests
- Frontend/E2E/Playwright tests
- Commit, push, PR creation, CI monitoring, merge, or issue closure

These lifecycle operations belong to the Orchestrator and later slices. Builder stops after Stage C with a working, tested function.