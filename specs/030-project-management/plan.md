# Implementation Plan: Authenticated Project Ownership and CRUD

## Original Approach (Planning History)

The original sequence is retained below; it is not a completion record.

1. Create Project migration with description field
2. Create Project model with relationships
3. Create ProjectFactory with description
4. Create ProjectResource (without user_id)
5. Create StoreProjectRequest with description validation
6. Create ProjectController with index, store, show, destroy
7. Create frontend types, API functions, hooks
8. Create CreateProjectForm, ProjectCard, ProjectList components
9. Integrate projects into AuthenticatedShell
10. Add backend tests
11. Add frontend tests
12. Add E2E tests
13. Run all tests

## Active Clarification: Issue #30 Only

Strengthen backend and frontend tests to address external review defects, then complete design review, SDD evidence, project-state reconciliation, and independent Tester review.

### Observed Starting Point

- Project implementation exists but has defects identified in external review:
  1. Missing description field end-to-end
  2. Exposed user_id in ProjectResource
  3. Out-of-scope UpdateProjectRequest and PUT endpoint
  4. Backend test infrastructure issues (csrfCookies/spaRequest not available)
  5. Frontend regressions (5 auth tests failing)
  6. Missing rate limiting for project creation
  7. Invalid APPROVE in evidence.md
- PR #31 is open with CI failures
- Evidence.md contains APPROVE without proper verification

## Ordered Remaining Work

All items below are pending verification, even where production behavior exists.

### 1. Builder: Fix Backend Defects

- [ ] Add description field to migration (text, nullable)
- [ ] Add description to Project model $fillable array
- [ ] Add description validation to StoreProjectRequest: nullable|string|max:1000
- [ ] Remove UpdateProjectRequest and PUT endpoint (out of scope)
- [ ] Remove user_id from ProjectResource public fields
- [ ] Add rate limiting for project creation (throttle:api middleware)
- [ ] Fix test infrastructure: ensure csrfCookies/spaRequest are available
- [ ] Update backend tests to include description field testing

### 2. Builder: Fix Frontend Defects

- [ ] Add description field to CreateProjectForm
- [ ] Add description display to ProjectCard
- [ ] Add description to TypeScript types
- [ ] Fix 5 failing auth tests (likely caused by project integration)
- [ ] Ensure no auth state regressions

### 3. Builder: Strengthen Tests

- [ ] Backend: Add tests for description field validation
- [ ] Backend: Add tests for rate limiting
- [ ] Backend: Fix test infrastructure issues
- [ ] Frontend: Fix auth test regressions
- [ ] Frontend: Add description field tests
- [ ] E2E: Add description field scenarios

### 4. Builder: Design Skills and Guidelines

- [ ] Before remaining UI changes, use `design-taste-frontend`
- [ ] Review resulting UI with `web-design-guidelines`
- [ ] Record skill source/version, concrete findings, and resolutions
- [ ] Check hierarchy, spacing, contrast, labels, focus, keyboard use, responsive layout

### 5. Builder: Evidence and Project State

- [ ] Update evidence.md with corrected implementation details
- [ ] Remove invalid APPROVE statement
- [ ] Add actual test execution results
- [ ] Map acceptance criteria to test results
- [ ] Document resolved defects and remaining gaps

### 6. Orchestrator: Independent Tester Handoff

- [ ] Invoke separate Tester role session after Builder hands off
- [ ] Tester independently inspects the diff and implementation
- [ ] Tester reruns applicable suites
- [ ] Tester interacts with running application at all three viewports
- [ ] Tester reviews screenshots, accessibility, API bodies, console, network activity
- [ ] Tester records APPROVE or REJECT with observed results

## Scope and Dependencies

In scope: Project CRUD with description field, rate limiting, test fixes, design review, evidence correction.

Out of scope: Project editing (update endpoint), project archiving, media upload, unrelated redesign, new issues.

Execution requires PostgreSQL with migrated projects table, PHP/Composer and frontend dependencies, Chromium, free ports 5173/8000, and matching session/Sanctum origin settings. Required design skills and independent role-session tooling must be available to the executing roles or reported as blockers.