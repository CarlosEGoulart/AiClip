# Evidence: Issue #56 — Scene Detection Contract Hardening

## TDD: SCENE_DETECTION_CONTRACT

### RED Phase

- [x] **Worker JSON Schema conditional**: Tests written and verified failing before implementation:
  - `test_contract_detect_scenes_missing_media_invalid` — FAILS (contract without media validated)
  - `test_contract_detect_scenes_media_empty_object_invalid` — FAILS (empty media object validated)
  - `test_contract_detect_scenes_missing_duration_ms_invalid` — FAILS (media without duration_ms validated)
  - Command: `cd services/worker && python -m pytest tests/test_contract_detect_scenes.py -v`
- [x] **Laravel duration validation**: Tests written and verified failing before implementation:
  - `test_validate_scenes_rejects_zero_duration_with_empty_scenes` — FAILS (validateScenes([], 0) succeeded)
  - `test_validate_scenes_rejects_negative_duration_with_empty_scenes` — FAILS (validateScenes([], -1) succeeded)
  - Command: `cd apps/api && php artisan test --filter="test_validate_scenes_rejects_zero_duration_with_empty_scenes|test_validate_scenes_rejects_negative_duration_with_empty_scenes"`
- [x] **PHP contract preflight**: Tests written and verified failing before implementation:
  - `test_throws_ProcessMediaException_when_durationMs_is_null_for_detect_scenes` — FAILS
  - `test_throws_ProcessMediaException_when_durationMs_is_0_for_detect_scenes` — FAILS
  - `test_throws_ProcessMediaException_when_durationMs_is_negative_for_detect_scenes` — FAILS
  - Command: `cd apps/api && php artisan test --filter="ProcessMediaActionTest"`
- [x] **Missing dependency error**: Tests written and verified failing before implementation:
  - `test_pyscenedetect_adapter_missing_dependency_raises_actionable_error` — FAILS (raw ImportError)
  - Command: `cd services/worker && python -m pytest tests/test_missing_dependency.py -v`
- [x] Existing test suites still pass (regression baseline confirmed before implementation)

### GREEN Phase

- [x] **Worker JSON Schema**: Added Draft-07 `allOf`/`if`/`then` conditional to `media_processing_v1.json` requiring `media.duration_ms` (integer >= 1) for `detect_scenes`
- [x] All 16 contract tests pass
- [x] Other actions (`probe`, `extract_audio`, `transcribe`) unaffected
- [x] **Laravel duration validation**: Moved `durationMs <= 0` check to top of `validateScenes()`, before empty-scenes early return
- [x] All 4 duration tests pass; `validateScenes([], 0)` and `validateScenes([], -1)` throw `InvalidArgumentException`
- [x] **Risky tests fixed**: 4 risky tests replaced with meaningful postconditions; 0 risky tests in full Laravel suite
- [x] **PHP contract preflight**: `ProcessMediaAction::detectScenes()` now calls `$contract->validate()` before subprocess
- [x] All 10 ProcessMediaActionTest tests pass
- [x] **Missing dependency error**: `PySceneDetectAdapter` raises actionable `ImportError` with `"pip install -e \".[scene_detection]\""` message
- [x] Real PySceneDetect integration tests executed and PASSED (3 tests)

### REFACTOR Phase

- [x] Missing-dependency tests rewritten using `monkeypatch` on `builtins.__import__` — no subprocess, no skipif
- [x] Removed hard-coded Cloud Shell path from test files
- [x] Created `services/worker/.gitignore` to prevent `__pycache__`/pyc tracking
- [x] Replaced array copy-on-write pattern with `throw new \RuntimeException` guard for createProcess preflight
- [x] All tests remain green after refactor

## Test Results

### Unit Tests

| Suite | Tool | Collected | Passed | Failed | Risky | Skipped | Notes |
|-------|------|-----------|--------|--------|-------|---------|-------|
| Worker | pytest | 208 | 208 | 0 | 0 | 0 | Missing-dependency tests execute (not skipped) |
| Laravel | Pest | 316 | 316 | 0 | 0 | 0 | 1762 assertions, 0 risky |
| Frontend | Vitest | 187 | 187 | 0 | 0 | 0 | Unaffected |
| E2E | Playwright | 75 | 75 | 0 | 0 | 0 | Unaffected |
| Governance | custom | 170 | 170 | 0 | 0 | 0 | Unaffected |

### Real Integration Tests

| Test | Status |
|------|--------|
| `test_real_scenedetect_on_solid_color_cuts` | PASS |
| `test_real_scenedetect_detects_expected_boundaries` | PASS |
| `test_real_scenedetect_single_color_no_scenes` | PASS |

### Code Quality Checks

| Check | Command | Result |
|-------|---------|--------|
| Code Style (Pint) | `vendor/bin/pint --dirty --format agent` | PASS |

### Regression Sentinels

| Test Suite | Result | Notes |
|------------|--------|-------|
| Existing Laravel test suite | PASS | No regression from Issue #56 changes |
| SrtGeneratorTest | PASS | Unaffected |

## Scope Verification

### In Scope (Implemented)

- [x] `services/worker/contracts/media_processing_v1.json` — JSON Schema conditional requiring `media.duration_ms` for `detect_scenes`
- [x] `apps/api/app/Models/MediaSceneAnalysis.php` — Duration validation before empty-scenes return
- [x] `apps/api/tests/Feature/Models/MediaSceneAnalysisTest.php` — Fixed 4 risky tests with meaningful assertions
- [x] `apps/api/app/Services/ProcessMediaAction.php` — Contract preflight validation in `detectScenes()`
- [x] `services/worker/aiclip_worker/scene_detection_pyscenedetect.py` — Actionable missing dependency error

### Out of Scope (Not Modified)

- [ ] Issue #53 metadata (historical, handled separately)
- [ ] PR #55 body (historical, handled separately)
- [ ] docs/project-state.md (historical, handled separately)
- [ ] spec.md, plan.md, test-plan.md for #53 (historical, handled separately)
- [ ] Worker rendering logic
- [ ] Database schema
- [ ] API endpoints
- [ ] Frontend/UI
- [ ] Python worker changes beyond error message
- [ ] FFmpeg invocation
- [ ] Control-plane modifications
- [ ] Clip ranking scope

## Tester Decision

Decision: PENDING

### Justification

[To be filled by Tester after independent validation]

## CI

Status: PENDING (GitHub Actions not yet executed for this branch)

GitHub Actions workflow run: [URL pending]

Required checks:
- [ ] Worker pytest `tests/test_contract_detect_scenes.py` — PENDING
- [ ] Worker pytest `tests/test_missing_dependency.py` — PENDING
- [ ] Laravel `php artisan test --filter="ProcessMediaActionTest"` — PENDING
- [ ] Laravel `php artisan test --filter="MediaSceneAnalysisTest"` — PENDING
- [ ] `vendor/bin/pint --dirty --format agent` — PENDING

## Lifecycle State

`NO_ACTIVE_ISSUE` → `ISSUE_CREATED` → `BRANCH_CREATED` → `SPEC_READY` → `RED_VERIFIED` → `GREEN_VERIFIED` → `TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

Current: `GREEN_VERIFIED`

## Bundle Completeness

The SDD bundle for Issue #56 is now complete with the 4 required files:

- `spec.md` — specification
- `plan.md` — implementation plan
- `test-plan.md` — test plan
- `evidence.md` — implementation evidence (this file)
