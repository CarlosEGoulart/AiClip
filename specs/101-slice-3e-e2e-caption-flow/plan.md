# M6.2 Slice 3E: End-to-End Caption Flow Integration Test — Implementation Plan

## Issue
**#101** — test(e2e): end-to-end caption flow integration test (M6.2 Slice 3E)

## Branch
`@carlosegoulart/101/test/e2e-caption-flow`

## Prerequisites (Verify Before Starting)
- [ ] Slices 3A–3D merged and available in codebase
- [ ] REC-01 (Issue #91) merged — RenderValidator enforces `request_sha256`
- [ ] REC-02 (Issue #92) merged — Idempotency semantics defined
- [ ] Worker `request_sha256` includes `caption_file` in canonicalization
- [ ] MinIO running in test environment (CI and local)
- [ ] FFmpeg with `subtitles` filter (libass) available
- [ ] Test video fixture created: `tests/fixtures/caption_test_video.mp4` (~10-30s, 1920x1080, with audio)

## Implementation Steps

### Step 1: Create Test Fixtures
**File**: `tests/fixtures/caption_test_video.mp4`
- Source: Generate or obtain a short test video (10-30 seconds)
- Resolution: 1920x1080 (landscape, for vertical crop to 1080x1920)
- Codec: h264 video, aac audio
- Duration: Known value (e.g., 30000ms = 30s)
- Must be probeable by FFprobe

**File**: Transcript fixture data (inline in test or separate JSON)
```php
$transcriptSegments = [
    ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'First caption segment'],
    ['start_ms' => 10000, 'end_ms' => 20000, 'text' => 'Second caption segment'],
    ['start_ms' => 20000, 'end_ms' => 30000, 'text' => 'Third caption segment'],
];
```
- Segments cover full video duration
- Candidate clip at index 0: 0-10000ms (first 10 seconds)
- Expected projected segments for candidate 0:
  - `local_start_ms=0, local_end_ms=10000, text='First caption segment'`

### Step 2: Create E2E Test Class
**File**: `tests/Feature/Jobs/RenderMediaClipCaptionE2ETest.php`

**Structure**:
```php
<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RenderMediaClip;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaClipRecommendation;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ClipRankingProfile;
use App\Services\ProcessMediaAction;
use App\Services\RenderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Use real MinIO disk (not fake)
    // Ensure render config is set
    config([
        'media.render_timeout_seconds' => '300',
        'media.render_target_width' => 1080,
        'media.render_target_height' => 1920,
        'media.render_target_fps' => 30,
        'media.render_video_codec' => 'libx264',
        'media.render_video_bitrate_kbps' => 5000,
        'media.render_audio_codec' => 'aac',
        'media.render_audio_bitrate_kbps' => 128,
    ]);
    
    // Verify MinIO is available
    Storage::disk('media')->assertExists(''); // bucket exists
});

// Fixture helper functions (reused from RenderMediaClipTest.php but adapted for real storage)
function createTestAssetForE2E(): MediaAsset { ... }
function createSceneAnalysisForE2E(MediaAsset $asset): MediaSceneAnalysis { ... }
function createClipAnalysisForE2E(MediaAsset $asset): MediaClipAnalysis { ... }
function createTranscriptForE2E(MediaAsset $asset): MediaTranscript { ... }
function createRecommendationForE2E(MediaAsset $asset, MediaClipAnalysis $clipAnalysis, MediaTranscript $transcript): MediaClipRecommendation { ... }
```

### Step 3: Implement E2E-CAP-01 — Happy Path
**Test**: `it('completes full caption flow with real MinIO, worker, and FFmpeg')`
- Create project, asset, transcript, analyses, recommendation
- Dispatch `RenderMediaClip` job with real `ProcessMediaAction` (no mock)
- Assert:
  - Job completes without exception
  - `DerivedAsset` created with `render_status=completed`
  - SRT file exists on MinIO at expected key pattern
  - SRT content matches expected format with correct timestamps
  - Worker was invoked (verify via log or DerivedAsset render_parameters)
  - `render_parameters.filter_graph` contains `subtitles=` filter
  - FFprobe on output video shows subtitle stream

**Key assertions**:
```php
// SRT stored on MinIO
$srtFiles = Storage::disk('media')->allFiles('projects/*/captions/*');
expect($srtFiles)->not->toBeEmpty();
$srtContent = Storage::disk('media')->get($srtFiles[0]);
expect($srtContent)->toContain('00:00:00,000 --> 00:00:10,000');
expect($srtContent)->toContain('First caption segment');

// DerivedAsset has render parameters with subtitle filter graph
$render = DerivedAsset::where('type', DerivedAsset::TYPE_RENDERED_CLIP)->first();
expect($render->render_parameters['filter_graph'])->toContain('subtitles=');

// Output video validation (optional: probe output video for subtitle stream)
// This requires downloading output from MinIO and running FFprobe
```

### Step 4: Implement E2E-CAP-02 — No Transcript
**Test**: `it('renders without captions when no transcript exists')`
- Create asset without any `MediaTranscript`
- Dispatch job
- Assert:
  - Job completes
  - No SRT files in captions path
  - `DerivedAsset` created successfully
  - `render_parameters.filter_graph` does NOT contain `subtitles=`

### Step 5: Implement E2E-CAP-03 — Transcript Not Completed
**Test**: `it('renders without captions when transcript status is not completed')`
- Create transcript with status = `pending` (or `failed`)
- Dispatch job
- Assert: Same as E2E-CAP-02 — no captions, render succeeds

### Step 6: Implement E2E-CAP-04 — Empty Projection
**Test**: `it('renders without captions when all transcript segments are outside clip range')`
- Create transcript with segments at 20000-30000ms (candidate is 0-10000ms)
- Dispatch job
- Assert: Same as E2E-CAP-02 — no captions, render succeeds

### Step 7: Implement E2E-CAP-05 — Malformed Segments
**Test**: `it('fails with invalid_input when transcript segments are malformed')`
- Create transcript with malformed segment (missing `start_ms`)
- Dispatch job
- Assert:
  - Job throws `ProcessMediaException` with `invalid_input` message
  - No completed `DerivedAsset` created

### Step 8: Implement E2E-CAP-06 — Idempotency with Captions
**Test**: `it('reuses existing render with same transcript (idempotent)')`
- Run E2E-CAP-01 once → capture `DerivedAsset` ID and caption file key
- Run same job again
- Assert:
  - Second job returns same `DerivedAsset` ID
  - No new SRT file created (count unchanged)
  - Worker not invoked again (verify via log or by checking only one render_parameters entry)

### Step 9: Implement E2E-CAP-07 — Transcript Change Triggers New Render
**Test**: `it('creates new render when transcript segments change')`
- Run E2E-CAP-01 once → capture `DerivedAsset` ID and caption file key
- Update transcript segments (modify text)
- Run job again
- Assert:
  - New `DerivedAsset` created (different ID)
  - New SRT file created (different UUID in key)
  - Two completed `DerivedAsset` rows exist

### Step 10: CI Configuration
**File**: `.github/workflows/test-e2e-caption.yml` (or update existing CI)
- Ensure MinIO service container
- Ensure FFmpeg with libass installed
- Run E2E test suite

### Step 11: Code Quality
- Run Pint: `vendor/bin/pint --dirty --format agent`
- Run PHPStan: `phpstan analyse --level=5`
- Ensure all tests pass: `php artisan test --filter=RenderMediaClipCaptionE2ETest`

## File Changes Summary

### New Files
1. `tests/fixtures/caption_test_video.mp4` — Test video fixture
2. `tests/Feature/Jobs/RenderMediaClipCaptionE2ETest.php` — E2E test class (7 test cases)

### Modified Files (CI only)
- `.github/workflows/*.yml` — Ensure MinIO + FFmpeg for E2E tests

### No Changes To
- Production code (`app/Services/`, `app/Jobs/`, `app/Contracts/`, `app/Models/`)
- Database migrations
- Worker code

## Test Execution Strategy

### Local Development
```bash
# Start MinIO (if not running)
docker run -d -p 9000:9000 -p 9001:9001 minio/minio server /data --console-address ":9001"

# Run tests
cd apps/api
php artisan test --filter=RenderMediaClipCaptionE2ETest --compact
```

### CI Execution
- GitHub Actions spins up MinIO service container
- FFmpeg pre-installed on runners
- Tests run in isolated database (RefreshDatabase)

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| FFmpeg `subtitles` filter not available | Verify `ffmpeg -filters | grep subtitles` in CI setup |
| MinIO connection issues | Health check in `beforeEach`, skip tests if unavailable |
| Test video fixture too large | Keep under 10MB, commit to repo |
| Flaky FFmpeg timing | Use generous timeouts (300s), validate duration tolerance ±50ms |
| Worker process not found | Ensure `config('media.worker_command')` points to correct Python module |

## Verification Checklist

Before marking `GREEN_VERIFIED`:
- [ ] All 7 E2E tests pass locally
- [ ] All 7 E2E tests pass in CI
- [ ] No production code modified
- [ ] Code style clean (Pint)
- [ ] Static analysis clean (PHPStan)
- [ ] Test video fixture committed and probeable
- [ ] Transcript fixture data deterministic
- [ ] No hardcoded secrets or credentials

## Rollback Plan
If E2E tests cannot be stabilized:
1. Revert test file changes
2. Keep REC-04 risk documentation (Issue #94)
3. Defer E2E test to future authorization