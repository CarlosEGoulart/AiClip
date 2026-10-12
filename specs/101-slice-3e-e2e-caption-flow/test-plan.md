# M6.2 Slice 3E: End-to-End Caption Flow Integration Test — Test Plan

## Issue
**#101** — test(e2e): end-to-end caption flow integration test (M6.2 Slice 3E)

## Test Strategy
This test plan defines validation scenarios for the E2E integration test. Tests use **real infrastructure**:
- Real MinIO (S3-compatible) storage
- Real Python worker process (`python -m aiclip_worker.cli render-clip`)
- Real FFmpeg with `subtitles` filter (libass)
- Real FFprobe for output validation
- Real Laravel queue (sync driver for test determinism)

**No mocks** for storage, worker, or FFmpeg — this is the critical difference from existing feature tests.

## Test Environment Requirements

### Required Services
| Service | Purpose | Version/Config |
|---------|---------|----------------|
| MinIO | S3-compatible storage for media and captions | Docker `minio/minio:latest` |
| FFmpeg | Video rendering with subtitles filter | ≥6.0 with libass enabled |
| FFprobe | Output video validation | Same as FFmpeg |
| Python | Worker CLI execution | ≥3.10 with aiclip_worker package |
| PostgreSQL | Test database | 16 (via Laravel RefreshDatabase) |

### Test Fixtures
| Fixture | Description | Location |
|---------|-------------|----------|
| `caption_test_video.mp4` | 30s test video, 1920x1080, h264/aac | `tests/fixtures/` |
| Transcript segments | Known segments matching video timestamps | Inline in test helpers |

**Video Fixture Spec**:
- Duration: 30000ms (30 seconds)
- Resolution: 1920x1080 (landscape)
- Video codec: h264
- Audio codec: aac
- Candidate clip for testing: index 0, 0-10000ms (first 10 seconds)

**Transcript Fixture**:
```php
$segments = [
    ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'First caption segment'],
    ['start_ms' => 10000, 'end_ms' => 20000, 'text' => 'Second caption segment'],
    ['start_ms' => 20000, 'end_ms' => 30000, 'text' => 'Third caption segment'],
];
```
- For candidate 0 (0-10000ms): projects to 1 segment → `local_start_ms=0, local_end_ms=10000`

## Test Scenarios

### E2E-CAP-01: Happy Path — Complete Caption Flow
**Priority**: Critical
**Type**: Positive integration

**Preconditions**:
- MinIO running and accessible
- FFmpeg with `subtitles` filter available
- Test video fixture exists and is probeable
- Clean database (RefreshDatabase)

**Steps**:
1. Create Project (via factory)
2. Upload test video to MinIO → create MediaAsset (probed, 30000ms)
3. Create MediaSceneAnalysis (completed, scenes covering video)
4. Create MediaClipAnalysis (completed, candidates including index 0: 0-10000ms)
5. Create MediaTranscript (STATUS_COMPLETED, segments as per fixture)
6. Create MediaClipRecommendation (completed, ranked, candidate 0 semantic_score non-null)
7. Dispatch `RenderMediaClip` job with real `ProcessMediaAction`
8. Job executes `handle()` → invokes worker → renders with captions
9. Validate results

**Assertions**:
| Check | Expected | Validation Method |
|-------|----------|-------------------|
| Job completes | No exception thrown | Try/catch |
| DerivedAsset created | `render_status=completed` | DB query |
| SRT file exists on MinIO | Key matches `projects/*/captions/*` pattern | `Storage::disk('media')->allFiles()` |
| SRT content valid | Contains `00:00:00,000 --> 00:00:10,000` and `First caption segment` | `Storage::get()` |
| Worker contract had caption_file | `render_parameters` shows `caption_file` in request metadata | DerivedAsset.render_parameters |
| FFmpeg filter graph has subtitles | `filter_graph` contains `subtitles=` | DerivedAsset.render_parameters['filter_graph'] |
| Output video has subtitle stream | FFprobe shows `codec_type: subtitle` | FFprobe on output file |
| Output duration correct | 10000ms ± 50ms | DerivedAsset.duration_ms |
| Output resolution | 1080x1920 | DerivedAsset.width/height |
| request_sha256 present | 64-char hex in render_parameters | DerivedAsset.render_parameters['request_sha256'] |

**Pass Criteria**: All assertions pass

---

### E2E-CAP-02: No Transcript
**Priority**: Critical
**Type**: Negative integration (graceful degradation)

**Preconditions**: Same as E2E-CAP-01, but NO MediaTranscript created

**Steps**:
1. Create asset, analyses, recommendation (no transcript)
2. Dispatch RenderMediaClip job
3. Validate results

**Assertions**:
| Check | Expected |
|-------|----------|
| Job completes | No exception |
| DerivedAsset created | `render_status=completed` |
| No SRT files | `Storage::allFiles('projects/*/captions/*')` empty |
| Filter graph no subtitles | `filter_graph` does NOT contain `subtitles=` |
| Output video valid | Duration, resolution match config |

