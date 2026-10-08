# M6.2 Slice 3 Implementation Plan

## Issue
**#81** — feat(captions): connect Laravel clip context to worker

## Branch
`@carlosegoulart/81/feat/laravel-worker-caption-flow`

## Overview
This plan implements the Laravel-side caption flow connecting MediaTranscript to the worker's render_clip contract. The implementation follows TDD: RED → GREEN → REFACTOR.

## Stages

### Stage 1: Segment Projection Function (Laravel)
**Goal**: Implement pure PHP `projectSegmentsToClipLocal()` matching Slice 2 algorithm exactly.

**Files**:
- `app/Services/CaptionProjection.php` (new)
- `tests/Unit/Services/CaptionProjectionTest.php` (new)

**TDD Steps**:
1. **RED**: Write Pest tests for all TP-02 scenarios (matching Slice 2 test cases)
2. **GREEN**: Implement `CaptionProjection::project()` static method
3. **REFACTOR**: Ensure integer-only arithmetic, add PHPStan/Pint compliance

**Function Signature**:
```php
final class CaptionProjection
{
    /**
     * @param array<int, array{start_ms: int, end_ms: int, text: string}> $segments
     * @return array<int, array{local_start_ms: int, local_end_ms: int, text: string}>
     */
    public static function project(array $segments, int $clipStartMs, int $clipEndMs): array
}
```

**Algorithm** (must match `project_segments_to_clip_local` exactly):
```php
$clipDurationMs = $clipEndMs - $clipStartMs;
if ($clipDurationMs <= 0) return [];

$result = [];
foreach ($segments as $segment) {
    $localStart = $segment['start_ms'] - $clipStartMs;
    $localEnd = $segment['end_ms'] - $clipStartMs;
    $localStart = max(0, $localStart);
    $localEnd = min($clipDurationMs, $localEnd);
    if ($localEnd <= $localStart) continue;
    $result[] = [
        'local_start_ms' => $localStart,
        'local_end_ms' => $localEnd,
        'text' => $segment['text'],
    ];
}
return $result;
```

---

### Stage 2: SRT Generation
**Goal**: Convert projected segments to valid SRT format.

**Files**:
- `app/Services/SrtGenerator.php` (new)
- `tests/Unit/Services/SrtGeneratorTest.php` (new)

**TDD Steps**:
1. **RED**: Write Pest tests for TP-03 scenarios
2. **GREEN**: Implement `SrtGenerator::generate()` static method
3. **REFACTOR**: Handle edge cases, ensure valid SRT format

**Function Signature**:
```php
final class SrtGenerator
{
    /**
     * @param array<int, array{local_start_ms: int, local_end_ms: int, text: string}> $segments
     */
    public static function generate(array $segments): string
}
```

**SRT Format**:
```
1
00:00:01,200 --> 00:00:02,600
Hello world

2
00:00:03,000 --> 00:00:04,500
Second line
```

**Timestamp Conversion**: `ms → HH:MM:SS,mmm` (comma, zero-padded to 3 digits)

---

### Stage 3: SRT Storage
**Goal**: Store SRT file on storage disk with project-scoped key.

**Files**:
- `app/Services/StorageKeyBuilder.php` (extend with `captionFile()` method)
- `tests/Unit/Services/StorageKeyBuilderTest.php` (extend)

**TDD Steps**:
1. **RED**: Write tests for key pattern and storage
2. **GREEN**: Add `StorageKeyBuilder::captionFile()` method
3. **REFACTOR**: Ensure consistency with `renderClip()` pattern

**Key Pattern**:
```
projects/{project_id}/captions/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.srt
```

**Storage**: `Storage::disk($disk)->put($key, $content, 'public')` (or appropriate visibility)

---

### Stage 4: MediaProcessingContract Extension
**Goal**: Extend `renderClipRequest()` to include optional `caption_file`.

**Files**:
- `app/Contracts/MediaProcessingContract.php` (modify)
- `tests/Unit/Contracts/MediaProcessingContractTest.php` (extend)

**TDD Steps**:
1. **RED**: Write tests for TP-05 scenarios
2. **GREEN**: Modify `renderClipRequest()` to:
   - Accept optional `$captionFile` parameter
   - Include `'caption_file' => $captionFile` in returned array when provided
3. **REFACTOR**: Ensure contract validation still passes

**Signature Change**:
```php
public static function renderClipRequest(
    int $mediaAssetId,
    int $durationMs,
    array $recommendation,
    int $recommendationId,
    int $candidateIndex,
    array $sourceMedia,
    int $projectId,
    ?string $captionFile = null  // NEW PARAMETER
): array
```

