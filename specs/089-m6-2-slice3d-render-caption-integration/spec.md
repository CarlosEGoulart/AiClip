# Spec: Issue #89 — Caption Flow Integration into RenderMediaClip (M6.2 Slice 3D)

## Issue
[#89](https://github.com/CarlosEGoulart/AiClip/issues/89) — feat(render): integrate caption flow into RenderMediaClip job (M6.2 Slice 3D)

## Branch
`@carlosegoulart/89/feat/render-caption-integration`

## Summary
Wire the completed caption pipeline (Slices 3A, 3B, 3C) into the `RenderMediaClip` job. When a `MediaTranscript` with `STATUS_COMPLETED` exists for the source media asset, the job projects the transcript segments to the clip's local time coordinates, generates an SRT file, stores it via `StorageKeyBuilder::captionFile()`, and includes the `caption_file` reference in the `renderClipRequest()` contract sent to the worker for burn-in via FFmpeg `subtitles=` filter. Absence of a usable transcript must not alter existing behavior — the `caption_file` key is simply omitted.

## Architecture Context
- Slice 1 (PR #78): Worker burns optional `caption_file` via FFmpeg `subtitles=` filter.
- Slice 2 (PR #80): Python `project_segments_to_clip_local()`.
- Slice 3A: `CaptionProjection::project()` — pure PHP projection.
- Slice 3B: `SrtGenerator::generate()` — pure PHP SRT generation.
- Slice 3C (Issue #87): `StorageKeyBuilder::captionFile()`, `MediaProcessingContract::renderClipRequest()` caption params, worker schema `caption_file`.
- Slice 3D (this issue): `RenderMediaClip` integration — fetch transcript, project, generate, store, pass to worker.
- Slice 3E (next): End-to-end integration test.

## Scope

### In Scope
1. **Transcript fetching**: In `RenderMediaClip::handle()`, query `MediaTranscript::where('media_asset_id', $asset->id)->where('status', MediaTranscript::STATUS_COMPLETED)->first()`.
2. **Caption parameters**: If transcript exists and is completed, pass its `segments`, candidate `start_ms` as `clipStartMs`, candidate `end_ms` as `clipEndMs`, and source media `disk` to `MediaProcessingContract::renderClipRequest()`.
3. **Backward compatibility**: If no transcript, or transcript status != `completed`, or projection yields empty segments, the `caption_file` key is omitted from the contract — existing behavior preserved.
4. **Idempotency**: Re-dispatching the job for the same asset/candidate/profile should produce a new caption file (fresh UUID) since SRT content depends on the candidate timing.
5. **Tests**: New feature tests in `RenderMediaClipTest.php` covering caption paths; existing tests must not regress.

### Out of Scope
- Worker FFmpeg rendering logic (Slice 1/PR #78).
- Database schema / migrations.
- API endpoints / controllers.
- Frontend / UI.
- End-to-end integration test (Slice 3E).
- Multiple caption tracks / languages.
- Caption styling customization.

## Acceptance Criteria

- [ ] AC-01: When completed transcript with in-range segments exists, `caption_file` is present in the render contract sent to worker.
- [ ] AC-02: When no transcript exists, `caption_file` is absent from render contract (backward compatible).
- [ ] AC-03: When transcript status != `completed` (pending/transcribing/failed), `caption_file` is absent.
- [ ] AC-04: When transcript exists but all segments fall outside clip range (empty projection), `caption_file` is absent.
- [ ] AC-05: When transcript segments are malformed, job fails with `ProcessMediaException` (invalid_input).
- [ ] AC-06: All existing `RenderMediaClipTest.php` tests pass (zero regression).
- [ ] AC-07: New tests cover the caption integration paths.

## Security Considerations
- Transcript content is user data; stored with same disk/permissions as media.
- Caption file path uses UUID, no user-controlled path segments.
- Worker receives only storage path, no credentials.

## UX Considerations
- None — internal job logic.

## Test Scenarios

### TC-RMJ-CAP-01: Happy path — completed transcript with in-range segments
- Setup: MediaAsset with completed MediaTranscript, segments overlapping candidate
- Expect: `caption_file` in render contract; SRT stored on disk; worker receives caption_file

### TC-RMJ-CAP-02: No transcript record
- Setup: MediaAsset with no MediaTranscript
- Expect: `caption_file` absent; render succeeds without captions

### TC-RMJ-CAP-03: Transcript status not completed
- Setup: MediaTranscript with status = pending/transcribing/failed
- Expect: `caption_file` absent; render succeeds without captions

### TC-RMJ-CAP-04: Empty projection — all segments outside clip range
- Setup: Completed transcript but segments entirely before clipStart or after clipEnd
- Expect: `caption_file` absent; render succeeds without captions

### TC-RMJ-CAP-05: Malformed transcript segments
- Setup: Completed transcript with invalid segment structure (missing start_ms/end_ms/text)
- Expect: Job fails with ProcessMediaException (invalid_input)

### TC-RMJ-CAP-06: Idempotency — re-dispatch creates new caption file
- Setup: Dispatch job twice for same asset/candidate/profile
- Expect: Each dispatch generates distinct caption file (new UUID); both renders succeed

### TC-RMJ-CAP-07: Regression — existing tests pass
- All pre-existing tests in RenderMediaClipTest.php continue to pass

## Dependencies
- Slice 3A (`CaptionProjection`), Slice 3B (`SrtGenerator`), Slice 3C (Issue #87) — all merged.
- Laravel Storage facade, `MediaTranscript` model.

## Human-Gated Operations
- None.