---

### E2E-CAP-03: Transcript Status Not Completed
**Priority**: High
**Type**: Negative integration (graceful degradation)

**Preconditions**: MediaTranscript exists with status = `pending` (or `failed`, `transcribing`)

**Steps**:
1. Create transcript with `status = MediaTranscript::STATUS_PENDING`
2. Dispatch job
3. Validate results

**Assertions**: Same as E2E-CAP-02 — no captions, render succeeds

---

### E2E-CAP-04: Empty Projection (Segments Outside Clip Range)
**Priority**: High
**Type**: Negative integration (boundary condition)

**Preconditions**:
- Transcript with segments at 20000-30000ms (candidate is 0-10000ms)
- Transcript status = `completed`

**Steps**:
1. Create transcript with segments entirely after clip range
2. Dispatch job
3. Validate results

**Assertions**: Same as E2E-CAP-02 — projection yields empty, no captions, render succeeds

---

### E2E-CAP-05: Malformed Transcript Segments
**Priority**: High
**Type**: Error handling

**Preconditions**:
- Transcript with malformed segment (missing `start_ms`, wrong type)

**Steps**:
1. Create transcript with segment: `['end_ms' => 5000, 'text' => 'Missing start_ms']`
2. Dispatch job
3. Catch exception

**Assertions**:
| Check | Expected |
|-------|----------|
| Exception thrown | `ProcessMediaException` |
| Exception message | Contains `invalid_input` |
| No completed DerivedAsset | Query returns null or failed status |

---

### E2E-CAP-06: Idempotency with Captions
**Priority**: Critical
**Type**: Idempotency verification

**Preconditions**: Same as E2E-CAP-01

**Steps**:
1. Run E2E-CAP-01 once → capture `$render1` (DerivedAsset), `$captionKey1` (SRT key)
2. Dispatch same job again (same asset, candidate, profile)
3. Capture `$render2`

**Assertions**:
| Check | Expected |
|-------|----------|
| Same DerivedAsset returned | `$render1->id === $render2->id` |
| No new SRT file | SRT file count unchanged |
| Worker not re-invoked | Only one render in render_parameters history (or log check) |
| transcript_hash matches | Both renders have same transcript_hash |

---

### E2E-CAP-07: Transcript Change Triggers New Render
**Priority**: High
**Type**: Cache invalidation verification

**Preconditions**: Completed render from E2E-CAP-01 exists

**Steps**:
1. Run E2E-CAP-01 → capture `$render1`
2. Update transcript segments (modify text content)
3. Dispatch job again
4. Capture `$render2`

**Assertions**:
| Check | Expected |
|-------|----------|
| New DerivedAsset | `$render1->id !== $render2->id` |
| Two completed renders | `DerivedAsset::where(...)->where('render_status', 'completed')->count() === 2` |
| New SRT file | Different UUID in caption file key |
| New filter graph | May differ due to caption content |

---

## Validation Criteria

### Per-Test Success
Each test must:
- Complete within 120 seconds (FFmpeg timeout 300s, but test should be faster)
- Leave no orphaned MinIO objects (cleanup in `afterEach` if needed)
- Leave no orphaned temp files in `/tmp/renders/`

### Overall Suite Success
- All 7 tests pass
- No flaky failures (run 3x to verify)
- CI pipeline green

## Output Validation Details

### SRT File Validation
```php
$srtContent = Storage::disk('media')->get($srtKey);
// Expected format:
/*
1
00:00:00,000 --> 00:00:10,000
First caption segment

*/
expect($srtContent)->toMatch('/^1\n\d{2}:\d{2}:\d{2},\d{3} --> \d{2}:\d{2}:\d{2},\d{3}\nFirst caption segment\n\n$/');
```

### FFmpeg Filter Graph Validation
```php
$filterGraph = $render->render_parameters['filter_graph'];
expect($filterGraph)->toContain('subtitles=');
// Expected pattern: crop=...,scale=...,pad=...,fps=30,subtitles=<path>
```

### FFprobe Output Video Validation (Subtitle Stream)
```php
// Download output video from MinIO to temp file
$outputPath = Storage::disk('media')->path($render->storage_key);
// Or use temporary download
$tempVideo = tempnam(sys_get_temp_dir(), 'e2e_output_');
Storage::disk('media')->get($render->storage_key, file_put_contents($tempVideo, ...));

// Run FFprobe
$probe = json_decode(shell_exec("ffprobe -v quiet -print_format json -show_streams {$tempVideo}"), true);
$hasSubtitleStream = false;
foreach ($probe['streams'] as $stream) {
    if ($stream['codec_type'] === 'subtitle') {
        $hasSubtitleStream = true;
        break;
    }
}
expect($hasSubtitleStream)->toBeTrue();
```

## Test Data Management

### Database
- Uses `RefreshDatabase` trait — each test gets clean database
- Factories create all required models

