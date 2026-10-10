# Implementation Plan: Render Idempotency Semantics (REC-02)

## Overview

This plan implements idempotent render reuse with transcript/caption change detection. The key change is adding a `transcript_hash` to the DerivedAsset model and using it in the idempotency check.

---

## Step 1: Database Migration

### 1.1 Create Migration for `transcript_hash` Column

```bash
php artisan make:migration add_transcript_hash_to_derived_assets_table --table=derived_assets
```

### 1.2 Migration Content

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('derived_assets', function (Blueprint $table) {
            $table->string('transcript_hash', 64)->nullable()->after('render_parameters');
            $table->index(['media_asset_id', 'type', 'candidate_index', 'render_profile_version', 'transcript_hash'], 'idx_render_idempotency');
        });
    }

    public function down(): void
    {
        Schema::table('derived_assets', function (Blueprint $table) {
            $table->dropIndex('idx_render_idempotency');
            $table->dropColumn('transcript_hash');
        });
    }
};
```

### 1.3 Run Migration

```bash
php artisan migrate
```

---

## Step 2: Update `DerivedAsset` Model

### 2.1 Add `transcript_hash` to Fillable

**File**: `apps/api/app/Models/DerivedAsset.php`

```php
protected $fillable = [
    // ... existing fields ...
    'render_parameters',
    'render_error',
    'render_started_at',
    'render_completed_at',
    'transcript_hash',  // ADD
];
```

### 2.2 Add Cast for `transcript_hash`

```php
protected function casts(): array
{
    return [
        // ... existing casts ...
        'render_started_at' => 'datetime',
        'render_completed_at' => 'datetime',
        'transcript_hash' => 'string',  // ADD
    ];
}
```

---

## Step 3: Update `RenderMediaClip` Job

### 3.1 Add Helper Method: Compute Transcript Hash

**File**: `apps/api/app/Jobs/RenderMediaClip.php`

Add private method after `isLockTimeout()`:

```php
/**
 * Compute SHA-256 hash of transcript segments that project to the clip range.
 * Returns null if no transcript, no segments, or no in-range segments.
 */
private function computeTranscriptHash(?MediaTranscript $transcript, int $clipStartMs, int $clipEndMs): ?string
{
    if ($transcript === null) {
        return null;
    }

    $segments = $transcript->segments ?? [];
    if (empty($segments)) {
        return null;
    }

    // Filter segments that intersect with clip range
    $inRangeSegments = array_filter($segments, function (array $segment) use ($clipStartMs, $clipEndMs): bool {
        $segStart = $segment['start_ms'] ?? 0;
        $segEnd = $segment['end_ms'] ?? 0;
        return $segStart < $clipEndMs && $segEnd > $clipStartMs;
    });

    if (empty($inRangeSegments)) {
        return null;
    }

    // Normalize: sort by start_ms, then encode minimal content for hash
    $normalized = array_values(array_map(function (array $segment): array {
        return [
            's' => (int) ($segment['start_ms'] ?? 0),
            'e' => (int) ($segment['end_ms'] ?? 0),
            't' => (string) ($segment['text'] ?? ''),
        ];
    }, $inRangeSegments));

    usort($normalized, fn (array $a, array $b) => $a['s'] <=> $b['s']);

    return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
}
```

### 3.2 Modify Idempotency Check (Lines 146-166)

**Current**:
```php
$existingRender = DerivedAsset::where('media_asset_id', $asset->id)
    ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
    ->where('candidate_index', $this->candidateIndex)
    ->where('render_profile_version', RenderProfile::RENDER_PROFILE_VERSION)
    ->first();
```

**New**:
```php
// Compute transcript hash BEFORE query
$transcriptHash = $this->computeTranscriptHash($transcript, $clipStartMs, $clipEndMs);

$existingRender = DerivedAsset::where('media_asset_id', $asset->id)
    ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
    ->where('candidate_index', $this->candidateIndex)
    ->where('render_profile_version', RenderProfile::RENDER_PROFILE_VERSION)
    ->where(function ($query) use ($transcriptHash) {
        if ($transcriptHash !== null) {
            $query->where('transcript_hash', $transcriptHash);
        } else {
            $query->whereNull('transcript_hash');
        }
    })
    ->first();