**Return Array Addition**:
```php
$contract = [
    // ... existing fields
];
if ($captionFile !== null) {
    $contract['caption_file'] = $captionFile;
}
return $contract;
```

---

### Stage 5: RenderMediaClip Job Integration
**Goal**: Wire caption flow into `RenderMediaClip::handle()`.

**Files**:
- `app/Jobs/RenderMediaClip.php` (modify)
- `tests/Feature/Jobs/RenderMediaClipTest.php` (extend)

**TDD Steps**:
1. **RED**: Write integration tests for TP-01, TP-04, TP-05, TP-06
2. **GREEN**: Modify `handle()` to:
   - Fetch `MediaTranscript` for asset
   - Check status = completed
   - Get candidate timing (`start_ms`, `end_ms`) from recommendation
   - Call `CaptionProjection::project()`
   - If projected segments non-empty:
     - Call `SrtGenerator::generate()`
     - Call `StorageKeyBuilder::captionFile()`
     - Store via `Storage::disk()->put()`
     - Pass `$captionFile` key to `MediaProcessingContract::renderClipRequest()`
3. **REFACTOR**: Extract to private method, ensure transaction safety

**Integration Point** (in `handle()`, after candidate selection, before building contract):
```php
// Fetch transcript
$transcript = MediaTranscript::where('media_asset_id', $asset->id)
    ->where('status', MediaTranscript::STATUS_COMPLETED)
    ->first();

$captionFile = null;
if ($transcript && !empty($transcript->segments)) {
    $projected = CaptionProjection::project(
        $transcript->segments,
        $selectedCandidate['start_ms'],
        $selectedCandidate['end_ms']
    );
    
    if (!empty($projected)) {
        $srtContent = SrtGenerator::generate($projected);
        $captionKey = StorageKeyBuilder::captionFile(
            $asset->project_id,
            $asset->id,
            $this->candidateIndex,
            RenderProfile::RENDER_PROFILE_VERSION,
        );
        
        Storage::disk($asset->storage_disk)->put($captionKey, $srtContent);
        $captionFile = $captionKey;
    }
}

// Pass to contract
$renderContract = MediaProcessingContract::renderClipRequest(
    // ... existing params
    $captionFile  // NEW
);
```

---

### Stage 6: Contract Validation Update
**Goal**: Ensure `RenderValidator` accepts `caption_file` in request (optional).

**Files**:
- `app/Services/RenderValidator.php` (modify if needed)
- `services/worker/contracts/media_processing_v1.json` (verify caption_file exists)

**Analysis**: 
- Worker schema already has `caption_file` as optional in `render_clip_request` (line 311-313)
- Laravel `RenderValidator::REQUEST_KEYS` does NOT include `caption_file` — this is correct because:
  - Laravel builds the contract, worker validates it
  - Laravel's `toRenderClipMetadataArray()` should include `caption_file` when present
- Need to verify `MediaProcessingContract::toRenderClipMetadataArray()` includes `caption_file`

**Action**: Modify `toRenderClipMetadataArray()` to include `caption_file` when `$this->captionFile` is set.

**New Property**: Add `?string $captionFile = null` to `MediaProcessingContract` class.

---

### Stage 7: End-to-End Test
**Goal**: Full integration test with real worker (or mocked worker).

**Files**:
- `tests/Feature/CaptionFlowIntegrationTest.php` (new)

**Test Scenarios** (TP-06):
- Asset with transcript → render produces video with captions
- Asset without transcript → render produces video without captions
- Verify filter_graph contains `subtitles=` when caption_file provided

---

## Test File Structure

```
tests/
├── Unit/
│   ├── Services/
│   │   ├── CaptionProjectionTest.php
│   │   ├── SrtGeneratorTest.php
│   │   └── StorageKeyBuilderTest.php (extend)
│   └── Contracts/
│       └── MediaProcessingContractTest.php (extend)
└── Feature/
    ├── Jobs/
    │   └── RenderMediaClipTest.php (extend)
    └── CaptionFlowIntegrationTest.php
```

## Run Commands

```bash
# Run all tests
php artisan test --compact

# Run specific test file
php artisan test --filter=CaptionProjectionTest

# Run with coverage
php artisan test --coverage
```

## Verification Checklist

- [ ] All unit tests pass (CaptionProjection, SrtGenerator, StorageKeyBuilder)
- [ ] Contract tests pass (MediaProcessingContract)
- [ ] RenderMediaClip job tests pass
- [ ] Integration test passes
- [ ] PHPStan level 5 passes
- [ ] Pint formatting passes
- [ ] No regression in existing tests