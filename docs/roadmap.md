# Roadmap

Milestones are planning boundaries. They do not authorize additional implementation issues. Only one implementation issue is active at a time.

## M0 — Governance Bootstrap (completed)

Completed slices:

- Governance bootstrap (Issue #1)
- Documentation formalization (Issues #3, #5)
- Governance enforcement automation (Issues #7, #9, #11, #13, #15)
- Repository baseline normalization (Issues #22, #24)

## M1 — Application Foundation (completed)

Completed slices:

- Application foundation: Laravel API, React frontend, PostgreSQL, health endpoint (Issue #17)
- Foundation stabilization: Vitest, Playwright lifecycle, mutation-proven tests (Issue #19)
- Sanctum SPA authentication (Issue #28, PR #29)
- Authenticated project management (Issue #30, PR #31)

## M2 — Media Storage (completed)

Completed slices:

- Project-scoped video upload and S3-compatible storage (Issue #35, PR #36)

## M3 — Asynchronous Media Processing (completed)

Completed slices:

- Async processing job boundary (Issue #45)
- Deterministic FFprobe media probing worker (Issue #47)
- Deterministic FFmpeg audio extraction worker (Issue #49)

## M4 — Video Understanding (completed)

Completed slices:

- Deterministic transcription worker stage (Issue #51)
- Deterministic scene detection worker stage (Issue #53, PR #55)
- Scene detection contract hardening (Issue #56, PR #57 — merged)

- Deterministic clip candidate analysis (Issue #58, PR #59 — merged; issue closed)

M4 supplies validated candidate metadata, deterministic timing-based scores/ranks,
and persistence/lifecycle infrastructure. The `scene_timing_baseline` algorithm
is deterministic, not AI recommendation. Issue #60 is closed as completed
following the merge of PR #61, completing M4's corrective closeout for
concurrency, failure boundaries, validation and documentation. Its verification
evidence remains separate and is not attributed retrospectively to #58.
M5 AI Clip Recommendation is completed: its first slice, Issue #64, added
model-backed semantic clip ranking (`rank_clips` v1.0.0) and shipped in the
mainline via the merge of PR #66; the issue is closed as completed. PR #65 was
superseded without merge, closed unmerged, and remains as history with both
Issue #64 branches preserved. M4 retains deterministic candidate analysis
(`scene_timing_baseline` v1.0.0, timing-based scores/ranks, not AI);
`rank_clips` is model-backed semantic ranking, separate from M4 scoring.
Further M5 slices and M6 require separate explicit authorization.

## M5 — AI Clip Recommendation (completed)

Completed slices:

- Model-backed semantic clip recommendation stage (Issue #64, PR #66 — merged; issue closed; PR #65 superseded and closed unmerged)

## M6 — Vertical Clip Rendering (M6.1 completed; M6.2 Slices 3A–3D completed)

Completed slices:

- Durable baseline vertical clip render pipeline (Issue #69, PR #73 — merged; issue closed): `ffmpeg_vertical_baseline` v1.0.0, render profile `vertical_v1`, dedicated `RenderMediaClip` queue job, `DerivedAsset` persistence with `type=clip_rendered`, explicit candidate selection, render status/error/timestamp tracking. No automatic render stage in `ProcessMediaAsset`. Captions, batch rendering, and additional profiles are out of scope for M6.1.

- **Slice 3A (Issue #81, PR #83 — merged; issue closed)**: Caption projection (`CaptionProjection`) and SRT generation (`SrtGenerator`) services. Pure PHP, deterministic, integer-only functions matching Slice 2 algorithm and SRT specification. 18 unit tests (12 CaptionProjection + 6 SrtGenerator).

- **Slice 3B (Issue #81B, PR #85 — merged; issue closed)**: Dedicated `SrtGenerator` implementation extracted from Slice 3A scope for atomic delivery. 6 unit tests covering all SRT format requirements.

- **Slice 3C (Issue #87, PR #88 — merged; issue closed)**: `StorageKeyBuilder::captionFile()` for deterministic S3 key generation; `MediaProcessingContract::renderClipRequest()` extended with optional `caption_file`; worker schema `media_processing_v1.json` updated with `caption_file` in `render_clip_request`. 15 new unit tests, zero regression in 986 existing tests.

- **Slice 3D (Issue #89, PR #90 — merged; issue closed)**: `RenderMediaClip` job integrates caption flow — fetches completed transcripts, projects segments via `CaptionProjection`, generates SRT via `SrtGenerator`, stores via `StorageKeyBuilder::captionFile()`, includes `caption_file` in worker contract only when projection non-empty. 6 new integration tests, zero regression in 14 existing RenderMediaClip tests.

Planned slices (not authorized, not implemented):

- **Slice 3E**: End-to-end caption flow integration test (full pipeline: upload → transcribe → detect scenes → recommend → render with captions).

## Planned Milestones

| Milestone | Description |
|-----------|-------------|
| M6 | Vertical Clip Rendering (M6.1 baseline complete; M6.2 Slices 3A–3D completed) |
| M7 | Clip Review Experience |
| M8 | AI Image Studio |
| M9 | Social Connection Framework |
| M10 | YouTube Publishing |
| M11 | Instagram Publishing |
| M12 | TikTok Publishing |
| M14 | Unified One-Click Publishing |
| M14 | Production Hardening |

Only one implementation issue is active at a time.