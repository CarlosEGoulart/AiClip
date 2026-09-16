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
yet (out of scope for #30). No transcoding, scene detection, transcription,
clip analysis, AI ranking, rendering, or social features exist yet.

# Current Milestone

M3 Asynchronous Media Processing (in progress):
- First slice: async processing job boundary (Issue #45).
- Second slice: deterministic FFprobe media probing worker (Issue #47).
- Laravel remains authoritative for PostgreSQL state.
- Python worker does not write PostgreSQL directly.
- Versioned worker contract established.
- Deterministic processing lifecycle states defined.
- Worker subprocess invocation via Symfony Process.
- Probe result and duration columns on MediaAsset.

# Next Architectural Goal

M3 continued: deterministic audio extraction worker stage — FFmpeg audio
extraction to normalized mono 16 kHz PCM WAV derivative, private object
storage, Laravel-controlled metadata/state. This derivative becomes the input
for future transcription (not yet implemented).
