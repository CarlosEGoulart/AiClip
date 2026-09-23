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

# Important Decisions

One active implementation issue at a time. TDD RED before implementation.
Independent Tester review. English-only content. Laravel is the authoritative
backend; heavy media/ML work stays out of HTTP request processes. Health check
route at /health (no auth provider). Frontend uses React 19 StrictMode-safe
auth hooks with generation-based race-condition protection. Storage uses
`filter_var(..., FILTER_VALIDATE_BOOLEAN)` for boolean casting in PHPUnit.
CSRF exceptions for media upload routes.

# Known Limitations

E2E needs prepared PostgreSQL, installed Chromium browsers, and free ports
5173/8000. Email verification not implemented. No project update/edit endpoint
yet (out of scope for #30). Real runtime transcription model requires the
faster-whisper optional dependency and model download. Real runtime scene
detection requires the scenedetect[opencv-headless] optional dependency.
Mandatory CI contains deterministic worker/unit coverage, mocked PySceneDetect
adapter coverage, AND real FFmpeg-generated video + real PySceneDetect
integration coverage. Semantic clip analysis/ranking, rendering, and social
features do not exist yet.

# Current Milestone

M0–M4 foundations and M4 corrective closeout completed. Issue #60 is closed
as completed following the merge of PR #61, covering PostgreSQL concurrency,
failure boundaries, empty-result completion and documentation. Its evidence
remains separate from the merged #58 implementation artifacts.

# Next Architectural Goal

M5 AI Clip Recommendation is next: model-backed recommendation and semantic
relevance, future, not active and not implemented. A standalone queue-consuming
Python service is also a future architectural evolution, not the current CLI
execution topology.
After this documentation lifecycle is merged and closed, return to
NO_ACTIVE_ISSUE and stop. Starting M5 requires separate explicit authorization.
