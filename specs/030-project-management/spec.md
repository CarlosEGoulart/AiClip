# Specification: Authenticated Project Ownership and CRUD

## Issue

GitHub Issue #30: feat(projects): add authenticated project ownership and CRUD

## Goal

Implement first-party project ownership with CRUD operations, ensuring authenticated users can create, list, view, and delete their own projects.

## API Contract

- GET /api/v1/projects
- POST /api/v1/projects
- GET /api/v1/projects/{project}
- DELETE /api/v1/projects/{project}

All endpoints require `auth:sanctum` middleware.

## Data Model

### Project Table

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | bigint (unsigned) | PK, auto-increment | |
| user_id | bigint (unsigned) | FK → users.id, cascade delete | Internal ownership |
| name | varchar(255) | required | |
| description | text | nullable | |
| created_at | timestamp | | |
| updated_at | timestamp | | |

### Public Resource Fields

- id
- name
- description
- created_at
- updated_at

**Explicitly excluded:** user_id

## Validation Rules

### Create Project

- name: required|string|max:255
- description: nullable|string|max:1000

### (Update endpoint explicitly out of scope)

## Acceptance Criteria

1. Authenticated user can create a project with name and description
2. Authenticated user can list their own projects
3. Authenticated user can view a specific project they own
4. Authenticated user can delete their own project after confirmation
5. Unauthenticated access returns 401
6. Other user's project returns 404 (not 403)
7. Validation rejects invalid data with appropriate error messages
8. Frontend shows loading/empty/error states
9. Delete requires confirmation dialog
10. Responsive at 390x844, 768x1024, 1440x900
11. Keyboard accessible
12. Unit tests pass
13. Feature/API tests pass
14. E2E tests pass

## Out of Scope

- Project editing (update endpoint)
- Project archiving
- Media upload
- Project description validation beyond max length

## Security Considerations

- All routes behind `auth:sanctum`
- Ownership enforced server-side via scoped queries
- Non-owners get 404 (not 403) to hide project existence
- user_id cannot be overridden in request payload
- CSRF protection with token retry
- Cookie-based auth only (consistent SPA pattern)
- Cascade delete on user removal

## UX Considerations

- Create form with name (required) and description (optional)
- Project card displays name, description, created date
- Delete with confirmation dialog
- Loading/empty/error states
- Responsive design following existing patterns
- Accessible form elements with proper ARIA attributes

## Dependencies

- Laravel Sanctum authentication (Issue #28)
- PostgreSQL database
- React frontend with TypeScript
- Playwright E2E testing infrastructure