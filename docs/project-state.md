# Current Architecture

Laravel 13 API, React 19 + Vite 8 frontend, and PostgreSQL 16, verified by a
health endpoint (`GET /api/v1/health`) with a real database check. Authentication
via Laravel Sanctum SPA session/cookie mode with CSRF protection. Media storage
via S3-compatible backend (MinIO in development, AWS S3 in production).

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

M4 Video Understanding (in progress):
- Deterministic transcription worker stage (Issue #51) — MERGED.
- Deterministic scene detection worker stage (Issue #53) — MERGED (PR #55).
- Post-merge contract hardening for scene detection (Issue #56) — IN PROGRESS.
- Transcription engine abstraction (DeterministicTranscriber for CI,
  FasterWhisperTranscriber for runtime).
- Scene detection engine abstraction (DeterministicSceneDetector for CI,
  PySceneDetectAdapter for runtime).
- MediaTranscript model with retryable lifecycle
  (pending → transcribing → completed/failed → transcribing).
- MediaSceneAnalysis model with retryable lifecycle
  (pending → detecting → completed/failed).
- Segment validation and transcription timeout enforcement.
- ProcessMediaAsset chains: probe → scene detection → audio extraction → transcription.
- Scene failure does not block transcription; no-audio video still receives scene detection.
- Laravel response validation for malformed worker output.

# Next Architectural Goal

M4 Video Understanding — next narrow slice: clip ranking/analysis.
Determine which segments are most interesting for clip extraction.
