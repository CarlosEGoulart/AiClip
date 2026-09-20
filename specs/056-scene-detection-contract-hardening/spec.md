# Specification: Scene Detection Contract Hardening

## Issue Reference

- **Issue**: #56
- **Title**: fix(media): harden scene detection contract and verification
- **Branch**: `@carlosegoulart/56/fix/scene-detection-contract-hardening`
- **PR**: To be created
- **Milestone**: M4 Video Understanding (maintenance slice)

## Goal

Post-merge verification of PR #55 (merged as commit 2104f14) revealed contract and verification gaps that must be corrected before the scene detection worker stage is considered production-ready. This maintenance issue addresses 12 specific blockers without implementing any new product features.

## Blocker Summary

### Blocker 1 — Worker JSON Schema Does Not Require Duration
`media_processing_v1.json` defines `media.duration_ms` but `media` is optional and the schema has no conditional requiring `media` for `detect_scenes` action. A `detect_scenes` contract without `media.duration_ms` currently validates.

### Blocker 2 — Empty Scenes Bypass Duration Validation
`MediaSceneAnalysis::validateScenes()` has an early return for empty scenes that skips duration validation. `validateScenes([], 0)` currently succeeds but must throw.

### Blocker 3 — Existing Test Codifies Wrong Behavior
Test `test_validate_scenes_accepts_single_scene_at_zero_duration` asserts `validateScenes([], 0)` is valid. This contradicts the spec and must be replaced with a correct failure test.

### Blocker 4 — Four Risky Laravel Tests
Post-merge CI reports 4 risky (no-assertion) tests. These must be fixed with meaningful postconditions.

### Blocker 5 — PHP Contract Validation Not Enforced at Boundary
`ProcessMediaAction::detectScenes()` serializes the contract and invokes the subprocess without calling `$contract->validate()` first. Invalid contracts reach worker invocation.

### Blocker 6 — Missing scene_detection Dependency Error Path
`PySceneDetectAdapter` lazy-imports `scenedetect` inside `detect()`. Missing dependency surfaces as raw `ImportError` instead of actionable installation guidance.

### Blocker 7 — Preserve Real Adapter Coverage
Current CI runs real PySceneDetect integration tests. These must remain mandatory Backend CI coverage.

### Blocker 8 — Evidence #53 Is Historically Inconsistent
`specs/053-scene-detection-worker/evidence.md` contains conflicting material (wrong test counts, stale decision status, etc.). Must be corrected factually.

### Blocker 9 — Closed Issue #53 Metadata Is Stale
Issue #53 body still references abandoned normalized `MediaScene` model and `media_scenes` table. Must be updated to reflect Option B (single `media_scene_analyses` row with JSON `scenes`).

### Blocker 10 — Merged PR #55 Body Is Stale
PR #55 body has historical totals (179 worker, ~279 Laravel) and stale dependency wording. Must be corrected to actual post-merge validation results.

### Blocker 11 — Project-State Documentation
`docs/project-state.md` has stale terminology (`pyscenedetect` vs `scenedetect[opencv-headless]`) and obsolete CI description.

### Blocker 12 — Reconcile SDD Text
`specs/053-scene-detection-worker/spec.md`, `plan.md`, `test-plan.md` contain stale statements about real integration being optional and CI using deterministic engine exclusively.

## Detailed Scope

### Included

1. **Worker JSON Schema Conditional**
   - Add Draft-07 `allOf`/`if`/`then` conditional to `media_processing_v1.json`
   - When `action == "detect_scenes"`, require `media` object with required `duration_ms` (integer, minimum 1)
   - Preserve existing validation for other actions (probe, extract_audio, transcribe)
   - `required: ["action"]` inside `if` is necessary to avoid vacuous matching

2. **Laravel Duration Validation Before Empty-Scenes Return**
   - Move `durationMs <= 0` check to the TOP of `validateScenes()`, before any early return
   - `validateScenes([], 0)` throws `InvalidArgumentException`
   - `validateScenes([], -1)` throws
   - `validateScenes([], 1)` succeeds
   - `markCompleted(..., [], 0)` throws
   - Failed completion does not persist `COMPLETED` status
   - `markCompleted(..., [], positiveDuration)` succeeds when lifecycle is valid

3. **Fix Risky Laravel Tests**
   - Replace `test_validate_scenes_accepts_single_scene_at_zero_duration` with correct failure test
   - Fix all 4 risky tests by adding meaningful assertions (not `$this->assertTrue(true)`)
   - Examples: assert persisted scenes equal expected, assert boundary scene end == duration, assert COMPLETED status persisted

