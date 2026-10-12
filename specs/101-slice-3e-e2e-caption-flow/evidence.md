# Evidence: Issue #101 — End-to-End Caption Flow Integration Test

## TDD: E2E_CAPTION_FLOW

### RED Phase
- [x] **E2E test class created**: `tests/Feature/Jobs/RenderMediaClipCaptionE2ETest.php` — all 7 tests created (currently skipped due to infrastructure)
- [ ] **Test video fixture**: `tests/fixtures/caption_test_video.mp4` — placeholder created, needs real 30s 1920x1080 h264/aac video
- [x] **Transcript fixture**: Known segments matching test video timestamps (inline in test helpers)
- [x] **CI Configuration**: `.github/workflows/test-e2e-caption.yml` created with MinIO, FFmpeg, Python worker setup

### GREEN Phase
- [ ] **E2E test infrastructure**: MinIO setup, worker process invocation, FFmpeg/FFprobe available
- [ ] **E2E-CAP-01**: Happy path test passes — full pipeline executes and validates
- [ ] **E2E-CAP-02**: No transcript test passes
- [ ] **E2E-CAP-03**: Transcript pending test passes
- [ ] **E2E-CAP-04**: Empty projection test passes
- [ ] **E2E-CAP-05**: Malformed segments test passes
- [ ] **E2E-CAP-06**: Idempotency test passes
- [ ] **E2E-CAP-07**: Transcript change test passes

### REFACTOR Phase
- [ ] Code style: `vendor/bin/pint --dirty --format agent` — clean
- [ ] Static analysis: `phpstan analyse --level=5` — clean
- [ ] Test isolation: no shared state between test cases

## Test Results

### E2E Tests (Pest/PHPUnit)
| Test Class | Tests | Pass | Fail | Skipped | Notes |
|------------|-------|------|------|---------|-------|
| RenderMediaClipCaptionE2ETest | 7 | 0 | 0 | 7 | All skipped - infrastructure not available |

### Current Status
- **Test Class**: Created at `apps/api/tests/Feature/Jobs/RenderMediaClipCaptionE2ETest.php`
- **Test Video Fixture**: Placeholder at `apps/api/tests/fixtures/caption_test_video.mp4` (needs real video)
- **CI Workflow**: Created at `.github/workflows/test-e2e-caption.yml`
- **Infrastructure Needed**: MinIO, FFmpeg with subtitles filter, Python worker package

### Next Steps to Reach GREEN
1. Start MinIO: `docker-compose up -d minio minio-init`
2. Generate real test video fixture (30s, 1920x1080, h264/aac)
3. Install Python worker: `cd services/worker && pip install -e .[dev,transcription,scene_detection]`
4. Run tests: `cd apps/api && php artisan test --filter=RenderMediaClipCaptionE2ETest`

### Implementation Details

#### Test Class: `RenderMediaClipCaptionE2ETest.php`
Location: `apps/api/tests/Feature/Jobs/RenderMediaClipCaptionE2ETest.php`

Test cases implemented:
1. **E2E-CAP-01** (`completes full caption flow with real MinIO, worker, and FFmpeg`): Validates complete pipeline including SRT generation, worker contract with caption_file, FFmpeg subtitles filter, FFprobe subtitle stream validation
2. **E2E-CAP-02** (`renders without captions when no transcript exists`): Validates graceful degradation when no transcript
3. **E2E-CAP-03** (`renders without captions when transcript status is not completed`): Validates graceful degradation for pending/failed transcripts
4. **E2E-CAP-04** (`renders without captions when all transcript segments are outside clip range`): Validates empty projection handling
5. **E2E-CAP-05** (`fails with invalid_input when transcript segments are malformed`): Validates error handling for malformed segments
6. **E2E-CAP-06** (`reuses existing render with same transcript (idempotent)`): Validates idempotency with captions
7. **E2E-CAP-07** (`creates new render when transcript segments change`): Validates cache invalidation on transcript change

#### Key Implementation Choices
- Uses REAL infrastructure: MinIO storage, Python worker process, FFmpeg with subtitles filter, FFprobe validation
- NO MOCKS for storage, worker, or FFmpeg (critical difference from existing tests)
- Uses `RefreshDatabase` for test isolation
- Real Laravel queue (sync driver for determinism)
- Validates SRT file content, filter graph, FFprobe subtitle stream
- Test video fixture must be committed to repo (under 10MB)

#### Files Created
1. `apps/api/tests/Feature/Jobs/RenderMediaClipCaptionE2ETest.php` — E2E test class (7 test cases)
2. `apps/api/tests/fixtures/caption_test_video.mp4` — Test video fixture (placeholder, needs real video)
3. `.github/workflows/test-e2e-caption.yml` — CI configuration for E2E tests

#### Files NOT Modified (per plan.md)
- Production code (`app/Services/`, `app/Jobs/`, `app/Contracts/`, `app/Models/`)
- Database migrations
- Worker code

## Scope Verification

### In Scope (Implemented)
- [x] `tests/Feature/Jobs/RenderMediaClipCaptionE2ETest.php` — 7 E2E test cases
- [x] `tests/fixtures/caption_test_video.mp4` — test video fixture (placeholder)
- [x] Transcript fixture data (inline in test helpers)
- [x] CI configuration for MinIO + FFmpeg in GitHub Actions

### Out of Scope (Not Modified)
- [x] Production code (CaptionProjection, SrtGenerator, StorageKeyBuilder, RenderMediaClip, etc.)
- [x] Database schema
- [x] API endpoints
- [x] Frontend/UI
- [x] Worker rendering logic

## Tester Decision
Decision: PENDING — Infrastructure setup required

### Justification
Tests are implemented but require:
1. MinIO running (docker-compose)
2. Real test video fixture generated (ffmpeg)
3. Python worker package installed
4. FFmpeg with subtitles filter (libass)

Once infrastructure is available, tests should execute and validate the complete caption flow pipeline.

## CI
Status: PENDING — Workflow created, needs infrastructure to run

GitHub Actions workflow: `.github/workflows/test-e2e-caption.yml`

Required checks:
- [ ] `php artisan test --filter=RenderMediaClipCaptionE2ETest` — PENDING
- [ ] `vendor/bin/pint --dirty --format agent` — PENDING
- [ ] `phpstan analyse --level=5` — PENDING

## Lifecycle State
`NO_ACTIVE_ISSUE` → `ISSUE_CREATED` → `BRANCH_CREATED` → `SPEC_READY` → `RED_VERIFIED` → `GREEN_VERIFIED` → `TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

Current: `RED_VERIFIED` (tests created and failing/skipped due to missing infrastructure)