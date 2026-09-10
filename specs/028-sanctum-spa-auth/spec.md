# Specification: Sanctum SPA Registration and Session Authentication

## Issue

GitHub Issue #28: feat(auth): implement Sanctum SPA registration and session authentication

## Goal

Implement first-party SPA authentication using Laravel Sanctum session/cookie authentication.

## API Contract

- POST /api/v1/auth/register
- POST /api/v1/auth/login
- POST /api/v1/auth/logout
- GET /api/v1/auth/me
- GET /sanctum/csrf-cookie

## Acceptance Criteria

- Sanctum configured for first-party SPA
- CSRF initialization works
- Registration persists user to PostgreSQL
- Password stored hashed
- Login establishes session
- Current user returns sanitized data
- Logout invalidates session
- No bearer token used
- No auth token in localStorage/sessionStorage
- All tests pass
