# Test Plan: Sanctum SPA Authentication

## Scope and Evidence Rules

This clarifies the original backend registration/validation/login/me/logout,
frontend rendering/submission/error, and E2E registration/login/logout/reload
scenarios for existing issue #28. IDs below are acceptance/evidence references,
not claims that tests have run or passed. Retain historical evidence and append
actual commands and outcomes; missing historical RED remains a documented gap.

## Backend: Pest, PostgreSQL, Real Session and Middleware Boundaries

### B1 — Database-backed registration and login roundtrips

- Explicitly configure `session.driver=database` before session resolution;
  `phpunit.xml` currently defaults to `array`. Assert the effective driver and
  persisted authenticated session row linked to the expected user.
- Initialize `/sanctum/csrf-cookie` as a guest, capture its session/XSRF cookies,
  and submit registration with that cookie jar and valid XSRF header. Expect 201,
  persisted user, and `Hash::check(submittedPassword, storedHash)` true with no
  plaintext password stored. Repeat the authentication proof separately for login
  with a factory-created user and expect 200.
- Carry cookies from actual `Set-Cookie` responses into subsequent requests,
  respecting encryption/encoding (avoid test-client double encryption). Refresh
  guard/request/session resolution between requests without deleting database
  rows. Document the helper's isolation mechanism; `actingAs`, `withSession`,
  `assertAuthenticated`, or cached `/me` resolution alone cannot satisfy B1.
- Compare guest and authenticated session IDs on the server: registration and
  login rotate IDs; the old guest cookie cannot access `/me`. A fresh request
  with the new cookie returns 200 and the same user. A cookie-less independent
  client returns JSON 401, proving identity is not retained in shared guards.

### B2 — Logout invalidation and old-cookie replay

- Establish a real B1 login, save the authenticated cookie before logout, and
  assert its session row exists. POST logout with valid CSRF returns empty 204.
- Assert the specific old session is destroyed, rather than requiring the whole
  sessions table to be empty (a new guest session may legitimately be saved).
  Confirm session ID and CSRF token rotate.
- In fresh request contexts, `/me` with the resulting logout cookie jar returns
  401; replay the saved pre-logout authenticated cookie and require 401 again.
  No cached guard, logout-only UI assertion, or cookie deletion substitutes.
- Guest logout with a valid guest CSRF pair returns JSON 401; unauthenticated
  `/me` is JSON 401 without HTML redirects. Keep guest authorization separate
  from missing-CSRF rejection, which may return 419 first.

### B3 — CSRF is actually enforced

- Keep the complete session/cookie/CSRF middleware path enabled. Laravel bypasses
  CSRF in unit-test mode even with `withMiddleware()`: use a test-only mechanism
  that removes that bypass while retaining real token validation, or make real
  HTTP requests to a normally booted application. Record the effective mechanism.
- `/sanctum/csrf-cookie` returns 204, a readable XSRF cookie and an HttpOnly session
  cookie. Check configured SameSite/path and Secure behavior appropriate to the
  exercised HTTP/HTTPS environment; do not require Secure on local HTTP.
- Parameterize register/login/logout: matching session cookie plus missing header
  or mismatched header yields 419. A token from another guest session also fails.
  The same operation with the decoded matching `X-XSRF-TOKEN` succeeds (201/200/204).
- Assert rejected registration creates no user, rejected login grants no identity,
  and rejected logout leaves its authenticated session usable. These rejection
  assertions are the negative control proving the CSRF harness is active.

### B4 — Validation and sanitized responses

- Registration returns 422 with field errors for missing/blank/non-string name,
  name over 255 characters, missing/invalid/non-string/over-255 email, duplicate
  email, missing/non-string/password under eight characters, and missing or
  mismatched confirmation. Exercise accepted name/email length boundaries with
  valid fixtures and the minimum valid password. Rejections create no user or
  authenticated session.
- Login validates missing/blank/non-string email/password and malformed email.
  Wrong password and unknown email both return 422 with the same generic message
  and email-error content, and leave the fresh client unauthenticated.
- Registration, login, and persisted-session `/me` return exactly the public
  `user` fields: `id`, `name`, `email`, `email_verified_at`, with correct values and
  types. Exercise null and non-null verification timestamps. No password,
  remember token, access token, extra private model attributes, or debug data.
