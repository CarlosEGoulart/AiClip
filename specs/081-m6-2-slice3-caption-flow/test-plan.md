# M6.2 Slice 3 Test Plan

## Issue
**#81** — feat(captions): connect Laravel clip context to worker

## Test Categories

### Unit Tests

#### TP-01: Transcript Fetching (RenderMediaClip Job)
| ID | Scenario | Expected |
|----|----------|----------|
| TP-01a | MediaAsset has completed MediaTranscript | Segments fetched, caption flow proceeds |
| TP-01b | MediaAsset has no MediaTranscript | No caption_file, render proceeds without captions |
| TP-01c | MediaTranscript status = failed | No caption_file, render proceeds without captions |
| TP-01d | MediaTranscript status = transcribing | No caption_file, render proceeds without captions |
| TP-01e | MediaTranscript segments = [] | No caption_file, render proceeds without captions |

#### TP-02: Segment Projection (CaptionProjection Service)
*Must match Slice 2 `project_segments_to_clip_local` behavior exactly*

| ID | Scenario | Input Segments | Clip Range | Expected Output |
|----|----------|----------------|------------|-----------------|
| TP-02a | Normal projection | `[{11200, 12600, "Hello"}]` | 10000-15000 | `[{1200, 2600, "Hello"}]` |
| TP-02b | Segment before clip | `[{5000, 6000, "Before"}]` | 10000-15000 | `[]` |
| TP-02c | Segment after clip | `[{20000, 21000, "After"}]` | 10000-15000 | `[]` |
| TP-02d | Partial overlap start | `[{9000, 11000, "Start"}]` | 10000-15000 | `[{0, 1000, "Start"}]` |
| TP-02e | Partial overlap end | `[{14000, 20000, "End"}]` | 10000-15000 | `[{4000, 5000, "End"}]` |
| TP-02f | Empty input | `[]` | 10000-15000 | `[]` |
| TP-02g | Determinism | Same as TP-02h | Same | 3 identical calls → identical output |
| TP-02h | Mixed segments | 5 segments (before, start-overlap, inside, end-overlap, after) | 10000-15000 | 3 segments (clamped start, normal, clamped end) |
| TP-02i | Zero-duration clip | `[{10000, 11000, "X"}]` | 10000-10000 | `[]` |
| TP-02j | Segment exactly at bounds | `[{10000, 10000, "X"}, {15000, 15000, "Y"}]` | 10000-15000 | `[]` |
| TP-02k | Empty text preserved | `[{11000, 12000, ""}]` | 10000-15000 | `[{1000, 2000, ""}]` |
| TP-02l | Integer arithmetic only | `[{10001, 10003, "X"}]` | 10000-15000 | `[{1, 3, "X"}]` (ints) |

#### TP-03: SRT Generation (SrtGenerator Service)

| ID | Scenario | Input | Expected |
|----|----------|-------|----------|
| TP-03a | Single segment | `[{1200, 2600, "Hello world"}]` | Valid SRT with 1 entry, index=1, timestamps `00:00:01,200 --> 00:00:02,600` |
| TP-03b | Multiple segments | 3 segments | Sequential indices 1,2,3; correct timestamps; blank line between entries |
| TP-03c | Empty text | `[{1000, 2000, ""}]` | Valid SRT entry with blank text line |
| TP-03d | Multi-line text | `[{1000, 2000, "Line1\nLine2"}]` | SRT preserves line breaks in text |
| TP-03e | Timestamp format | Any | `HH:MM:SS,mmm` (comma, zero-padded: hours 2-digit, minutes 2-digit, seconds 2-digit, ms 3-digit) |
| TP-03f | Zero-duration clip | `[]` | Empty string (no SRT generated) |

**Timestamp Conversion Examples**:
- `0ms` → `00:00:00,000`
- `1200ms` → `00:00:01,200`
- `3600000ms` (1 hour) → `01:00:00,000`
- `3661000ms` (1h 1m 1s) → `01:01:01,000`

#### TP-04: SRT Storage (StorageKeyBuilder + Storage)

