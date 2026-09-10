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
/health independent of AuthProvider.

# Important Decisions

One active implementation issue at a time. TDD RED before implementation.
Independent Tester review. English-only content. Laravel is the authoritative
backend; heavy media/ML work stays out of HTTP request processes. Health check
route at /health (no auth provider). Frontend uses React 19 StrictMode-safe
auth hooks with generation-based race-condition protection.

# Known Limitations

E2E needs prepared PostgreSQL, installed Chromium browsers, and free ports
5173/8000. No media, AI, or social features exist yet. Email verification
not implemented.

# Current Milestone

M1 Application Foundation — Issue #28 COMPLETE. PR #29 merged.
Next: M2 media processing pipeline.

# Next Architectural Goal

Media processing pipeline: upload, transcoding, scene detection, clip analysis.