4. **PHP Contract Preflight in ProcessMediaAction**
   - `ProcessMediaAction::detectScenes()` must call `$contract->validate()` BEFORE creating process
   - Throw `ProcessMediaException` on invalid contract
   - Add tests proving: duration null/0/negative → exception; valid positive → process created; invalid contract → `createProcess()` NEVER called

5. **Actionable Missing Dependency Error**
   - In `PySceneDetectAdapter.detect()`, wrap lazy import in try/except
   - Raise `ImportError` with actionable message: `"scenedetect is required for PySceneDetectAdapter; install the worker scene_detection extra with: pip install -e \".[scene_detection]\""`
   - Preserve lazy import (import only at detect-time)
   - Add unit test mocking ImportError

6. **Historical Evidence Correction**
   - Update `specs/053-scene-detection-worker/evidence.md` with factual post-merge data
   - Add "Post-Merge Maintenance" section referencing this issue
   - Record actual CI results: Worker 196 passed, Laravel 305/1735/4 risky, Frontend 187, E2E 75, governance 170

7. **Issue #53 Metadata Correction**
   - Update Issue #53 body: add architecture note about Option B superseding normalized model
   - Update checklist items to reflect actual implementation

8. **PR #55 Body Correction**
   - Update PR #55 body with actual post-merge validation totals
   - Correct package terminology to `scenedetect[opencv-headless]`
   - Add post-merge maintenance note

9. **Project-State Documentation**
   - Update `docs/project-state.md` with correct terminology and CI description
   - Preserve exactly six H1 headings

10. **SDD Text Reconciliation**
    - Update `specs/053-scene-detection-worker/spec.md`, `plan.md`, `test-plan.md`
    - Make duration contract unambiguous: `detect_scenes` requires `media.duration_ms` integer >= 1
    - Empty scenes valid ONLY with valid positive authoritative duration
    - Real integration is mandatory Backend CI coverage

### NOT Included

- Clip ranking/analysis
- New product features
- Frontend feature work
- Governance/control-plane modifications
- MediaScene model or media_scenes table (Option B is authoritative)
- Scene semantic analysis
- Rendering

## Architecture Invariants

1. JSON Schema conditional uses Draft-07 `if`/`then` with `required: ["action"]` in `if`
2. Laravel validates duration BEFORE empty-scenes early return
3. PHP contract validation is a HARD boundary before subprocess spawn
4. Worker JSON schema validation remains independent (both boundaries enforce)
5. Real PySceneDetect integration tests remain mandatory CI coverage
6. No control-plane modifications
7. Historical corrections are factual, not rewritten deceptively

## Acceptance Criteria

- [ ] JSON Schema conditional requires `media.duration_ms` for `detect_scenes`
- [ ] Laravel validates duration before empty-scenes early return
- [ ] 0 risky Laravel tests
- [ ] PHP contract preflight rejects invalid contracts before subprocess
- [ ] Missing dependency produces actionable error with install instructions
- [ ] Historical evidence/metadata corrected factually
- [ ] All CI checks green with 0 risky
- [ ] Worker tests: contract regression tests executed, missing dependency test executed, 3 real PySceneDetect integration tests executed
- [ ] Laravel tests: exact total, exact assertions, 0 risky, 0 failed
- [ ] MinIO real integration tests green
- [ ] Frontend regression suite green
- [ ] E2E regression suite green
- [ ] Governance suite green

## Out of Scope

- Clip ranking/analysis
- New product features
- Frontend feature work
- Governance/control-plane modifications
- MediaScene model or media_scenes table
- Scene semantic analysis
- Rendering

## Dependencies

- Existing scene detection implementation (PR #55)
- Worker contract validation infrastructure
- Laravel MediaSceneAnalysis model and ProcessMediaAction service
- PySceneDetectAdapter with lazy import

## Risks

1. **Schema conditional complexity**: Draft-07 `if`/`then` must be carefully constructed to avoid vacuous matching
2. **Risky test fixes**: Must add meaningful assertions, not dummy assertions
3. **Historical accuracy**: Corrections must be factual, not rewrite history to hide that PR #55 had 4 risky tests
4. **Real integration preservation**: Must not accidentally disable or skip the 3 real PySceneDetect integration tests

## Success Criteria

- All 12 blockers resolved
- CI passes with 0 risky tests
- Real PySceneDetect integration tests still execute
- Historical documents are factually accurate
- No new features implemented