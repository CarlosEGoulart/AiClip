# Implementation Plan: Sanctum SPA Authentication

## Original Approach (Planning History)

The original sequence is retained below; it is not a completion record.

1. Install and configure Laravel Sanctum
2. Create auth routes, controllers, form requests
3. Create React auth API layer
4. Create AuthProvider and useAuth hook
5. Build login/register UI
6. Add backend tests
7. Run all tests

## Active Clarification: Issue #28 Only

Strengthen backend authentication tests first, then complete frontend coverage,
real auth E2E, design review, SDD evidence, project-state reconciliation, and
independent Tester review. The acceptance details in `test-plan.md` operationalize
`spec.md` and the explicitly requested remaining work for this active issue.

### Observed Starting Point

- Auth implementation and tests already exist, with worktree edits in progress.
- `apps/api/phpunit.xml` selects array sessions. Existing `actingAs`, cached-guard,
  and in-process session assertions do not prove database-backed cookie roundtrips;
  a zero-row session assertion under the array driver is insufficient.
- Laravel's testing-mode CSRF bypass must be addressed explicitly in security tests.
- The current login policy is five failed credential attempts per lowercased
  email + client IP, with a 300-second decay and successful-login reset.
- `evidence.md` contains historical counts and an APPROVE statement without the
  commands, failing assertions, browser artifacts, or independent review needed
  to verify this strengthened gate. These claims are not new verification.
- `docs/project-state.md` still says authentication does not exist. Reconcile it
  against verified results and pending work through Orchestrator.

## Ordered Remaining Work

All items below are pending verification, even where production behavior exists.

### 1. Builder: Backend Proof Before Further UI Work

- [ ] Extend `apps/api/tests/Feature/Auth/AuthenticationTest.php` or focused sibling
  Pest files using the B1–B5 scenarios in `test-plan.md`.
- [ ] Use PostgreSQL and explicitly select the database session driver before
  resolving session services. Preserve persisted rows between requests while
  refreshing request/guard/session resolution. Carry only real response cookies
  into each next request; include a fresh cookie-less control client.
- [ ] Prove registration/login persist an authenticated session, rotate the guest
  session identifier, and allow a fresh `/me` request. Prove logout destroys the
  old authenticated session and replaying its saved cookie returns 401.
- [ ] Enable actual CSRF validation, including disabling only the test-environment
  bypass through a test-only harness. Demonstrate missing/mismatched tokens fail
  and real cookie/header pairs succeed; keep application middleware enabled.
- [ ] Cover validation boundaries, exact sanitized response fields, generic
  credential errors, and throttle boundary/expiry/key isolation/reset.
- [ ] Run new assertions before fixes. Record genuine behavior failures, fix only
  #28 defects, then run targeted and complete backend suites and Pint.

### 2. Builder: Frontend Vitest and React Testing Library

- [ ] Add behavior-focused API tests near `apps/web/src/features/auth/api/`,
  provider tests near `hooks/`, and form/shell/App integration tests. Use fetch
  doubles at the HTTP boundary for API tests and controlled promises for loading.
- [ ] Cover F1–F3: credentialed requests, CSRF setup and failure handling, a maximum
  of one 419 refresh/retry, validation, 401/429/500/network states, bootstrap,
  registration/login/logout transitions, accessible fields and error recovery.
- [ ] Exercise representative provider + form + API paths together so invented
  mocked error types cannot hide mismatches with Laravel's actual 422 responses.
- [ ] Demonstrate pending-state duplicate-submit prevention and truthful failed
  logout behavior. Confirm storage is never used for authentication tokens.
- [ ] Record test-first failures for newly discovered defects, minimal fixes,
  GREEN, and any refactor rerun. Existing passing behavior is regression coverage.

### 3. Builder: Design Skills and Guidelines

- [ ] Before remaining auth UI changes, use `design-taste-frontend`; review the
  resulting auth UI with `web-design-guidelines`. Record skill source/version,
  concrete findings, and resolutions in evidence.
