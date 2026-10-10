# Test Plan: Render Idempotency Semantics (REC-02)

## Test Strategy

This test plan maps each Acceptance Criterion (AC) to specific test cases. Tests are implemented in `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php` using Pest.

---

## Test Mapping to Acceptance Criteria

| AC ID | Criterion | Test Case | Status |
|-------|-----------|-----------|--------|
| AC-01 | Same inputs → reuses COMPLETED DerivedAsset | TC-RMJ-12 (existing), TC-RMJ-CAP-06 (renamed) | ✅ Existing |
| AC-02 | No worker call on equivalent re-dispatch | TC-RMJ-12, TC-RMJ-CAP-06 | ✅ Existing |
| AC-03 | Same DerivedAsset ID returned | TC-RMJ-12, TC-RMJ-CAP-06 | ✅ Existing |
| AC-04 | Transcript segments changed → NEW render | **TC-RMJ-15** (new) | 🆕 New |
| AC-05 | Caption file content changed → NEW render | Covered by AC-04 (caption derives from transcript) | 🆕 New |
| AC-06 | Projection parameters changed → NEW render | TC-RMJ-10 (existing, different candidate_index) | ✅ Existing |
| AC-07 | Failed DerivedAsset → retry allowed | TC-RMJ-13 (existing) | ✅ Existing |
| AC-08 | Retry after failure → calls worker | TC-RMJ-13 | ✅ Existing |
| AC-09 | Successful retry → COMPLETED | TC-RMJ-13 | ✅ Existing |
| AC-10 | Concurrent equivalent requests → one succeeds | Implicit via `lockForUpdate` (not explicitly tested) | ⚠️ Not explicit |
| AC-11 | All existing tests pass | Full test suite run | 🔄 Verify |
| AC-12 | TC-RMJ-CAP-06 renamed and aligned | **TC-RMJ-CAP-06** renamed to "reuses completed render with same transcript" | 🆕 Rename |

---

## Detailed Test Cases

### TC-RMJ-12: Job Idempotency (Existing)
- **Name**: `is idempotent - re-dispatch returns same DerivedAsset`
- **Scenario**: Dispatch job twice with identical parameters
- **Assertions**:
  - `count($action2->renderCalls) === 1` (only first calls worker)
  - `$result1->id === $result2->id`

### TC-RMJ-CAP-06: Caption Idempotency (Renamed)
- **Old Name**: `generates distinct caption files on re-dispatch`
- **New Name**: `reuses completed render with same transcript - idempotent`
- **Scenario**: Dispatch with transcript, then re-dispatch with same transcript
- **Assertions**:
  - First dispatch: `count($action1->renderCalls) === 1`, has `caption_file`
  - Second dispatch: `count($action2->renderCalls) === 0`, no worker call
  - `$result1->id === $result2->id`
  - Both `RENDER_STATUS_COMPLETED`

### TC-RMJ-15: Transcript Change Triggers New Render (New)
- **Name**: `creates new render when transcript segments change`
- **Scenario**:
  1. Render with transcript segments A
  2. Modify transcript segments to B
  3. Re-dispatch with same media/recommendation/candidate
- **Assertions**:
  - First render: 1 worker call
  - Second render: 1 worker call (NOT 0)
  - `$result1->id !== $result2->id`
  - Two DerivedAsset rows with `RENDER_STATUS_COMPLETED`
  - Both have different `transcript_hash` values

### TC-RMJ-16: No Transcript Idempotency (New)
- **Name**: `reuses render when both dispatches have no transcript`
- **Scenario**: Dispatch twice with no transcript
- **Assertions**:
  - First: 1 worker call, no `caption_file`
  - Second: 0 worker calls
  - Same DerivedAsset ID
  - Both have `transcript_hash = NULL`

### TC-RMJ-17: Transcript Added After Initial Render (New)
- **Name**: `creates new render when transcript added after initial render`
- **Scenario**:
  1. Render without transcript
  2. Add completed transcript
  3. Re-dispatch
- **Assertions**:
  - First: 1 worker call, no `caption_file`
  - Second: 1 worker call, HAS `caption_file`
  - Different DerivedAsset IDs
  - First has `transcript_hash = NULL`, second has non-null hash

---

## Regression Test Suite

Run all RenderMediaClip tests to ensure no regressions:

```bash
cd apps/api
php artisan test --compact --filter=RenderMediaClip
```

### Expected Test Count

| Category | Count |
|----------|-------|
| Core render tests (TC-RMJ-01 to TC-RMJ-14) | 14 |
| Caption integration (TC-RMJ-CAP-01 to TC-RMJ-CAP-06) | 6 |
| Validation failure (FV-01 to FV-10+) | ~10 |
| **New idempotency tests (TC-RMJ-15 to TC-RMJ-17)** | **3** |
| **Total** | **~33** |

---

## Edge Cases to Verify Manually

| Scenario | Expected |
|----------|----------|
| Transcript segments partially in range | Hash only in-range segments |
| Empty transcript segments array | Null hash → matches other null hashes |
| Transcript status FAILED | Treated as no transcript (null hash) |
| Candidate with different clipStartMs/clipEndMs | Different render (covered by TC-RMJ-10) |
| Same transcript, different recommendation_id | Different render (different M5 authority) |

---

## Test Data Requirements

### Fixtures

Existing fixtures in `RenderMediaClipTest.php`:
- `createProbedAssetForRender()` - MediaAsset with probe data
- `createCompletedSceneAnalysisForRender()` - Scene analysis
- `createCompletedClipAnalysisForRender()` - Clip analysis with 2 candidates
- `createCompletedTranscriptForRender()` - Transcript with 2 segments
- `createCompletedRecommendationForRender()` - Ranked recommendation

### Mock Worker

`RecordingRenderActionForRender` - Records render calls for assertion

---

## CI Integration

Add to GitHub Actions workflow:

```yaml
- name: Run RenderMediaClip Tests
  run: |
    cd apps/api
    php artisan test --compact --filter=RenderMediaClip
```

---

## Test Execution Checklist

- [ ] Run migration locally
- [ ] Run all existing RenderMediaClip tests → PASS
- [ ] Run new test TC-RMJ-15 → PASS
- [ ] Run new test TC-RMJ-16 → PASS
- [ ] Run new test TC-RMJ-17 → PASS
- [ ] Verify TC-RMJ-CAP-06 renamed and passes
- [ ] Run full test suite → PASS
- [ ] Run Pint → PASS
- [ ] Commit changes

---

## Coverage Targets

| Metric | Target |
|--------|--------|
| Line coverage (RenderMediaClip.php) | ≥ 95% |
| Branch coverage (idempotency logic) | ≥ 90% |
| New test cases | 3 |

---

## Test Maintenance

- Tests are self-contained (RefreshDatabase)
- No external dependencies
- Deterministic fixtures
- No flaky assertions