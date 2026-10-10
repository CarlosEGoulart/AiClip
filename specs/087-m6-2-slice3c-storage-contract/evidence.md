# Evidence: Issue #87 — SRT Storage & Worker Contract

## TDD: STORAGE_CONTRACT_CAPTION

### RED Phase

- [x] **StorageKeyBuilder tests**: Extended `tests/Unit/StorageKeyBuilderTest.php` — all 9 TP-04 tests failed (method not implemented)
- [x] **MediaProcessingContract tests**: Extended `tests/Unit/MediaProcessingContractTest.php` — all 6 TP-05 tests failed (caption_file not in contract output)

### GREEN Phase

- [x] **StorageKeyBuilder::captionFile()**: Implemented in `app/Services/StorageKeyBuilder.php` — all 9 TP-04 tests pass
- [x] **MediaProcessingContract::renderClipRequest()**: Extended in `app/Contracts/MediaProcessingContract.php` — all 6 TP-05 tests pass
- [x] **Worker schema**: Updated `services/worker/contracts/media_processing_v1.json` — `caption_file` added to `render_clip_request`

### REFACTOR Phase

- [x] Code style: `cd apps/api && vendor/bin/pint --dirty --format agent` — clean
- [x] No behavior-changing refactors needed

## Test Results

### Unit Tests (TP-04)

| Test Class | Tests | Pass | Fail | Notes |
|------------|-------|------|------|-------|
| StorageKeyBuilderTest (caption) | 9 | 9 | 0 | PASS |

### Unit Tests (TP-05)

| Test Class | Tests | Pass | Fail | Notes |
|------------|-------|------|------|-------|
| MediaProcessingContractTest (caption integration) | 6 | 6 | 0 | PASS |

### Regression Tests

| Test Suite | Result | Notes |
|------------|--------|-------|
| Existing StorageKeyBuilder tests | PASS | 3 tests pass — no regression |
| Existing MediaProcessingContract tests | PASS | 15 tests pass — no regression |
| All Laravel test suite | PASS | 986 tests pass |

## Scope Verification

### In Scope (Implemented)
- [x] `app/Services/StorageKeyBuilder.php` — `captionFile()` method added
- [x] `app/Contracts/MediaProcessingContract.php` — `renderClipRequest()` extended with `caption_file`
- [x] `services/worker/contracts/media_processing_v1.json` — `caption_file` added to `render_clip_request`
- [x] `apps/api/tests/Unit/StorageKeyBuilderTest.php` — 9 TP-04 test cases added
- [x] `apps/api/tests/Unit/MediaProcessingContractTest.php` — 6 TP-05 test cases added

### Out of Scope (Not Modified)
- [ ] SRT generation (Slice 3B)
- [ ] Segment projection (Slice 3A)
- [ ] Worker rendering logic (`services/worker/aiclip_worker/rendering.py`)
- [ ] Database schema / migrations
- [ ] API endpoints / controllers
- [ ] Frontend / UI

## Tester Decision

Decision: APPROVE

### Justification

All 15 new acceptance criteria tests pass independently. Implementation follows existing patterns (StorageKeyBuilder mirrors renderClip, MediaProcessingContract maintains backward compatibility with optional params). Worker schema addition is optional (string | null). Zero regression in 986 existing tests. Code style clean via Pint.

## CI

Status: PASS

GitHub Actions workflow run: https://github.com/CarlosEGoulart/AiClip/actions/runs/38014031339

Required checks:
- [x] `cd apps/api && php artisan test --filter=StorageKeyBuilderTest` — PASS (12 tests)
- [x] `cd apps/api && php artisan test --filter=MediaProcessingContractTest` — PASS (21 tests)
- [x] `cd apps/api && vendor/bin/pint --dirty --format agent` — PASS
- [x] Full Laravel test suite — PASS (986 tests)

## Lifecycle State

`NO_ACTIVE_ISSUE` → `ISSUE_CREATED` → `BRANCH_CREATED` → `SPEC_READY` → `RED_VERIFIED` → `GREEN_VERIFIED` → `TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

Current: `TESTER_APPROVED`

## Final Lifecycle State

The issue was merged and closed following Tester approval.

- **PR**: #88
- **Merge Commit**: 743cb85
- **Merge Date**: 2026-10-10
- **GitHub PR URL**: https://github.com/CarlosEGoulart/AiClip/pull/88
- **GitHub Issue URL**: https://github.com/CarlosEGoulart/AiClip/issues/87

### Lifecycle Transition

`TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

### Historical Preservation

The original `TESTER_APPROVED` decision is preserved above. This section records the final GitHub lifecycle completion without altering historical evidence.