### MinIO Storage
- Tests use real `media` disk (MinIO)
- `beforeEach`: Verify bucket exists
- `afterEach`: Clean up test objects (optional — bucket can be purged between runs)
- Object keys use project-scoped patterns — no cross-test contamination

### Temp Files
- Worker creates temp files in `/tmp/renders/{uuid}/`
- Worker cleans up on success/failure
- Test does not need to manage worker temp files

## CI Integration

### GitHub Actions Workflow
```yaml
# .github/workflows/test-e2e-caption.yml
jobs:
  e2e-caption-test:
    runs-on: ubuntu-latest
    services:
      minio:
        image: minio/minio:latest
        ports: ['9000:9000', '9001:9001']
        env:
          MINIO_ROOT_USER: minioadmin
          MINIO_ROOT_PASSWORD: minioadmin
        options: >-
          --health-cmd "curl -f http://localhost:9000/minio/health/live"
          --health-interval 10s --health-timeout 5s --health-retries 5
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_pgsql, redis
      - name: Install FFmpeg
        run: sudo apt-get update && sudo apt-get install -y ffmpeg
      - name: Verify subtitles filter
        run: ffmpeg -filters | grep subtitles
      - name: Start MinIO bucket
        run: |
          mc alias set local http://localhost:9000 minioadmin minioadmin
          mc mb local/testing --ignore-existing
      - name: Run E2E tests
        working-directory: ./apps/api
        run: php artisan test --filter=RenderMediaClipCaptionE2ETest
        env:
          MINIO_ENDPOINT: http://localhost:9000
          MINIO_ACCESS_KEY: minioadmin
          MINIO_SECRET_KEY: minioadmin
          MINIO_BUCKET: testing
```

## Risk Assessment

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| FFmpeg subtitles filter missing | Test fails completely | Low (verify in CI setup) | Pre-flight check in CI |
| MinIO unavailable | Test fails | Medium | Health check, retry logic |
| Test video fixture corrupt | Test fails | Low | Commit known-good fixture |
| Worker CLI not found | Test fails | Low | Verify `config('media.worker_command')` |
| Flaky FFmpeg timing | Intermittent failures | Medium | Generous timeouts, ±50ms tolerance |
| Cross-test MinIO pollution | False positives | Low | Unique project IDs per test (RefreshDatabase) |

## Test Execution Checklist

### Pre-Test
- [ ] MinIO running and accessible
- [ ] FFmpeg with `subtitles` filter verified
- [ ] Test video fixture exists and probeable
- [ ] Database migrations current
- [ ] Worker CLI command resolves

### During Test Run
- [ ] All 7 tests execute without timeout
- [ ] No PHP errors/warnings
- [ ] Assertions match expected values

### Post-Test
- [ ] All tests pass (green)
- [ ] Code style clean (Pint)
- [ ] Static analysis clean (PHPStan)
- [ ] No production code modified
- [ ] Evidence recorded in `evidence.md`

## Traceability Matrix

| Acceptance Criteria | Test Scenario | Validation Method |
|---------------------|---------------|-------------------|
| AC-01 Happy Path | E2E-CAP-01 | Full pipeline assertions |
| AC-02 No Transcript | E2E-CAP-02 | Absence assertions |
| AC-03 Transcript Not Completed | E2E-CAP-03 | Absence assertions |
| AC-04 Empty Projection | E2E-CAP-04 | Absence assertions |
| AC-05 Malformed Segments | E2E-CAP-05 | Exception assertion |
| AC-06 Idempotency | E2E-CAP-06 | Identity assertions |
| AC-07 Transcript Change | E2E-CAP-07 | New render assertions |

## Scope Verification

### In Scope (Test Plan Covers)
- [ ] Real MinIO storage read/write
- [ ] Real worker process invocation
- [ ] Real FFmpeg rendering with subtitles
- [ ] Real FFprobe validation
- [ ] Real Laravel persistence
- [ ] All 7 acceptance criteria scenarios
- [ ] Deterministic fixtures (no external APIs)

### Out of Scope (Not Tested)
- [ ] UI/E2E browser tests (Playwright)
- [ ] Multiple caption tracks
- [ ] Caption styling
- [ ] Real transcription (Whisper) — mocked via fixture
- [ ] Load testing
- [ ] Production deployment validation

---

## Tester Sign-Off

**Tester**: [To be assigned]
**Date**: [To be filled]

### Verification Results
| Scenario | Pass/Fail | Notes |
|----------|-----------|-------|
| E2E-CAP-01 | PENDING | |
| E2E-CAP-02 | PENDING | |
| E2E-CAP-03 | PENDING | |
| E2E-CAP-04 | PENDING | |
| E2E-CAP-05 | PENDING | |
| E2E-CAP-06 | PENDING | |
| E2E-CAP-07 | PENDING | |

### Overall Decision
- [ ] **APPROVED** — All scenarios pass, evidence complete
- [ ] **REJECTED** — Defects found, return to Builder

### Defects (if rejected)
[To be filled by Tester]