```

### 3.3 Store Transcript Hash on New Render

**In the atomic transaction (around line 216)**, add to `insertOrIgnore`:

```php
DB::table('derived_assets')->insertOrIgnore([
    // ... existing fields ...
    'transcript_hash' => $transcriptHash,  // ADD
]);
```

**On completion (around line 306-323)**, add to update:

```php
$locked->transcript_hash = $transcriptHash;  // ADD
$locked->save();
```

---

## Step 4: Update Tests

### 4.1 Rename `TC-RMJ-CAP-06` and Fix Assertions

**File**: `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php`

**Rename test** from:
```php
it('generates distinct caption files on re-dispatch', function () {
```

**To**:
```php
it('reuses completed render with same transcript - idempotent', function () {
```

**Assertions already correct** (expects 0 render calls on second dispatch, same DerivedAsset ID).

### 4.2 Add New Test: Transcript Change Triggers New Render

```php
/*
|--------------------------------------------------------------------------
| TC-RMJ-15: Transcript content changed → new render (not reused)
|--------------------------------------------------------------------------
*/

it('creates new render when transcript segments change', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action1 = new RecordingRenderActionForRender;
    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action1);
    $result1 = $job1->handle();

    expect(count($action1->renderCalls))->toBe(1);
    $captionFile1 = $action1->renderCalls[0]['caption_file'] ?? null;

    // Modify transcript segments (simulate user edit)
    $transcript->update([
        'segments' => [
            ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'First segment MODIFIED'],
            ['start_ms' => 10000, 'end_ms' => 20000, 'text' => 'Second segment MODIFIED'],
        ],
    ]);

    $action2 = new RecordingRenderActionForRender;
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action2);
    $result2 = $job2->handle();

    // New render triggered
    expect(count($action2->renderCalls))->toBe(1);
    expect($result1->id)->not->toBe($result2->id);

    // Two DerivedAsset rows exist (old completed, new completed)
    $renders = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->get();

    expect($renders->count())->toBe(2);
    expect($renders->where('render_status', DerivedAsset::RENDER_STATUS_COMPLETED)->count())->toBe(2);
});
```

### 4.3 Add New Test: No Transcript → Null Hash Matching

```php
/*
|--------------------------------------------------------------------------
| TC-RMJ-16: No transcript on both dispatches → idempotent reuse
|--------------------------------------------------------------------------
*/

it('reuses render when both dispatches have no transcript', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    // No transcript
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, null);

    $action1 = new RecordingRenderActionForRender;
    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action1);
    $result1 = $job1->handle();

    $action2 = new RecordingRenderActionForRender;
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action2);
    $result2 = $job2->handle();

    expect(count($action1->renderCalls))->toBe(1);
    expect(count($action2->renderCalls))->toBe(0);
    expect($result1->id)->toBe($result2->id);
});
```

### 4.4 Add New Test: Transcript Added After Initial Render

```php
/*
|--------------------------------------------------------------------------
| TC-RMJ-17: Transcript added after initial render → new render
|--------------------------------------------------------------------------
*/

it('creates new render when transcript added after initial render', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    // Initial: no transcript
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, null);

    $action1 = new RecordingRenderActionForRender;
    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action1);
    $result1 = $job1->handle();

    expect(count($action1->renderCalls))->toBe(1);
    expect($action1->renderCalls[0])->not->toHaveKey('caption_file');

    // Add transcript
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation->refresh(); // Not strictly needed but ensures consistency

    $action2 = new RecordingRenderActionForRender;
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action2);
    $result2 = $job2->handle();

    expect(count($action2->renderCalls))->toBe(1);
    expect($action2->renderCalls[0])->toHaveKey('caption_file');
    expect($result1->id)->not->toBe($result2->id);
});
```

### 4.5 Add New Test: Projection Change (Different Candidate) → New Render

Already covered by `TC-RMJ-10` (different candidate_index produces different output).

### 4.6 Update Existing Test: `TC-RMJ-12` (Job Idempotency)

Ensure it still passes — it uses same transcript, so should reuse.

---

## Step 5: Verify All Tests Pass

```bash
cd apps/api
php artisan test --compact --filter=RenderMediaClip
```

Expected: All existing tests pass + 3 new tests pass.

---

## Step 6: Code Style

```bash
cd apps/api
vendor/bin/pint --dirty --format agent
```

---

## Step 7: Commit

```bash
git add -A
git commit -m "feat(render): implement idempotent render with transcript hash detection

- Add transcript_hash column to derived_assets
- Compute transcript hash from in-range segments in RenderMediaClip
- Use transcript_hash in idempotency check
- Transcript changes now trigger new render
- Rename TC-RMJ-CAP-06 to reflect idempotent behavior
- Add tests for transcript change, no-transcript, and transcript-added scenarios

Refs #92"
```

---

## File Change Summary

| File | Change Type |
|------|-------------|
| `database/migrations/XXXX_add_transcript_hash_to_derived_assets_table.php` | New |
| `apps/api/app/Models/DerivedAsset.php` | Modified (fillable, casts) |
| `apps/api/app/Jobs/RenderMediaClip.php` | Modified (computeTranscriptHash, idempotency query, store hash) |
| `apps/api/tests/Feature/Jobs/RenderMediaClipTest.php` | Modified (rename test, add 3 new tests) |

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Migration fails on existing data | Column is nullable, no data loss |
| Hash collision | SHA-256, negligible risk |
| Performance | Index on (media_asset_id, type, candidate_index, render_profile_version, transcript_hash) |
| Existing tests break | Only new behavior for transcript changes; existing idempotency preserved |

---

## Rollback Plan

```bash
php artisan migrate:rollback
git revert <commit>
```