- Review success and error response bodies for secret/credential leakage. Never
  include submitted passwords in validation errors or evidence. No auth endpoint
  issues a bearer token; session cookies are the authentication mechanism.

### B5 — Login throttle boundary, expiry, isolation, and reset

Policy under test: five failed credential attempts per lowercased email + client
IP, a 300-second window beginning at the first failure, reset on successful login.
Use isolated limiter keys/cache fixtures and deterministic time travel; do not
wait five real minutes or let previous tests contaminate the results.

- Failures 1–5 return generic 422. Attempt 6 returns 429 even with valid
  credentials, provides understandable retry guidance, and creates no session.
- At 299 seconds after the first failure the key remains blocked; at/after 300
  seconds a valid login succeeds. Blocked requests must not extend the window.
- Same email with different casing and the same IP shares the limit. Different
  email at the same IP, and the same email at a different IP, remain independent.
  Exercise actual request IP inputs, not only the key-construction function.
- Four failures followed by success resets that key. After real logout, another
  five failures are allowed and the sixth blocks. A separately blocked key stays
  blocked when another key logs in successfully.

## Frontend: Vitest and React Testing Library

### F1 — API boundary

- Test register/login/logout/me URLs, methods, JSON payloads and Accept headers.
  Every auth/CSRF request includes cookies via `credentials: 'include'`; no
  Authorization bearer header or browser-storage token is produced/read.
- CSRF setup completes before state-changing requests. Read and URL-decode the
  exact `XSRF-TOKEN` cookie, not a similarly named cookie; include it as
  `X-XSRF-TOKEN` for POSTs. A failed setup must not send the mutation.
- Reject network failures and non-success statuses from initial CSRF setup and
  refresh as typed, recoverable errors. Auth-request network rejection is also a
  network error. Check 401/429/500 and non-JSON error bodies without parse crashes.
- On first auth 419, refresh CSRF once and retry the same operation once with the
  new token. Assert method/payload retained, at most two mutation attempts, and
  only one recovery refresh. A second 419 becomes a visible CSRF error; a failed
  refresh stops retrying. Other failure statuses must not trigger this retry.
