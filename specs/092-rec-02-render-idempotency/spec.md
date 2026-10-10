# Specification: Render Idempotency Semantics (REC-02)

## 1. Problem Statement

The current implementation has a contradiction between the Slice 3D specification, test `TC-RMJ-CAP-06`, and the actual `RenderMediaClip.php` implementation regarding re-dispatch behavior for caption generation.

### Current Contradiction

| Source | Behavior on Re-dispatch |
|--------|-------------------------|
| **Slice 3D Spec (line 27)** | "Re-dispatching the job for the same asset/candidate/profile should produce a new caption file (fresh UUID) since SRT content depends on the candidate timing." |
| **Test TC-RMJ-CAP-06 Name** | "generates distinct caption files on re-dispatch" |
| **Test TC-RMJ-CAP-06 Assertions** (lines 1021-1035) | Expects `count($action2->renderCalls) === 0` and SAME DerivedAsset ID (idempotent reuse) |
| **Implementation** (lines 153-166) | Reuses COMPLETED DerivedAsset — NO worker call, NO new caption file |

The test assertions and implementation agree (idempotent reuse), but the spec and test name disagree (distinct caption files).

---

## 2. Product Decision: Idempotent Reuse for Equivalent Requests

**Decision**: Equivalent render requests MUST reuse the existing COMPLETED DerivedAsset. Transcript/caption changes are detected and trigger a new render.

### 2.1 Equivalent Request Definition

Two render requests are **equivalent** if ALL of the following match:

| Field | Source | Notes |
|-------|--------|-------|
| `media_asset_id` | Job parameter | |
| `recommendation_id` | Job parameter | Implies same M5 output authority (algorithm, version, parameters) |
| `candidate_index` | Job parameter | |
| `render_profile_version` | `RenderProfile::RENDER_PROFILE_VERSION` | Current profile version constant |
| **Transcript segments content** | `MediaTranscript::segments` | Only when transcript exists, is COMPLETED, and has in-range segments |
| **Projection parameters** | Candidate `start_ms` / `end_ms` | Clip timing from recommendation |

### 2.2 Transcript/Caption Changes Trigger New Render

**Decision**: When transcript segments change (new content, different segments, modified text), the caption file content changes, which affects the rendered video. The system MUST:

1. Detect transcript/content changes via content hash
2. NOT reuse the old completed DerivedAsset
3. Create a new render with updated captions

### 2.3 Retry After Failure

**Decision**: Failed DerivedAsset allows retry.

- `RENDER_STATUS_FAILED` → retry ALLOWED
- New attempt updates existing DerivedAsset, calls worker again
- Current implementation supports this (lines 165-166, 238-244)

### 2.4 Fingerprint/Identity

**Decision**: Stable fingerprint based on semantic inputs.

The render identity fingerprint includes:
- `media_asset_id`
- `recommendation_id`
- `candidate_index`
- `render_profile_version`
- **Transcript content hash** (when transcript exists, is COMPLETED, and projects to non-empty in-range segments)
- **Caption file content hash** (when caption file is generated and stored)

This fingerprint determines if a request is equivalent to a previous one.

---

## 3. Current Behavior Analysis

### 3.1 Idempotency Check (Current Implementation - lines 146-166)

```php
$existingRender = DerivedAsset::where('media_asset_id', $asset->id)
    ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
    ->where('candidate_index', $this->candidateIndex)
    ->where('render_profile_version', RenderProfile::RENDER_PROFILE_VERSION)
    ->first();

if ($existingRender !== null) {
    if ($existingRender->render_status === DerivedAsset::RENDER_STATUS_COMPLETED) {
        return $existingRender; // Idempotent reuse - NO transcript check
    }
    // If failed, continue to retry
}
```

**Missing**: No check for transcript/caption content equivalence.

### 3.2 Caption File Generation (Current - lines 270-282)

Caption file key is generated in the worker contract:
```php
$renderContract = MediaProcessingContract::renderClipRequest(
    // ...
    $transcript?->segments,
    $clipStartMs,
    $clipEndMs,
    // ...
);
```

The caption file storage key format: `projects/{project_id}/captions/{asset_id}/{candidate_index}/{profile_version}/{uuid}.srt`

---

## 4. Acceptance Criteria

### 4.1 Idempotency (Equivalent Requests)

| ID | Criterion |
|----|-----------|
| **AC-01** | Same `media_asset_id`, `recommendation_id`, `candidate_index`, `render_profile_version`, transcript segments, and projection → reuses COMPLETED DerivedAsset |
| **AC-02** | No worker call on equivalent re-dispatch |
| **AC-03** | Same DerivedAsset ID returned |

### 4.2 Transcript/Caption Changes

| ID | Criterion |
|----|-----------|
| **AC-04** | Transcript segments changed (content, order, count) → NEW render (NOT reuse) |
| **AC-05** | Caption file content changed → NEW render |
| **AC-06** | Projection parameters changed (`clipStartMs`/`clipEndMs`) → NEW render |

### 4.3 Failure Retry

| ID | Criterion |
|----|-----------|
| **AC-07** | Failed DerivedAsset (`RENDER_STATUS_FAILED`) → retry creates new attempt |
| **AC-08** | Retry after failure → calls worker again |
| **AC-09** | After successful retry → DerivedAsset marked COMPLETED |

### 4.4 Concurrency

| ID | Criterion |
|----|-----------|
| **AC-10** | Concurrent equivalent requests → one succeeds, others wait/reuse (via `lockForUpdate`) |

### 4.5 Regression

| ID | Criterion |
|----|-----------|
| **AC-11** | All existing `RenderMediaClip` tests pass |
| **AC-12** | `TC-RMJ-CAP-06` renamed and aligned with idempotent behavior |

---

## 5. Scope

### In Scope

| File | Changes |
|------|---------|
| `apps/api/app/Jobs/RenderMediaClip.php` | Add transcript hash to idempotency check; compute fingerprint |
| `apps/api/app/Models/DerivedAsset.php` | Add `transcript_hash` column (nullable), update fillable/casts |
| `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php` | Rename `TC-RMJ-CAP-06`, add transcript change test cases |
| Migration | Add `transcript_hash` to `derived_assets` table |

### Out of Scope

- Worker rendering logic (Python media worker)
- DB schema beyond `transcript_hash`
- API endpoints
- Frontend
- Multiple caption tracks/languages
- Caption styling

---

## 6. Dependencies

| Dependency | Status |
|------------|--------|
| `DerivedAsset` model migration | Required (new column) |
| `MediaTranscript` segments content | Existing |
| `RenderProfile::RENDER_PROFILE_VERSION` | Existing constant |
| `StorageKeyBuilder::renderClip()` | Existing |

---

## 7. Security Considerations

- No secrets exposed
- Transcript hash is derived from user content (no PII risk beyond existing transcript storage)
- Fingerprint computation is server-side only

---

## 8. UX Considerations

- Users re-triggering render with same inputs get instant result (cached)
- Users editing transcript see new render automatically
- No silent stale captions

---

## 9. Architecture Alignment

- Laravel remains authoritative backend
- Heavy ML inference stays in media worker
- Fingerprint is computed in Laravel job, passed to worker via contract
- Worker uses fingerprint for its own idempotency (out of scope)

---

## 10. Open Questions

None — all decisions made in Issue #92 product decision section.

---

## 11. References

- Issue #92: REC-02: Define and Test Render Idempotency Semantics
- Slice 3D Spec: `/specs/089-m6-2-slice3d-render-caption-integration/spec.md`
- Current implementation: `apps/api/app/Jobs/RenderMediaClip.php`
- Test file: `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php`