# Test Plan: Authenticated Project Ownership and CRUD

## Scope and Evidence Rules

This clarifies the backend project CRUD, frontend rendering/submission/error, and E2E project lifecycle scenarios for existing issue #30. IDs below are acceptance/evidence references, not claims that tests have run or passed. Retain historical evidence and append actual commands and outcomes; missing historical RED remains a documented gap.

## Backend: Pest, PostgreSQL, Real Session and Middleware Boundaries

### B1 — Project creation and listing

- Test POST /api/v1/projects with valid name and description returns 201 with project data
- Test GET /api/v1/projects returns list of authenticated user's projects only
- Test projects are ordered by created_at descending
- Test description field is included in response when provided
- Test description field is null when not provided
- Verify user_id is not exposed in response

### B2 — Project viewing and deletion

- Test GET /api/v1/projects/{project} returns project when owner
- Test GET /api/v1/projects/{project} returns 404 when not owner
- Test DELETE /api/v1/projects/{project} returns 204 when owner
- Test DELETE /api/v1/projects/{project} returns 404 when not owner
- Test deleted project no longer appears in list

### B3 — Authentication and authorization

- Test all project endpoints return 401 when unauthenticated
- Test authenticated user cannot access other user's projects
- Test user_id in request payload is ignored (not overridden)
- Verify CSRF protection with token retry
- Verify cookie-based auth only (no bearer tokens)

### B4 — Validation

- Test name is required (422 when missing)
- Test name must be string (422 when array)
- Test name max 255 characters (422 when exceeded)
- Test description must be string when provided (422 when array)
- Test description max 1000 characters (422 when exceeded)
- Test validation errors have correct field names and messages

### B5 — Rate limiting

- Test project creation rate limiting (throttle:api middleware)
- Test rate limit headers are included in responses
- Test rate limiting returns 429 when exceeded

## Frontend: Vitest and React Testing Library

### F1 — API boundary

- Test getProjects(), createProject(), deleteProject() URLs and methods
- Test CSRF handling with retry on 419
- Test error classification: 401→unauthorized, 422→validation, network→error
- Test no Authorization bearer header used
- Test no localStorage/sessionStorage token usage

### F2 — Hook and component state

- Test useProjects initial state (projects: [], loading: false, error: null)
- Test fetchProjects loading/success/error states
- Test createProject loading/success/error/validation states
- Test deleteProject loading/success/error states
- Test optimistic list updates (prepend on create, filter on delete)

### F3 — Form and interaction

- Test CreateProjectForm renders name and description fields
- Test form submission with valid data
- Test form clears on successful creation
- Test validation errors display for invalid fields
- Test form is disabled during submission
- Test ARIA attributes: aria-invalid, aria-describedby, aria-busy

### F4 — Project display

- Test ProjectCard renders name, description, created date
- Test delete button triggers confirmation dialog
- Test confirmation dialog has proper ARIA attributes
- Test cancel button closes dialog without deleting
- Test confirm button deletes project

### F5 — List states

- Test ProjectList shows loading state (role="status")
- Test ProjectList shows empty state when no projects
- Test ProjectList renders ProjectCard for each project
- Test project count is accurate

### F6 — Auth integration

- Test projects load after authentication
- Test projects clear after logout
- Test no auth test regressions (5 previously failing tests must pass)

## Playwright and Independent Browser Review

### E1 — Real authenticated lifecycle

- Run projects.spec.ts with real Laravel, migrated PostgreSQL, database sessions
- Test authenticated user sees project form
- Test user creates project after login
- Test project persists after reload
- Test user deletes own project
- Test validation errors for empty name
- Test multiple projects listed

### E2 — Browser errors and description field

- Test project creation with description field
- Test description displays on project card
- Test no console errors during project operations
- Test no failed network requests
- Test no unexpected redirects

### E3 — Responsive and accessibility

- Test at 390x844 viewport
- Test at 768x1024 viewport
- Test at 1440x900 viewport
- Test keyboard navigation
- Test focus visibility
- Test screen reader compatibility

### E4 — Visual and accessibility gate

- Capture screenshots at all three viewports
- Review layout/overflow, spacing/alignment, typography/contrast
- Review responsive controls, focus visibility
- Review keyboard order and submission
- Review labels/error associations
- Review navigation, disabled states, stable loading
- Review understandable errors and success states
- Record design-skill/guidelines findings
- Record console/network/API review per viewport

## Execution and Handoff

Builder runs targeted new tests before fixes, then the applicable complete checks:

| Working directory | Command / check |
| --- | --- |
| `apps/api` | `php artisan test --compact --filter=Project` |
| `apps/api` | `php artisan test --compact` |
| `apps/api` | `vendor/bin/pint --dirty --format agent` followed by affected reruns if formatting changes files |
| `apps/web` | `npm test` |
| `apps/web` | `npm run lint` |
| `apps/web` | `npm run build` |
| `apps/web` | `npm run test:e2e` (all three projects, including projects) |
| Repository root | Existing governance check command from repository/CI tooling; record the exact invocation |

Record actual environment preparation, commands, exits, named failing/passing assertions and counts in `evidence.md`, mapping them to B1-E4. Infrastructure or fixture failures are blockers, not RED. Immediately passing added tests are regression evidence; preserve unsupported historical RED/approval claims as history with explicit clarification rather than inventing proof.

Builder hands off evidence and remaining gaps to Orchestrator. Orchestrator reconciles project state and invokes an independent Tester role session. Tester reruns applicable checks, reviews the actual diff and running app, and records an explicit APPROVE/REJECT with artifacts and any unresolved TDD/design/tooling gaps. A different model is preferred where selectable; record unavailable model selection honestly. Rejected implementation goes back through Orchestrator to Builder. This plan does not authorize lifecycle operations or another issue.