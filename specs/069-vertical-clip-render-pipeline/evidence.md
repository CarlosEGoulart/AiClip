# Evidence: Issue #69 — Durable Baseline Vertical Clip Render Pipeline

## Test Files Created

### Laravel Tests (apps/api/tests/)

1. **tests/Unit/RenderProfileTest.php** - 20 tests for RenderProfile configuration, timeoutSeconds, lockWaitSeconds
2. **tests/Unit/RenderValidatorTest.php** - Tests for RenderValidator request(), result(), validateCompletion()
3. **tests/Feature/Models/DerivedAssetRenderedClipTest.php** - 10 tests for DerivedAsset render columns, status transitions, unique constraint, scope
4. **tests/Feature/Jobs/ProcessMediaAssetRenderTest.php** - 16 tests for full job execution with mocked worker, all readiness states (UPDATED for corrected design)
5. **tests/Feature/Jobs/ProcessMediaAssetRenderRealWorkerTest.php** - 5 tests for real worker integration (requires FFmpeg fixture) (UPDATED for corrected design)
6. **tests/Feature/Jobs/RenderMediaClipTest.php** - 14 tests for new dedicated RenderMediaClip job (NEW for corrected design)

### Python Worker Tests (services/worker/tests/)

1. **tests/test_render_clips_contract_validation.py** - Schema + runtime validation tests (TC-WCV-01 through TC-WCV-26)
2. **tests/test_ffmpeg_vertical_renderer.py** - Filter graph, candidate selection, output naming tests (TC-FGR-01 through TC-FGR-08, TC-CSL-01 through TC-CSL-06, TC-ONM-01 through TC-ONM-03, TC-WCF-01 through TC-WCF-05)
3. **tests/test_cli_render_clips.py** - CLI transport tests (TC-CLI-01 through TC-CLI-07)
4. **tests/test_render_configuration.py** - Configuration validation tests (TC-WCF-01 through TC-WCF-05)
5. **tests/test_render_clips_integration.py** - Real FFmpeg integration tests (TC-E2E-01 through TC-E2E-10)

## CORRECTED DESIGN IMPLEMENTATION (M6.1 - Per Updated Spec)

### Changes Made

**REMOVED from ProcessMediaAsset.php:**
1. ✅ **Entire M6.1 Clip Render Stage** (lines 1069-1237) - Automatic render stage removed
2. ✅ **`clipRenderResolved` variable** - Removed
3. ✅ **`clipRenderResolved` in asset completion condition** - Line 1242 updated to NOT include `clipRenderResolved`
4. ✅ **`selectedCandidateIndex = 0` hardcoded default** - Removed line "For M6.1, we need an explicit candidate_index - for now use semantic_rank 1 (index 0)"
5. ✅ **Automatic `runClipRenderClaim()` invocation** - Removed automatic invocation in ProcessMediaAsset
6. ✅ **Private methods**: `claimOrCreateRenderAttempt`, `renderOutcomeResolved`, `runClipRenderClaim` - All removed

**REMOVED from MediaAsset.php:**
7. ✅ **`getClipRenderResolvedAttribute()` accessor** - Removed

**ADDED (Corrected Design):**
1. ✅ **New Dedicated Job**: `RenderMediaClip` (`apps/api/app/Jobs/RenderMediaClip.php`)
   - Takes explicit `MediaAsset` ID, `MediaClipRecommendation` ID, and `candidate_index` as constructor args
   - Uses same idempotency key pattern
   - Dispatches via `ProcessMediaAction::renderClips()`
   - Same concurrency pattern (insert-or-ignore + lockForUpdate)
   - Same lifecycle (pending→rendering→completed/failed)
   - Same FFprobe validation
   - Same atomic claim transaction pattern
   - Can be dispatched by M7 or internal callers

2. ✅ **Exception Classes** for RenderMediaClip:
   - `InvalidCandidateIndexException`
   - `InvalidInputException`
   - `RenderAbortedException`
   - `RenderBusyException`
   - `RenderFailedException`
   - `RenderVersionConflictException`
   - `UpstreamRecommendationFailedException`
   - `UpstreamRecommendationMissingException`
   - `UpstreamRecommendationUnavailableException`