| ID | Scenario | Expected |
|----|----------|----------|
| TP-04a | File stored on asset's storage disk | `Storage::disk($asset->storage_disk)->exists($key)` true |
| TP-04b | Key pattern | `projects/{project_id}/captions/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.srt` |
| TP-04c | Content matches generated SRT | `Storage::get($key) === $generatedSrt` |
| TP-04d | UUID is valid v4 | Regex match on UUID in key |

#### TP-05: Worker Contract (MediaProcessingContract)

| ID | Scenario | Expected |
|----|----------|----------|
| TP-05a | Caption file provided | `renderClipRequest()` output includes `'caption_file' => $key` |
| TP-05b | No caption file (null) | `renderClipRequest()` output omits `caption_file` key |
| TP-05c | Empty string caption file | Treated as null → omits key |
| TP-05d | Contract validation | `toRenderClipMetadataArray()` includes `caption_file` when set |

### Integration Tests

#### TP-06: End-to-End Caption Flow

| ID | Scenario | Setup | Expected |
|----|----------|-------|----------|
| TP-06a | Full flow with transcript | Asset + completed transcript + recommendation | Job completes, worker receives caption_file, output video has captions burned in |
| TP-06b | Flow without transcript | Asset + no transcript | Job completes, worker receives no caption_file, output video without captions |
| TP-06c | Filter graph verification | Mock worker capture | When caption_file provided, filter_graph contains `subtitles=` |
| TP-06d | Duration accuracy | With captions | Output duration within 50ms of expected |
| TP-06e | Idempotency | Re-run same job | Reuses existing DerivedAsset, no duplicate SRT generation |

### Regression Tests

#### TP-R1: Existing RenderMediaClip Behavior
- All existing RenderMediaClip tests must pass
- No changes to probe, extract_audio, transcribe, detect_scenes, analyze_clips, rank_clips
- RenderValidator still validates render_clip contracts correctly

#### TP-R2: Worker Contract Compatibility
- Worker `validate_contract()` accepts contracts with optional `caption_file`
- Worker `render_clip` action processes `caption_file` correctly
- No breaking changes to worker schema

#### TP-R3: MediaTranscript Model
- Existing MediaTranscript tests pass
- Status transitions unchanged

## Test Data Requirements

### Fixtures
- MediaAsset with probe data (duration, width, height, codecs)
- MediaClipRecommendation with ranked candidates (semantic_score non-null)
- MediaTranscript with segments array (various overlap scenarios)
- DerivedAsset for idempotency testing

### Mock Worker
For integration tests, use mocked `ProcessMediaAction` that:
- Captures contract JSON sent to worker
- Returns success response with valid render result
- Validates filter_graph contains `subtitles=` when caption_file in contract

## Test Execution

```bash
# Unit tests only
php artisan test tests/Unit/Services/CaptionProjectionTest.php
php artisan test tests/Unit/Services/SrtGeneratorTest.php
php artisan test tests/Unit/Services/StorageKeyBuilderTest.php
php artisan test tests/Unit/Contracts/MediaProcessingContractTest.php

# Feature tests
php artisan test tests/Feature/Jobs/RenderMediaClipTest.php
php artisan test tests/Feature/CaptionFlowIntegrationTest.php

# All tests
php artisan test --compact

# With coverage
php artisan test --coverage --min=80
```

## Acceptance Criteria Mapping

| AC | Test IDs |
|----|----------|
| AC-01: Transcript Fetching | TP-01a, TP-01b, TP-01c, TP-01d, TP-01e |
| AC-02: Segment Projection | TP-02a through TP-02l |
| AC-03: SRT Generation | TP-03a through TP-03f |
| AC-04: SRT Storage | TP-04a through TP-04d |
| AC-05: Worker Contract | TP-05a through TP-05d |
| AC-06: End-to-End Render | TP-06a through TP-06e |

## CI Gate
All tests must pass in GitHub Actions before merge. Required checks:
- `php artisan test --compact` exit code 0
- `vendor/bin/pint --dirty --format agent` exit code 0
- `phpstan analyse --level=5` exit code 0