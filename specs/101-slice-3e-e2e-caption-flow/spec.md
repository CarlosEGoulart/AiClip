# M6.2 Slice 3E: End-to-End Caption Flow Integration Test

## Issue
**#101** — test(e2e): end-to-end caption flow integration test (M6.2 Slice 3E)

## Branch
`@carlosegoulart/101/test/e2e-caption-flow`

## Summary
Implement a real end-to-end integration test that exercises the complete caption flow pipeline:
```
Laravel → Storage (caption SRT) → Worker contract → FFmpeg subtitles filter → Output video → FFprobe validation → Laravel persistence
```

This validates the complete caption integration built in M6.2 Slices 3A–3D (all merged). No E2E test exists yet — this is the gap documented in REC-04 (Issue #94).

## Architecture Context
- **Slice 3A (PR #83)**: `CaptionProjection` — pure PHP segment projection matching Slice 2 algorithm
- **Slice 3B (PR #85)**: `SrtGenerator` — SRT format generation from projected segments
- **Slice 3C (PR #88)**: `StorageKeyBuilder::captionFile()`, `MediaProcessingContract::renderClipRequest()` extended with `caption_file`, worker schema `media_processing_v1.json` updated
- **Slice 3D (PR #90)**: `RenderMediaClip` job integrates caption flow — fetches `STATUS_COMPLETED` transcripts, projects segments, generates SRT, stores caption file, includes `caption_file` in contract only when projection yields content
- **Slice 3E (This Spec)**: Real E2E integration test — **no mocks for storage, worker, or FFmpeg**

## Scope

### In Scope
1. **E2E test infrastructure**: Create a test that uses real MinIO storage, real worker process (Python CLI), real FFmpeg, real FFprobe
2. **Deterministic test fixtures**: Pre-recorded video fixture, pre-computed transcript fixture with known segments
3. **Complete pipeline test**: Upload → Transcribe (mock) → Analyze → Recommend → Render with captions → Validate output → Persist DerivedAsset
4. **Caption-specific assertions**:
   - SRT file generated and stored on MinIO at correct key
   - `caption_file` included in worker contract
   - FFmpeg filter graph contains `subtitles=` filter
   - Output video has burned-in captions (validated via FFprobe subtitle stream)
   - `DerivedAsset` persisted with correct metadata
5. **Edge case coverage**:
   - No transcript exists → renders without captions
   - Transcript status not completed → renders without captions
   - Transcript segments all outside clip range → renders without captions
   - Malformed transcript segments → job fails with `invalid_input`

### Out of Scope
- UI/E2E browser tests (no React component for render pipeline)
- Multiple caption tracks/languages
- Caption styling/customization
- Load/stress testing
- Real transcription model (use deterministic transcript fixture)

## Data Flow

```
Test Setup
    │
    ▼
Create Project + MediaAsset (upload test video to MinIO)
    │
    ▼
Create MediaTranscript with known segments (STATUS_COMPLETED)
    │
    ▼
Create MediaSceneAnalysis (completed)
    │
    ▼
Create MediaClipAnalysis (completed)
    │
    ▼
Create MediaClipRecommendation (completed, ranked)
    │
    ▼
Dispatch RenderMediaClip job (real queue, real ProcessMediaAction)
    │
    ▼
RenderMediaClip.handle():
  ├── Fetch MediaTranscript
  ├── Project segments to clip-local (CaptionProjection)
  ├── Generate SRT (SrtGenerator)
  ├── Store SRT on MinIO (StorageKeyBuilder::captionFile)
  ├── Build render_clip contract with caption_file
  └── Invoke ProcessMediaAction.renderClips()
         │
         ▼
    Python worker CLI (render-clip)
         │
         ▼
    FFmpegVerticalClipRenderer.render_singular(caption_file=...)
         │
         ▼
    FFmpeg: -vf "...subtitles=caption_file" → output.mp4
         │
         ▼
    FFprobe validates output
         │
         ▼
    Worker returns result with request_sha256, filter_graph
         │
         ▼
    Laravel RenderValidator validates result
         │
         ▼
    DerivedAsset persisted with render_status=completed
```

## Acceptance Criteria

### AC-01: Happy Path — Caption Flow Complete
- Given: MediaAsset with completed MediaTranscript having segments overlapping candidate clip range
- When: RenderMediaClip job executes via real queue/worker
- Then:
  - SRT file is stored on MinIO at `projects/{project_id}/captions/{media_asset_id}/{candidate_index}/{render_profile_version}/{uuid}.srt`
  - Worker contract includes `caption_file` key with the SRT storage key
  - FFmpeg filter graph in worker result contains `subtitles=` filter referencing the caption file
  - Output video has a subtitle stream (FFprobe shows `codec_type: subtitle`)
  - DerivedAsset created with `render_status=completed`, `render_profile_version=vertical_v1`, correct dimensions/duration

### AC-02: No Transcript
- Given: MediaAsset with NO MediaTranscript record
- When: RenderMediaClip job executes
- Then:
  - No SRT file is generated or stored
  - Worker contract omits `caption_file` key entirely
  - FFmpeg filter graph does NOT contain `subtitles=` filter
  - DerivedAsset created successfully without captions

### AC-03: Transcript Status Not Completed
- Given: MediaTranscript exists but status != `STATUS_COMPLETED` (e.g., `pending`, `failed`)
- When: RenderMediaClip job executes
- Then:
  - No SRT file is generated or stored
  - Worker contract omits `caption_file` key
  - Render succeeds without captions

### AC-04: Empty Projection (Segments Outside Clip Range)
- Given: Completed MediaTranscript but all segments fall outside candidate clip time range
- When: RenderMediaClip job executes
- Then:
  - No SRT file is generated (projection yields empty)
  - Worker contract omits `caption_file` key
  - Render succeeds without captions

### AC-05: Malformed Transcript Segments
- Given: Completed MediaTranscript with malformed segments (missing start_ms/end_ms/text, wrong types)
- When: RenderMediaClip job executes
- Then:
  - Job fails with `ProcessMediaException` message containing `invalid_input`
  - No DerivedAsset with `render_status=completed` is created

### AC-06: Idempotency with Captions
- Given: RenderMediaClip dispatched twice for same asset/candidate/profile with same transcript
- When: Second dispatch executes
- Then:
  - First dispatch: generates caption file, calls worker, creates DerivedAsset
  - Second dispatch: reuses existing DerivedAsset, no worker call, no new caption file
  - Both return same DerivedAsset ID

### AC-07: Transcript Change Triggers New Render
- Given: Completed render exists, then transcript segments are modified
- When: RenderMediaClip dispatched again
- Then:
  - New caption file generated (different UUID in path)
  - Worker called again
  - New DerivedAsset created (different ID from first)

## Security Considerations
- SRT files contain transcript text (user content) — stored with same disk/permissions as source media
- No secrets in SRT files or worker contract
- Worker receives only storage path — no credentials
- Path traversal prevented: SRT key generated via `StorageKeyBuilder` with UUID, no user input

## UX Considerations
- None — this is an internal integration test

## Test Scenarios

| ID | Scenario | Description |
|----|----------|-------------|
| E2E-CAP-01 | Happy path | Complete caption flow with real MinIO, worker, FFmpeg |
| E2E-CAP-02 | No transcript | Asset without transcript renders without captions |
| E2E-CAP-03 | Transcript pending | Transcript status != completed skips captions |
| E2E-CAP-04 | Empty projection | All segments outside clip range → no captions |
| E2E-CAP-05 | Malformed segments | Invalid segment structure → job fails |
| E2E-CAP-06 | Idempotency | Re-dispatch reuses render with same transcript hash |
| E2E-CAP-07 | Transcript change | Modified transcript triggers new render |

## Dependencies
- Slices 3A–3D merged (Issues #81, #81B, #87, #89)
- REC-01 (Issue #91) — Render completion validation — **COMPLETED**
- REC-02 (Issue #92) — Idempotency semantics — **COMPLETED**
- Worker `request_sha256` canonicalization with `caption_file` — **IMPLEMENTED**
- MinIO available in test environment
- FFmpeg with `subtitles` filter support (libass)
- Test video fixture with known duration/resolution
- Deterministic transcript fixture

## Human-Gated Operations
- None required (no schema changes, no external API changes)

---

# Evidence: Issue #101 — End-to-End Caption Flow Integration Test

## TDD: E2E_CAPTION_FLOW

### RED Phase
- [ ] **E2E test class created**: `tests/Feature/Jobs/RenderMediaClipCaptionE2ETest.php` — all tests fail (infrastructure not implemented)
- [ ] **Test video fixture**: `tests/fixtures/caption_test_video.mp4` — exists and probeable
- [ ] **Transcript fixture**: Known segments matching test video timestamps

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
| Test Class | Tests | Pass | Fail | Notes |
|------------|-------|------|------|-------|
| RenderMediaClipCaptionE2ETest | 7 | 0 | 0 | PENDING |

## Scope Verification

### In Scope (Implemented)
- [ ] `tests/Feature/Jobs/RenderMediaClipCaptionE2ETest.php` — 7 E2E test cases
- [ ] `tests/fixtures/caption_test_video.mp4` — test video fixture
- [ ] Transcript fixture data (inline or file)
- [ ] CI configuration for MinIO + FFmpeg in GitHub Actions

### Out of Scope (Not Modified)
- [ ] Production code (CaptionProjection, SrtGenerator, StorageKeyBuilder, RenderMediaClip, etc.)
- [ ] Database schema
- [ ] API endpoints
- [ ] Frontend/UI
- [ ] Worker rendering logic

## Tester Decision
Decision: PENDING

### Justification
[To be filled by Tester after independent validation]

## CI
Status: PENDING

GitHub Actions workflow run: [URL]

Required checks:
- [ ] `php artisan test --filter=RenderMediaClipCaptionE2ETest` — PENDING
- [ ] `vendor/bin/pint --dirty --format agent` — PENDING
- [ ] `phpstan analyse --level=5` — PENDING

## Lifecycle State
`NO_ACTIVE_ISSUE` → `ISSUE_CREATED` → `BRANCH_CREATED` → `SPEC_READY` → `RED_VERIFIED` → `GREEN_VERIFIED` → `TESTER_APPROVED` → `PR_OPEN` → `CI_GREEN` → `MERGE_GATE_READY` → `MERGED` → `ISSUE_CLOSED` → `NO_ACTIVE_ISSUE`

Current: `SPEC_READY`