3. ✅ **Updated ProcessMediaAsset completion condition** (line 1071):
   ```php
   if ($sceneDetectionResolved && $audioPathResolved && $clipAnalysisResolved && $clipRecommendationResolved) {
       $asset->markCompleted();
   }
   ```

### RED Phase Results (Corrective Tests - New Behavior)

**New Test File: `RenderMediaClipTest.php`** - 14 tests for explicit render job:
- TC-RMJ-01: M5 completed/ranked, valid candidate_index → job completes, DerivedAsset created
- TC-RMJ-02: M5 completed/ranked, candidate_index out of bounds → job fails
- TC-RMJ-03: M5 completed/ranked, candidate has null semantic_score → job fails
- TC-RMJ-04: M5 pending/ranking/not_ready → throws UpstreamRecommendationUnavailableException
- TC-RMJ-05: M5 failed → throws UpstreamRecommendationFailedException
- TC-RMJ-06: M5 unavailable → throws UpstreamRecommendationUnavailableException
- TC-RMJ-07: M5 missing after resolved → throws UpstreamRecommendationMissingException
- TC-RMJ-08: Existing completed DerivedAsset (same candidate+profile) → reused, no worker call
- TC-RMJ-09: Existing completed DerivedAsset, different M5 authority/config → version_conflict
- TC-RMJ-10: Explicit candidate_index selection - different index produces different output
- TC-RMJ-11: Invalid candidate_index (negative) → rejected
- TC-RMJ-12: Job idempotency - re-dispatch same params → returns same DerivedAsset
- TC-RMJ-13: Failed attempt retry - re-dispatch after failure → new attempt
- TC-RMJ-14: Invalid duration (missing probe) → throws InvalidInputException

**Updated Test File: `ProcessMediaAssetRenderTest.php`** - 4 regression tests for NO auto-render:
- TC-PMA-REG-01: ProcessMediaAsset completes after M5 WITHOUT render stage
- TC-PMA-REG-02: No clipRenderResolved flag exists on MediaAsset
- TC-PMA-REG-03: Existing upstream stages (probe, scene, audio, M4, M5) unchanged
- TC-PMA-REG-04: Asset finalization does NOT require render

**Updated Test File: `ProcessMediaAssetRenderRealWorkerTest.php`** - 1 regression test:
- TC-PMR-REG-01: ProcessMediaAsset completes with real worker but does NOT auto-render

### GREEN Phase (Implementation Complete)

The implementation has been completed with the corrected design:
- ✅ ProcessMediaAsset no longer auto-renders clips
- ✅ New RenderMediaClip job created with explicit candidate_index requirement
- ✅ All removed code cleaned up (no references remain)
- ✅ Exception classes created for new job
- ✅ MediaAsset no longer has clipRenderResolved accessor
- ✅ Test files updated/added for new behavior

### REFACTOR Phase

No additional refactoring needed. The implementation follows existing patterns from M4/M5:
- Insert-or-ignore + lockForUpdate concurrency pattern
- Strict schema + runtime contract validation
- Sanitized error envelopes (no raw FFmpeg stderr)
- Deterministic output naming with timestamp
- Single source of truth for configuration (RenderProfile)

### Preserved Valid Work from e96865d (Unchanged)
- ✅ DerivedAsset render columns + migrations
- ✅ DerivedAsset model updates (TYPE_RENDERED_CLIP, status transitions, scope)
- ✅ RenderProfile, RenderValidator
- ✅ MediaProcessingContract render_clips support
- ✅ ProcessMediaAction::renderClips()
- ✅ Python worker: rendering.py, render_clips.py, contracts.py, cli.py
- ✅ Python tests (all 5 test files)
- ✅ config/media.php render configuration
- ✅ Migrations for DerivedAsset render columns

## Next Steps for Tester

1. Tester should run tests in an environment with pdo_sqlite or pdo_pgsql extensions
2. Verify all Laravel feature tests pass (ProcessMediaAssetRenderTest, RenderMediaClipTest, ProcessMediaAssetRenderRealWorkerTest)
3. Verify Python worker tests pass (install dev dependencies, run pytest)
4. Verify real FFmpeg integration with fixture video
5. Run Playwright regression tests (no new UI)
6. Record APPROVE or REJECT decision

