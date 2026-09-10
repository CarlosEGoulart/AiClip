# Current Architecture

Laravel 13 API, React 19 + Vite 8 frontend, and PostgreSQL 16, verified by a
health endpoint (`GET /api/v1/health`) with a real database check.

# Completed Capabilities

M0 governance foundation (Issues #1–#15). M1 foundation: health API/page, Pest
backend suite, Vitest frontend suite with mutation-proven assertions,
Playwright-managed E2E at three viewports, and backend/frontend/E2E/governance
CI.

# Important Decisions

One active implementation issue. TDD RED before implementation. Independent
Tester review. English-only content. Laravel is the authoritative backend;
heavy media/ML work stays out of HTTP request processes.

# Known Limitations

E2E needs prepared PostgreSQL, installed Chromium browsers, and free ports
5173/8000. No authentication, media, AI, or social features exist yet.

# Current Milestone

M1 Application Foundation (in progress). Next slice: authentication and user
management.

# Next Architectural Goal

Authentication and user management (Sanctum SPA, registration, login).
