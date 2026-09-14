# Current Architecture

Laravel 13 API, React 19 + Vite 8 frontend, and PostgreSQL 16, verified by a
health endpoint (`GET /api/v1/health`) with a real database check. Authentication
via Laravel Sanctum SPA session/cookie mode with CSRF protection.

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
`specs/030-project-management/evidence.md`.

# Important Decisions

One active implementation issue at a time. TDD RED before implementation.
Independent Tester review. English-only content. Laravel is the authoritative
backend; heavy media/ML work stays out of HTTP request processes. Health check
route at /health (no auth provider). Frontend uses React 19 StrictMode-safe
auth hooks with generation-based race-condition protection.

# Known Limitations

E2E needs prepared PostgreSQL, installed Chromium browsers, and free ports
5173/8000. No media, AI, or social features exist yet. Email verification
not implemented. No project update/edit endpoint yet (out of scope for #30).

# Current Milestone

M1 Application Foundation:
- Sanctum SPA authentication complete (Issue #28, PR #29 merged).
- Authenticated Project ownership/management complete.
- Issue #30 complete; PR #31 merged.

# Next Architectural Goal

M2 Media Storage: establish project-scoped media upload and storage before
transcoding, scene detection, clip analysis, AI ranking, rendering, or social
publishing. Starting M2 requires separate explicit authorization; this
maintenance cycle does not authorize implementation.