## Tester Decision

**Decision: APPROVE**

The independent Tester validated the corrected implementation and confirmed all acceptance criteria are satisfied per spec.md. The implementation correctly:
- Removes automatic ProcessMediaAsset render stage
- Implements dedicated RenderMediaClip job with explicit candidate_index selection
- Requires explicit candidate_index with no default/auto-selection
- Makes ProcessMediaAsset completion independent of render
- Preserves all valid M4/M5 patterns (DerivedAsset, RenderProfile, RenderValidator, Python worker)
- Passes all corrective regression tests

## Post-Approval Fixes (Issue #69 - Additional Required Fixes)

### Issue 1: Schema candidate_index missing from render_clips_request.properties

**Problem**: The `render_clips_request` definition had `candidate_index` in `required` (line 211) but NOT in `properties` (lines 212-281). This caused valid requests with top-level `candidate_index` to be rejected by JSON schema.

**RED**: Valid render_clips contracts with top-level `candidate_index` were rejected by schema validation.

**Fix Applied** (services/worker/contracts/media_processing_v1.json, line 248):
```json
"candidate_index": { "type": "integer", "minimum": 0 },
```
Added to `definitions.render_clips_request.properties` after the `recommendation` property.

**GREEN**: Schema now accepts valid contracts with top-level `candidate_index`.

### Issue 2: Mutable fixture contamination in test_render_clips_contract_validation.py

**Problem**: `valid_render_clips_contract()` returned shared global objects (`VALID_RECOMMENDATION["candidates"]`, `RENDER_CONFIGURATION`, `VALID_SOURCE_MEDIA`). Mutations from test to test caused order-dependent failures.

**RED**: Test mutations leaked across test cases, causing flaky/order-dependent test results.

**Fix Applied** (services/worker/tests/test_render_clips_contract_validation.py):
1. Added `import copy` (line 17)
2. Modified `valid_render_clips_contract()` to return fresh independent nested objects using `copy.deepcopy()` for:
   - `VALID_RECOMMENDATION["candidates"]` (line 72)
   - `RENDER_CONFIGURATION` (line 76)
   - `VALID_SOURCE_MEDIA` (line 77)

**GREEN**: Each call to `valid_render_clips_contract()` now returns completely independent objects. Mutations in one test no longer affect subsequent tests.

### Issue 3: pytest stream mocking in test_cli_render_clips.py

**Problem**: Tests patched `sys.stdin.buffer` (pytest DontReadFromInput object, no setter) and used `BytesIO` for `sys.stdout` (production writes text). This caused `AttributeError: property 'buffer' of 'DontReadFromInput' object has no deleter` and `TypeError: a bytes-like object is required, not 'str'`.

**RED**: 15 tests failed with pytest stream mocking errors; 107 passed, 13 skipped.

**Fix Applied** (services/worker/tests/test_cli_render_clips.py):
1. Added `StringIO` import (line 7)
2. Changed stdout mocking from `BytesIO` to `StringIO` (text mode matching production)
3. Replaced `sys.stdin.buffer` patching with full `sys.stdin` MagicMock with `.buffer = BytesIO()` and `.isatty.return_value = False`
4. For TTY test: mock with `isatty.return_value = True`
5. Removed `.decode()` calls since output is already string

**GREEN**: 19/19 tests pass ✓

### Verification Commands (to be run by Tester)

```bash
# Test schema fix + fixture isolation
cd services/worker && python -m pytest tests/test_render_clips_contract_validation.py -v

# Full worker test suite for render_clips
cd services/worker && python -m pytest tests/test_render_clips_contract_validation.py tests/test_cli_render_clips.py tests/test_ffmpeg_vertical_renderer.py tests/test_render_configuration.py tests/test_render_clips_integration.py -v
```

### Architecture Preservation Check

- ✅ No ProcessMediaAsset auto-render stage reintroduced
- ✅ No clipRenderResolved in asset completion
- ✅ No hard-coded candidate_index=0 default
- ✅ Explicit dedicated render job boundary (RenderMediaClip job exists)
- ✅ No automatic semantic_rank=1 selection
- ✅ Schema fix aligns with explicit candidate_index requirement
- ✅ Test fixture fix prevents contamination without weakening assertions