# M6.2 Slice 3E: E2E Integration — Caption Flow End-to-End Test

## Issue
**#81E** — test(captions): implement end-to-end caption flow integration test

## Branch
`@carlosegoulart/81E/test/caption-flow-e2e`

## Summary
Implement end-to-end integration tests for the complete M6.2 caption flow pipeline: upload → transcribe → detect scenes → recommend → render with captions → validate output → persist. Uses real infrastructure (MinIO, FFmpeg, Python worker, FFprobe) with no mocks for storage, worker, or FFmpeg. Covers 7 E2E scenarios: happy path, no transcript, pending transcript, empty projection, malformed segments, idempotency, and transcript change triggering new render.

## Architecture Context
- **Slice 1 (PR #78)**: Worker supports optional `caption_file` in `render_clip` contract; FFmpeg burns captions via `subtitles=` filter.
- **Slice 2 (PR #80)**: Pure Python `project_segments_to_clip_local()` in worker.
- **Slice 3A (PR #83)**: Laravel `CaptionProjection::project()`.
- **Slice 3B (PR #85)**: Laravel `SrtGenerator::generate()`.
- **Slice 3C (PR #88)**: `StorageKeyBuilder::captionFile()` + `MediaProcessingContract::renderClipRequest()` caption_file + worker schema.
- **Slice 3D (PR #90)**: `RenderMediaClip` job wires the caption flow.
- **Slice 3E (This Spec)**: E2E integration tests validating the complete caption flow pipeline with real infrastructure.

## Scope

### In Scope
1. **E2E test suite**: Tests exercising the full caption flow pipeline end-to-end.
2. **7 E2E scenarios**:
   - TP-01: Happy path (transcript → captions burned in output)
   - TP-02: No transcript (render without captions)
   - TP-03: Pending transcript (render without captions, graceful degradation)
   - TP-04: Empty projection (no in-range segments → no caption_file)
   - TP-05: Malformed segments (graceful handling)
   - TP-06: Idempotency (equivalent re-dispatch reuses completed render)
   - TP-07: Transcript change triggers new render
3. **Real infrastructure**: MinIO (storage), FFmpeg, Python worker, FFprobe — no mocks for storage, worker, or FFmpeg.
4. **Output validation**: Verify captions present/absent in rendered output, video duration, FFmpeg filter_graph.

### Out of Scope
- Worker rendering logic changes (Slice 1 complete)
- Laravel job/business logic changes (Slices 3A-3D complete)
- Database schema changes
- API endpoints
- UI changes
- Unit tests for individual services (covered in Slices 3A-3D)
- Image generation (future capability)

## Data Flow

```
Upload media → MediaAsset created
        │
        ▼
Transcribe (worker) → MediaTranscript (completed, segments)
        │
        ▼
Detect scenes → SceneAnalysis
        │
        ▼
Recommend clips → ClipAnalysis + Recommendation
        │
        ▼
RenderMediaClip job:
  fetch transcript → CaptionProjection → SrtGenerator → StorageKeyBuilder
  → MediaProcessingContract (caption_file) → Worker (FFmpeg subtitles)
        │
        ▼
Rendered clip with captions → validate output → persist DerivedAsset
```

## Acceptance Criteria

### AC-01: Happy Path
- Complete pipeline with completed transcript produces rendered clip with captions burned in.
- Output video duration matches clip duration (±50ms).
- Worker filter_graph includes `subtitles=` filter when caption_file provided.

### AC-02: No Transcript
- Render without transcript produces clip without captions.
- `caption_file` omitted from worker contract.
- Output video renders successfully without captions.

### AC-03: Pending Transcript
- Transcript with status != completed → render without captions.
- Graceful degradation, no error.

### AC-04: Empty Projection
- Transcript exists but no segments overlap clip range → no caption_file.
- Render completes without captions.

### AC-05: Malformed Segments
- Malformed segment data handled gracefully without crashing.
- Render completes (captions may be omitted for malformed entries).

### AC-06: Idempotency
- Equivalent re-dispatch (same media/candidate/profile/transcript) reuses completed DerivedAsset.
- No worker call on second dispatch.
- Same DerivedAsset ID returned.

### AC-07: Transcript Change
- Transcript segments changed after initial render → new render triggered.
- New DerivedAsset created, different ID.

## Security Considerations
- E2E tests use test fixtures only; no real user content or secrets.
- Test infrastructure (MinIO, worker) isolated from production.
- No tokens/credentials in test fixtures.

## UX Considerations
- None — this is a test-only slice; no user-facing interface changes.

## Test Scenarios

| ID | Scenario | Infrastructure | Expected |
|----|----------|----------------|----------|
| TP-01 | Happy path (captions) | MinIO + FFmpeg + worker + FFprobe | Captions in output, duration match |
| TP-02 | No transcript | MinIO + FFmpeg + worker | No captions, renders OK |
| TP-03 | Pending transcript | MinIO + FFmpeg + worker | No captions, graceful |
| TP-04 | Empty projection | MinIO + FFmpeg + worker | No caption_file, renders OK |
| TP-05 | Malformed segments | MinIO + FFmpeg + worker | Graceful, no crash |
| TP-06 | Idempotency | MinIO + worker | Reuse, no 2nd worker call |
| TP-07 | Transcript change | MinIO + worker | New render, new DerivedAsset |

## Dependencies
- Slices 3A–3D (PRs #83, #85, #88, #90) — **COMPLETED/MERGED**
- Test infrastructure: MinIO, FFmpeg, Python worker, FFprobe available in CI
- Laravel Pest/PHPUnit for E2E test structure

## Human-Gated Operations
- None required (test-only changes, no production infrastructure changes)
