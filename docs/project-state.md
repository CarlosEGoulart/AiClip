# Current Architecture

Laravel 13 API, React 19 + Vite 8 frontend, and PostgreSQL 16, verified by a
health endpoint (`GET /api/v1/health`) with a real database check. Authentication
via Laravel Sanctum SPA session/cookie mode with CSRF protection. Media storage
via S3-compatible backend (MinIO in development, AWS S3 in production).
Laravel's `ProcessMediaAsset` queue job invokes `ProcessMediaAction`, which
runs the Python media CLI as a subprocess. Laravel validates worker results
and owns PostgreSQL persistence; Python does not consume the Laravel queue.

# Completed Capabilities

M0 governance foundation (Issues #1–#15). M1 foundation: health API/page, Pest
backend suite, Vitest frontend suite with mutation-proven assertions,
Playwright-managed E2E at three viewports, and backend/frontend/E2E/governance
CI. Sanctum SPA auth (Issue #28): register, login, logout, session persistence,
CSRF protection, database sessions, password hashing, rate limiting. Auth UI
styled per Taste Skill and Vercel Web Design Guidelines. Health check route at
/health independent of AuthProvider. Authenticated project management
(Issue #30): session-owned projects with name and optional description;
list/create/show/delete API, non-owner 404 responses, rate-limited creation,
and frontend list/create/delete with confirmation. Final verification is in
`specs/030-project-management/evidence.md`. M2 media storage foundation
(Issue #35): project-scoped video upload and S3-compatible storage with
user_id/project_id/uuid key structure, MediaAsset model, upload/list/delete
API with rate limiting, frontend media upload and list with delete confirmation,
MinIO integration tests, and full E2E coverage with mocked storage. Final
verification is in `specs/035-media-storage/evidence.md`.
M3: queued processing, FFprobe probing and FFmpeg audio extraction.
M4: transcription, scene detection, contract hardening and deterministic clip
candidate analysis (Issue #58 / PR #59 merged and closed). Candidate metadata
uses `scene_timing_baseline` v1.0.0 with timing-based scores/ranks, not AI
recommendation. Scene analysis is independent of transcription failure;
no-audio and extraction-failure workflows retain scene-only analysis.
M5: model-backed semantic clip recommendation (Issue #64 / PR #66 merged and
closed): the worker `rank_clips` v1.0.0 stage adds model-backed semantic clip
ranking under Laravel authority and persistence, keeping deterministic
`scene_timing_baseline` M4 scoring separate. PR #65 was superseded without
merge, closed unmerged, and remains as history.

# Important Decisions

One active implementation issue at a time. TDD RED before implementation.
Independent Tester review. English-only content. Laravel is the authoritative
backend; heavy media/ML work stays out of HTTP request processes. Health check
route at /health (no auth provider). Frontend uses React 19 StrictMode-safe
auth hooks with generation-based race-condition protection. Storage uses
`filter_var(..., FILTER_VALIDATE_BOOLEAN)` for boolean casting in PHPUnit.
CSRF exceptions for media upload routes.

M7 Slice 1 — Clip Review Experience foundation (Issue #106, PR #107) was merged manually via GitHub UI (commit 4c65c80), not via `scripts/merge_gate.py` as required by AGENTS.md §4.1. This governance exception is recorded here; all future PRs must use the deterministic merge gate.

M6.1 baseline vertical clip rendering (`ffmpeg_vertical_baseline` v1.0.0,
profile `vertical_v1`) is the first M6 slice; it is explicitly invoked via
dedicated render job, not an automatic stage in `ProcessMediaAsset`. Render
output is a persisted `DerivedAsset` with full metadata and status tracking.

M6.2 Slices 3A–3E — Caption Flow Integration (Issues #81, #81B, #87, #89, #101 / PRs #83, #85, #88, #90, #103 — all merged and closed):
- **Slice 3A (Issue #81 / PR #83)**: `CaptionProjection` — pure PHP segment projection matching Slice 2 algorithm; `SrtGenerator` — SRT format generation from projected segments.
- **Slice 3B (Issue #81B / PR #85)**: Dedicated `SrtGenerator` implementation with 6 unit tests covering single/multiple segments, empty text, multi-line text, timestamp format, and empty input.
- **Slice 3C (Issue #87 / PR #88)**: `StorageKeyBuilder::captionFile()` for deterministic S3 key generation; `MediaProcessingContract::renderClipRequest()` extended with optional `caption_file` parameter; worker schema `media_processing_v1.json` updated with `caption_file` in `render_clip_request`.
- **Slice 3D (Issue #89 / PR #90)**: `RenderMediaClip` job integrates caption flow — fetches `STATUS_COMPLETED` transcripts, projects segments, generates SRT, stores caption file, includes `caption_file` in contract only when projection yields content. Backward compatible: caption_file omitted when no transcript, status not completed, or empty projection.
- **Slice 3E (Issue #101 / PR #103)**: End-to-end caption flow integration test — validates complete pipeline: upload → transcribe → detect scenes → recommend → render with captions → validate output → persist. Uses real MinIO, FFmpeg, Python worker, FFprobe. No mocks for storage, worker, or FFmpeg.

M7 Slice 1 decisions (Issue #106, PR #107): clip-review video playback
issues time-limited (1 hour) signed API URLs that redirect to presigned
storage URLs; invalid, expired, or tampered signatures return 403 before
any ownership check, unsigned requests fall back to ownership 401/404, and
non-owners always receive 404 so no ownership information leaks. Review
list endpoints return the standard Laravel pagination envelope. The
frontend is a single-page shell with no client-side router library;
deep-link state uses the native History API.

# Known Limitations

E2E needs prepared PostgreSQL, installed Chromium browsers, and free ports
5173/8000. Email verification not implemented. No project update/edit endpoint
yet (out of scope for #30). Real runtime transcription model requires the
faster-whisper optional dependency and model download. Real runtime scene
detection requires the scenedetect[opencv-headless] optional dependency.
Mandatory CI contains deterministic worker/unit coverage, mocked PySceneDetect
adapter coverage, AND real FFmpeg-generated video + real PySceneDetect
integration coverage. The signed clip-review video redirect requires S3-compatible storage; the
local filesystem driver fails closed with 500. Full approve/reject/
video-workflow e2e coverage is deferred until rendered-clip fixtures can
exist in the e2e environment; the reachable review states are covered
instead. Social features do not exist yet. Semantic
clip ranking ships in the mainline via `rank_clips` v1.0.0 while
`scene_timing_baseline` v1.0.0 remains the deterministic M4 scoring; there is
no recommendation UI or public recommendation API. The agent
runtime does not allow local execution of `tests/governance/pr_enforcement.py`
(it runs in Actions) or deletion of generated `__pycache__` files; generated
caches must never be staged.

# Current Milestone

M0–M5 completed. Issue #64 (M5 first slice, model-backed semantic clip
recommendation) is closed as completed following the merge of PR #66. PR #65
was superseded without merge, closed unmerged, and remains as history with
both Issue #64 branches preserved. M6.1 — Durable Baseline Vertical Clip
Render Pipeline (Issue #69, PR #73) is completed and merged. M6.1 delivers
`ffmpeg_vertical_baseline` v1.0.0 render profile `vertical_v1` producing
persisted `DerivedAsset` records with `type=clip_rendered`, candidate index,
render timestamps, and error capture.

M6.2 Slices 3A–3E — Caption Flow Integration (Issues #81, #81B, #87, #89, #101 / PRs #83, #85, #88, #90, #103 — all merged and closed):
- **Slice 3A (Issue #81 / PR #83)**: `CaptionProjection` — pure PHP segment projection matching Slice 2 algorithm; `SrtGenerator` — SRT format generation from projected segments.
- **Slice 3B (Issue #81B / PR #85)**: Dedicated `SrtGenerator` implementation with 6 unit tests covering single/multiple segments, empty text, multi-line text, timestamp format, and empty input.
- **Slice 3C (Issue #87 / PR #88)**: `StorageKeyBuilder::captionFile()` for deterministic S3 key generation; `MediaProcessingContract::renderClipRequest()` extended with optional `caption_file` parameter; worker schema `media_processing_v1.json` updated with `caption_file` in `render_clip_request`.
- **Slice 3D (Issue #89 / PR #90)**: `RenderMediaClip` job integrates caption flow — fetches `STATUS_COMPLETED` transcripts, projects segments, generates SRT, stores caption file, includes `caption_file` in contract only when projection yields content. Backward compatible: caption_file omitted when no transcript, status not completed, or empty projection.
- **Slice 3E (Issue #101 / PR #103)**: End-to-end caption flow integration test — validates complete pipeline: upload → transcribe → detect scenes → recommend → render with captions → validate output → persist. Uses real MinIO, FFmpeg, Python worker, FFprobe. No mocks for storage, worker, or FFmpeg.

REC-04 (Issue #94): E2E caption flow integration test gap resolved; Slice 3E implemented and tested.

REC-05 (Issue #95): Governance automation audit completed; GOV-01 through GOV-04 follow-up issues defined.

M7 Slice 1 — Clip Review Experience foundation (Issue #106, PR #107):
ClipReview model with status enum (pending, approved, rejected,
needs_changes), review API (project-scoped paginated list, show, status
update with idempotent transitions, feedback submission, dual-gate signed
video URL), `ProcessClipReview` queue job with event dispatch, automatic
ClipReview creation when a rendered DerivedAsset completes, and per-endpoint
rate limiting. Frontend delivers the embedded review experience inside
ProjectMediaSection: VideoPlayer, ReviewActions, FeedbackForm, ReviewList,
and ReviewPage with native History API URL sync (no client-side router
library), a root React Query provider, 44px touch targets, and visible
focus indicators. Verification: backend Pest suites, a `real-integration`
group executing real MinIO presigned round trips and a real-database
rollback case, Vitest component suites, and Playwright e2e covering the
reachable review states at three viewports.

M7 Slice 2 — Clip Review Status Workflow (Issue #110, PR #111, merged via `scripts/merge_gate.py`):
One-way status transition contract (pending → approved/rejected/needs_changes;
terminal states reject all further transitions with 422). `PATCH
/api/v1/clip-reviews/{clipReview}/status` terminal-state guard.
Test-seeding route `POST /api/v1/test/clip-reviews/seed` (testing
environment only) that persists a real media object at
`DerivedAsset.storage_key` so the signed `video_url` resolves. Frontend
`ReviewActions` confirmation dialogs with disabled/loading states and
`aria-live="polite"` announcements; `ReviewPage` status reflection after
PATCH (badge update, refetch, deep-link consistency); feedback form for
rejected/needs_changes only. Playwright E2E approve/reject/needs_changes
happy paths plus terminal-state no-further-transition at 390x844 /
768x1024 / 1440x900 with console/network cleanliness. Backend: 87 tests,
81 passed, 6 skipped (MinIO real-integration), 0 failures. Frontend: 78
Vitest passed, 0 failed. Governance: 173 tests OK. E2E CI green at all
three viewports.

M8 Slice 1 — AI Image Studio Foundation (Issue #113, PR #114, merged via
`scripts/merge_gate.py`): `ImageGenerationProvider` contract with a
deterministic `FakeImageGenerationProvider` (container-bound; no model
download), `StorageKeyBuilder::imageGeneration()` project-scoped keys,
`Image` model with status enum (pending/generated/failed) plus migration and
factory, `GenerateImage` queue job following the `RenderMediaClip` topology
(ownership re-validation, non-pending idempotency, strict result validation,
transactional generated/failed marking; inference never inside HTTP request
processes), and the API triad `POST /api/v1/images/generate` (202
enqueue-only with 422/404/401/419/429 contracts and a 20/min rate limiter),
`GET /api/v1/images` (paginated, project filter), `GET /api/v1/images/{image}`,
with a strict 11-field ImageResource whitelist (no storage internals).
Frontend adds the image-studio feature (`useGenerateImage`/`useImages` hooks,
prompt form with live counter, style and dimension presets, gallery with
status badges, loading/empty/error states) and the Image Studio tab in
ProjectMediaSection, styled with semantic project CSS (44px touch targets,
visible focus indicators, one column below 1024px, side-by-side above).
Verification: backend issue suites 84 tests/349 assertions, ImageStudio
feature suites 49/49, Vitest 303/303, lint/build clean, Playwright e2e
generate flow plus responsive/touch-target/focus at 390x844/768x1024/1440x900
with console/network cleanliness, governance 173 tests OK.

# Next Architectural Goal

M8 — AI Image Studio continues beyond Slice 1: remaining slices (a real
model-backed image generation provider behind the `ImageGenerationProvider`
seam, presigned download URLs, image deletion, batch generation, image
editing) require separate explicit authorization and are not active. M7
remaining slices (approval-driven downstream actions, editor integrations,
publishing hooks) and M6.2+ enhancements (multi-candidate batch rendering,
render profiles beyond `vertical_v1`) likewise remain future work, not
active, and not implemented. A standalone queue-consuming Python service
remains a future architectural evolution, not the current CLI execution
topology.