- Verify 201/200 sanitized-user handling, empty 204 logout without JSON parsing,
  422 field errors (including Laravel's generic invalid-credentials email error),
  401 unauthorized, 429 throttle, and 500 server error mappings.

### F2 — Provider and application state

- Use controlled promises to observe initial checking-session state, `/me` 200
  restoration, and `/me` 401 as a normal guest with no alarming expired-session
  error on first visit. Bootstrap network/500 failures remain visible and
  recoverable rather than being indistinguishable from a confirmed guest.
- Register/login transitions show pending state, then sanitized authenticated
  identity; validation/network/419/401/429/500 failures end loading and display
  the appropriate errors. Clear obsolete errors when editing or retrying.
- Logout shows pending state; 204 clears identity and returns to guest. A 401
  after an established session clears stale identity and communicates expiration.
  Network/419-after-retry/429/500 logout failures do not falsely report successful
  logout: preserve identity, show the failure, and allow retry.
- Use at least one real provider + form + API-boundary test for 422 credentials
  and a transport failure. Tests must not merely supply a `credentials` error
  that the real API layer never emits. No storage-based auth persistence.

### F3 — Forms, shell, and interaction

- Submit labeled login/register fields by keyboard and verify exact data,
  including password confirmation. Verify required/type/autocomplete semantics,
  visible password-field validation, associated error descriptions, and
  `aria-invalid` for every rejected field.
- Hold requests pending: controls and submit actions are disabled, progress text
  is visible, and repeated click/Enter cannot create duplicate submissions.
- Exercise validation, generic invalid credentials, session expiration, throttle,
  CSRF exhaustion, network and server errors through user-visible alerts on the
  applicable form/shell, including registration transport errors. Editing/retry
  clears obsolete errors and successful resubmission reaches authenticated UI.
- Verify login/register navigation, identity display, logout success/failure, and
  retained access to the existing health UI with scoped accessible queries.

## Playwright and Independent Browser Review

### E1 — Real authenticated lifecycle

- Run `auth.spec.ts` with real Laravel, migrated PostgreSQL, database sessions,
  the Vite proxy, and normal CSRF enforcement. No route-fulfilled success
  responses, injected auth state, `actingAs`, or preloaded storage tokens.
- Fresh context: guest `/me` 401; register via UI (201); observe identity and real
  `/me` 200. Reload the page and verify identity is restored through `/me` 200.
- Logout via UI (204); verify guest UI, real `/me` 401, and another reload remains
  guest. Login again with that account (200), reload to authenticated state, then
  logout again. Use unique accounts for every project/repeat and clean fixtures.
- Save the pre-logout cookie in memory and replay it in an isolated client after
  logout; `/me` must return 401. Preserve the separate fresh cookie-less control.

### E2 — Browser errors and authentication transport

- Exercise real duplicate-registration and invalid-credential 422 responses with
  understandable field feedback. Test session expiration and logout recovery.
- Use explicitly labeled endpoint-scoped interception only for deterministic
  loading/network/419-retry-exhaustion/429/500 UI scenarios. At minimum verify
  pending submit lockout, recoverable login transport failure, and truthful logout
  failure. These tests supplement E1, not prove real CSRF or session persistence.
- Inspect actual auth/CSRF requests: cookies and XSRF header are used, Authorization
  bearer is absent. Inspect localStorage/sessionStorage after registration, login,
  reload and logout for absence of auth tokens; inspect responses for no issued
  bearer tokens or private fields. Session cookie is HttpOnly; the readable XSRF
  cookie is expected and is not an authentication token stored in web storage.

### E3 — Health and global diagnostics regression

- Run existing backend/frontend health tests and `e2e/health.spec.ts`: real 200
  connected result, pending state, rejected request, exact 503 body, overflow,
  wrong-origin/late diagnostic rejection, and duplicate-request settlement.
- Collect global console errors, page exceptions, failed requests/resources,
  redirects, and API statuses/bodies from before navigation until requests and
  body parses settle. Keep auth and health assertions scoped to their UI regions
  while retaining a global unexpected-error gate.
- Allow only scenario-required failures correlated to exact origin/path/method,
  status or failure code and expected body where applicable (for example initial
  guest `/me` 401). Do not ignore all auth responses, unknown console origins, or
  all 4xx/5xx to keep health tests green. Unexpected diagnostics fail review.

### E4 — Required visual and accessibility gate

Execute the auth lifecycle and inspect screenshots in all existing projects:
**390x844**, **768x1024**, **1440x900**. Capture login, registration, authenticated,
pending, error, and post-logout states with descriptive artifact names.

Independent Tester must interact with each viewport and record layout/overflow,
spacing/alignment, typography/contrast, responsive controls, focus visibility,
keyboard order and submission, labels/error associations, navigation, disabled
states, stable loading, understandable errors, and success states. Inspect the
health section alongside auth. Mark dialogs/media/empty states N/A only when
absent, with a reason. Automated passing tests and screenshots without actual
visual review do not satisfy E4. Record design-skill/guidelines findings and
console/network/API review per viewport; unexpected errors fail approval.

## Execution and Handoff

Builder runs targeted new tests before fixes, then the applicable complete checks:

| Working directory | Command / check |
| --- | --- |
| `apps/api` | `php artisan test --compact --filter=Authentication` (adjust filter for new sibling suites) |
| `apps/api` | `php artisan test --compact` |
| `apps/api` | `vendor/bin/pint --dirty --format agent` followed by affected reruns if formatting changes files |
| `apps/web` | `npm test` |
| `apps/web` | `npm run lint` |
| `apps/web` | `npm run build` |
| `apps/web` | `npm run test:e2e` (all three projects, including auth and health) |
| Repository root | Existing governance check command from repository/CI tooling; record the exact invocation |

Record actual environment preparation, commands, exits, named failing/passing
assertions and counts in `evidence.md`, mapping them to B1–E4. Infrastructure or
fixture failures are blockers, not RED. Immediately passing added tests are
regression evidence; preserve unsupported historical RED/approval claims as
history with explicit clarification rather than inventing proof.

Builder hands off evidence and remaining gaps to Orchestrator. Orchestrator
reconciles project state and invokes an independent Tester role session. Tester
reruns applicable checks, reviews the actual diff and running app, and records
an explicit APPROVE/REJECT with artifacts and any unresolved TDD/design/tooling
gaps. A different model is preferred where selectable; record unavailable model
selection honestly. Rejected implementation goes back through Orchestrator to
Builder. This plan does not authorize lifecycle operations or another issue.