- [ ] If required skills are unavailable, report that limitation to Orchestrator
  for access or explicit resolution; do not claim skill usage. Apply the root
  AGENTS.md visual/accessibility criteria while the requirement remains pending.
- [ ] Limit design corrections to auth forms, authenticated shell, and their
  integration with the existing health page. Check hierarchy, spacing, contrast,
  labels, focus, keyboard use, responsive layout, and all interaction states.

### 4. Builder: Real Playwright Auth and Health Regression Gate

- [ ] Add `apps/web/e2e/auth.spec.ts` using existing managed Laravel/Vite servers
  and all three configured projects. Prepare isolated PostgreSQL fixtures and
  database sessions; use unique accounts per test/project and cleanup.
- [ ] Execute E1–E4 against real register/login/logout/me/CSRF endpoints. Prove
  reload restoration and logout persistence with HTTP results as well as UI.
- [ ] Inspect request headers, cookies, sanitized responses, and storage throughout
  the flow. Keep credentials/session-cookie values out of committed artifacts.
- [ ] Keep health success/loading/rejection/503 and diagnostic regression assertions
  meaningful. Expected guest `/me` 401s need exact scenario-specific allowances;
  filtering away unrelated console/network failures is not a passing global gate.
- [ ] Capture screenshots and console/network review records at 390x844, 768x1024,
  and 1440x900. Controlled failure fixtures are only for explicit error scenarios,
  not substitutes for the real auth success path.

### 5. Builder and Orchestrator: Evidence and Project State

- [ ] Append a dated clarification/superseding verification section to `evidence.md`
  preserving historical statements. Identify which historical claims lack
  supporting artifacts; do not reconstruct RED or independent approval.
- [ ] Map B/F/E acceptance IDs to test names, commands, environment prerequisites,
  exit codes, results, screenshots, and actual reviewer findings. Record new RED
  only for observed missing-behavior failures before fixes. Tests first added
  after implementation that pass immediately are regression evidence, not RED.
- [ ] Record unresolved historical TDD gaps for Orchestrator disposition; artificial
  production breakage or mutation checks do not establish historical test-first RED.
- [ ] Run the checks listed in `test-plan.md`; document blockers and outstanding
  items honestly rather than carrying forward old counts as current results.
- [ ] Orchestrator updates `docs/project-state.md` within its existing six headings:
  current auth architecture, verified capabilities, cookie/session decisions,
  actual limitations, and #28's pending/verified state. No completion claim before
  required verification and no next-issue initiation.

### 6. Orchestrator: Independent Tester Handoff

- [ ] Invoke a separate Tester role session after Builder hands off changes and
  reproducible evidence. A general-agent role session is acceptable when that is
  the available mechanism; record that model selection is unavailable rather than
  claiming a different model. Independence requires a separate review session.
- [ ] Tester independently inspects the diff and B/F/E matrix, reruns applicable
  suites, interacts with the running application at all three viewports, and
  reviews screenshots, accessibility, API bodies, console, and network activity.
- [ ] Tester records APPROVE or REJECT with observed results and remaining gaps.
  Route defects through Orchestrator to Builder, then repeat affected review.
  Historical approval does not replace this gate.

## Scope and Dependencies

In scope: first-party registration/login/current-user/logout, Sanctum CSRF and
database sessions, their frontend UX, login throttling, relevant tests and evidence,
and preservation of the health workflow. Out of scope: password reset, email
verification workflows, OAuth, bearer/PAT authentication, roles, media/social
features, unrelated redesign, and new issues. Planner changes only these planning
documents; lifecycle operations remain Orchestrator responsibilities.

Execution requires PostgreSQL with migrated users/sessions tables, PHP/Composer and
frontend dependencies, Chromium, free ports 5173/8000, and matching session/Sanctum
origin settings. Required design skills and independent role-session tooling must
be available to the executing roles or reported as blockers.
