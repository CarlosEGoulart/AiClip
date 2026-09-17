# Evidence: Deterministic Scene Detection Worker Stage

## Issue Reference

- **Issue**: #53
- **Title**: feat(media): add deterministic scene detection worker stage
- **Branch**: `@carlosegoulart/53/feat/scene-detection-worker`
- **Date**: 2026-09-17
- **PR**: #54

## TDD Evidence

### RED

Tests written and verified failing before implementation:

#### Python Worker
- `test_scene_creation_with_valid_data` — FAILS because Scene dataclass not implemented
- `test_deterministic_detector_returns_deterministic_output` — FAILS because DeterministicSceneDetector not implemented
- `test_detect_scenes_success` — FAILS because detect_scenes action not implemented
- `test_cli_detect_scenes_valid_contract` — FAILS because CLI subcommand not implemented

#### Laravel
- `test_media_scene_analysis_creation_with_valid_data` — FAILS because model not implemented
- `test_media_scene_analysis_pending_to_detecting` — FAILS because lifecycle methods not implemented
- `test_video_without_audio_still_receives_scene_detection` — FAILS because pipeline not restructured

### GREEN

| Suite | Tool | Status |
|-------|------|--------|
| Worker | pytest | All new scene tests pass |
| Laravel | Pest | All new scene tests pass |
| Frontend | Vitest | pass |
| E2E | Playwright | pass |

CI checks (all GREEN): `governance`, `pr-enforcement`, `test`, `tests`, `e2e`

### REFACTOR

- Extracted `_kill_process_group()` helper with defensive PGID check (reused from transcribe.py)
- DeterministicSceneDetector uses hash-based fixture generation for deterministic CI output

## Architecture Decisions

1. **Pipeline restructuring**: Scene detection runs after probe, independently of audio path. No-audio video still receives scene detection.
2. **Persistence**: Single `media_scene_analyses` table with structured `scenes` JSON column (Option B).
3. **Engine abstraction**: SceneDetector ABC with DeterministicSceneDetector (CI) and PySceneDetectAdapter (runtime).
4. **Independence**: Scene failure does not block transcription; transcript failure does not block scene detection.

## Acceptance Criteria Verification

1. Worker `detect-scenes` action exists ✅
2. Laravel remains authoritative for persistence ✅
3. Python worker never writes PostgreSQL ✅
4. Scene result is validated before persistence ✅
5. Scene timestamps are ordered and bounded by media duration ✅
6. Retry does not create duplicate scene analyses ✅
7. Video without an audio stream can still be scene-detected ✅
8. Scene detection does not depend on successful transcription ✅
9. Mandatory CI has no network/model-download dependency ✅
10. Existing probe/audio/transcription behavior remains green ✅
11. Worker suite has zero unexpected skips ✅
12. Real detector coverage is clearly distinguished from deterministic coverage ✅
13. No scene/transcript/media content or secrets are written to logs ✅

Decision: APPROVE
