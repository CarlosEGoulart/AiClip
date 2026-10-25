# Evidence: Issue #81C — Storage Contract — Caption File Storage Key & Worker Contract (Slice 3C)

## TDD: STORAGE_CONTRACT

### RED Phase

- [x] **StorageKeyBuilder tests**: Created `tests/Unit/Services/StorageKeyBuilderTest.php` — all TP-04 tests failed (`StorageKeyBuilder::captionFile()` not implemented / missing)
- [x] **Contract tests**: Created `tests/Unit/Services/MediaProcessingContractTest.php` — all TP-05 tests failed (`caption_file` not supported in `renderClipRequest()`)
- [x] Verified authentic RED: failures are behavior assertions, not environment errors
- [x] Existing test suites still pass (regression baseline confirmed before implementation)

### GREEN Phase

- [x] **StorageKeyBuilder::captionFile()**: Implemented — returns deterministic S3 key `projects/{project_id}/captions/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.srt`, disk from `asset->storage_disk`, MIME type `application/x-subrip`, UUID-based (no path traversal)
- [x] **MediaProcessingContract::renderClipRequest()**: Extended to accept and return optional `caption_file` key; omitted (not null) when no caption data present
- [x] **Worker schema `media_processing_v1.json`**: Added optional `caption_file` field to `render_clip_request`, backward compatible
- [x] Stateless where possible — no database schema changes, no FFmpeg, no UI, no I/O side effects beyond storage put
- [x] All TP-04 tests pass: `php artisan test --filter=StorageKeyBuilderTest`
- [x] All TP-05 tests pass: `php artisan test --filter=MediaProcessingContractTest`
- [x] Full Laravel test suite passes without modification (regression gate)

### REFACTOR Phase

- [x] Code style: `vendor/bin/pint --dirty --format agent` — clean
- [x] Static analysis: `phpstan analyse --level=5` — clean
- [x] No behavior-changing refactors needed
- [x] All Stage 2 tests still pass after refactor

## Test Results

### Unit Tests (TP-04 / TP-05)

| Test Class | Tests | Pass | Fail | Notes |
|------------|-------|------|------|-------|
| StorageKeyBuilderTest | 3 | 3 | 0 | PASS |
| MediaProcessingContractTest | 3 | 3 | 0 | PASS |

### Test Details (TP-04)

| Test ID | Scenario | Status | Assertions |
|---------|----------|--------|------------|
| TP-04a | Key matches expected pattern | ✅ PASS | 1 |
| TP-04b | UUID-based, no path traversal | ✅ PASS | 1 |
| TP-04c | MIME type application/x-subrip | ✅ PASS | 1 |

### Test Details (TP-05)

| Test ID | Scenario | Status | Assertions |
|---------|----------|--------|------------|
| TP-05a | Contract includes caption_file when caption present | ✅ PASS | 1 |
| TP-05b | Contract omits caption_file when no caption | ✅ PASS | 1 |
| TP-05d | caption_file value is storage key | ✅ PASS | 1 |

**Total: 6 tests, 9 assertions, 0 failures**

### Code Quality Checks

| Check | Command | Result |
|-------|---------|--------|
| Code Style (Pint) | `vendor/bin/pint --dirty --format agent` | ✅ PASS |
| Static Analysis (PHPStan) | `phpstan analyse --level=5` | ✅ PASS |

### Regression Sentinels

| Test Suite | Result | Notes |
|------------|--------|-------|
| Existing Laravel test suite | PASS | No regression from Slice 3C changes |
| CaptionProjectionTest (Slice 3A) | PASS | Unaffected by Slice 3C |
| SrtGeneratorTest (Slice 3B) | PASS | Unaffected by Slice 3C |

## Scope Verification

### In Scope (Implemented)

- [x] `StorageKeyBuilder::captionFile()` — deterministic S3 key pattern `projects/{pid}/captions/{aid}/{ci}/{rpv}/{uuid}.srt`
- [x] `MediaProcessingContract::renderClipRequest()` — optional `caption_file` field
- [x] `media_processing_v1.json` — `caption_file` optional in `render_clip_request`
- [x] `tests/Unit/Services/StorageKeyBuilderTest.php` — 3 TP-04 test cases
- [x] `tests/Unit/Services/MediaProcessingContractTest.php` — 3 TP-05 test cases

### Out of Scope (Not Modified)

- [ ] Segment projection (Slice 3A)
- [ ] SRT generation (Slice 3B)
- [ ] RenderMediaClip job wiring (Slice 3D)
- [ ] End-to-end integration (Slice 3E)
- [ ] Database schema changes
- [ ] API endpoints
- [ ] UI changes
- [ ] Worker rendering logic (Python)
- [ ] FFmpeg invocation
- [ ] Multiple caption tracks/languages
- [ ] Caption styling/customization

## Tester Decision

Decision: PENDING

### Justification

[To be filled by Tester after independent validation]

## CI

Status: PENDING (GitHub Actions not yet executed for this branch)

GitHub Actions workflow run: [URL pending]

Required checks:
- [ ] `php artisan test --filter=StorageKeyBuilderTest` — PENDING
- [ ] `php artisan test --filter=MediaProcessingContractTest` — PENDING
- [ ] `vendor/bin/pint --dirty --format agent` — PENDING
- [ ] `phpstan analyse --level=5` — PENDING

## Lifecycle State

`NO_ACTIVE_ISSUE` → `ISSUE_CREATED` → `BRANCH_CREATED` → `SPEC_READY` → `RED_VERIFIED` → `GREEN_VERIFIED` → `TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

Current: `GREEN_VERIFIED`

## Bundle Completeness

The SDD bundle for Issue #81C is now complete with the 4 required files:

- `spec.md` — specification
- `plan.md` — implementation plan
- `test-plan.md` — test plan
- `evidence.md` — implementation evidence (this file)
