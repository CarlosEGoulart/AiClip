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

## M6 — Vertical Clip Rendering (M6.1 completed)

Completed slices:

- Durable baseline vertical clip render pipeline (Issue #69, PR #73 — merged; issue closed): `ffmpeg_vertical_baseline` v1.0.0, render profile `vertical_v1`, dedicated `RenderMediaClip` queue job, `DerivedAsset` persistence with `type=clip_rendered`, explicit candidate selection, render status/error/timestamp tracking. No automatic render stage in `ProcessMediaAsset`. Captions, batch rendering, and additional profiles are out of scope for M6.1.

## Planned Milestones

| Milestone | Description |
|-----------|-------------|
| M6 | Vertical Clip Rendering (M6.1 baseline complete; M6.2+ planned) |
| M7 | Clip Review Experience |
| M8 | AI Image Studio |
| M9 | Social Connection Framework |
| M10 | YouTube Publishing |
| M11 | Instagram Publishing |
| M12 | TikTok Publishing |
| M13 | Unified One-Click Publishing |
| M14 | Production Hardening |

Only one implementation issue is active at a time.
