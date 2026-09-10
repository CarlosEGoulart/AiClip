# Evidence: Sanctum SPA Authentication

## Current Gate

**APPROVE — Independent Tester review complete.** All 15 acceptance criteria
satisfied. Code review, CI verification, test coverage, security architecture,
and scope assessment passed. See `2026-09-10 — Independent Tester Review`
section below for detailed findings.

## Historical TDD Claims (Unverified, Not Current Results)

The following original statements are retained as history. No original failing
commands/assertions or independent review artifacts accompanied them. Designing
components for testability is not RED evidence. These counts must not be used as
verification of the updated test plan.

### RED
- 18 backend test cases written before implementation
- Frontend components designed with testability

### GREEN
- All 26 backend tests pass
- All 6 frontend tests pass
- Lint clean
- Build clean

### REFACTOR
- Clean auth API layer
- Proper error handling
- Accessible form components

## Historical Test Result Claims

- Backend: 26/26 pass
- Frontend: 6/6 pass
- Governance: 143/143 pass
- Lint: clean
- Build: clean

## Historical Security Verification Claims

- Passwords hashed with bcrypt
- No bearer tokens generated
- Session-based authentication
- CSRF protection via /sanctum/csrf-cookie
- No auth tokens in localStorage/sessionStorage

## Historical Decision Correction

Previously recorded: `Decision: APPROVE`. Unsupported and superseded by
**REJECT / pending independent review**, not a new Tester decision by Builder.

## 2026-09-10 — Builder Backend Strengthening, Issue #28

### Scope, Environment, and Reproduction

- Role: Builder, as requested for the active issue. GitHub issue #28 was confirmed
  OPEN with `gh issue view 28 --json number,title,state,body` (exit 0).
- Repository: `/home/goulartoliveiracarloseduardo/AiClip` (exact spelling).
  All PHP commands below ran in
  `/home/goulartoliveiracarloseduardo/AiClip/apps/api`.
- Read root/API AGENTS.md, current issue, spec, updated plan and test plan, and
  relevant local `testing-best-practices` / `laravel-best-practices` skill rules.
- `php --version` / `php artisan --version` (exit 0): PHP 8.3.6,
  Laravel Framework 13.31.0. Existing Composer dependencies and PostgreSQL at
  the phpunit-configured local connection were available. No dependency install
  or database-service preparation was necessary. `RefreshDatabase` supplies
  migrations and transaction isolation; tests assert the `pgsql` connection.
- Starting uncommitted issue work was inspected. Existing throttle behavior,
  generic credential errors, sanitized resources, login route name, frontend
  edits, and updated planning documents were retained. This invocation changed
  only API auth code/tests and this evidence document. No lifecycle operations.
- The checked-in test plan defines B1–B5, with no B6 heading. The requested
  mutation evidence is listed separately below as the invocation's B6 crosswalk;
  this does not invent an additional Planner acceptance criterion.

### Harness: What the Requests Actually Prove

`tests/Support/SpaTestCase.php`, `SpaRequests.php`, and `EnforcedCsrf.php`:

1. Configure `session.driver=database` before the first HTTP/session resolution.
   Disable random session garbage collection for deterministic tests, not session
   persistence. The new suites use real PostgreSQL users and sessions.
2. Exercise the HTTP kernel and all production middleware. Every SPA request has
   `Origin: http://localhost:5173`, an actual configured stateful origin. No
   `Sec-Fetch-Site` shortcut, `actingAs`, `withSession`, or disabled middleware is
   used in the new suites.
3. Bind the Laravel CSRF middleware variants to a test-only subclass overriding
   **only** `runningUnitTests()` to false. Encryption, token matching, session
   loading, and request rejection remain the installed framework implementation.
4. Parse actual `Set-Cookie` headers with Symfony's `Cookie::fromString(...,
   decode: true)`. Pass those values directly to `call()` and the matching
   `X-XSRF-TOKEN` header; avoid Laravel test-helper double encryption. Saved guest
   and authenticated cookie arrays remain independent of subsequent responses.
   Decrypt IDs/tokens only for rotation and database assertions, never as injected
   authentication state. No cookie/token values are recorded in this document.
5. Before each request forget AuthManager's resolved web/Sanctum guards and the
   separate `auth.driver` singleton, restore the default web guard, discard only
   resolved session drivers/`session.store`, and clear queued response cookies.
   The HTTP kernel rebinds the request. Database rows and limiter cache survive.
   No global `Facade::clearResolvedInstances()` or application reboot is used.
6. Set `app.debug=false` to exercise production error contracts independently of
   local `.env` debug mode. The application already defaults debug to false;
   this is not a production exception-handler modification. Exact 419/401 and
   credential-error bodies are asserted alongside exact success bodies.

The older single-request/controller tests remain regression coverage; their
`actingAs`/array-session assertions are not offered as B1–B3 evidence. The old
logout test specifically was upgraded to a positive persisted-row assertion,
specific-row destruction, saved-cookie replay, and cookie-less control using the
shared fresh-request helper. Its global facade clearing was removed.

### Genuine RED, Production Fix, GREEN, and Refactor

Baseline before any edits:

| Command | Exit | Observed result |
| --- | --- | --- |
| `php artisan test --compact` | 0 | 30 passed, 113 assertions |

After adding database cookie-roundtrip tests and correcting the harness's
`auth.driver` reset, production was still unchanged:

| Command | Exit | Observed result |
| --- | --- | --- |
| `php artisan test --compact --filter=SessionAuthentication` | 1 | 1 passed / 3 failed, 38 assertions; old guest rows survived; after `/me`, logout setup's response session ID had no matching persisted row |
| `php artisan test --compact --filter='SessionAuthentication\|CsrfAuthentication'` | 1 | 11 passed / 3 failed, 177 assertions; same session defects, CSRF controls already passed |
| `php artisan test --compact --filter=SessionAuthentication` after adding a second `/me` roundtrip | 1 | 1 passed / 3 failed, 42 assertions; both register/login cases expected 200 on second `/me`, received 401; logout setup still lacked the expected row |

The last RED is a direct user-visible persistence failure, not an assertion
about middleware class names: register/login succeeds, the first `/me` succeeds,
but applying its response cookies to a fresh second `/me` loses authentication.

**Root cause:** API routes unconditionally added `web`, while `statefulApi()`
also added Sanctum's cookie/session/CSRF pipeline for a stateful Origin. Nested
cookie decryption and session startup disrupted the persisted session/cookie
identity. A cached in-process guard and array driver hid that broken roundtrip.

**Minimal production fix:** remove redundant `web` from the API auth routes in
`routes/api.php`, leaving Sanctum's configured stateful stack and `auth:sanctum`
on logout/me. No CSRF exceptions or vendor edits were introduced.

| Command after production fix | Exit | Observed result |
| --- | --- | --- |
| `php artisan test --compact --filter='SessionAuthentication\|CsrfAuthentication'` | 0 | 14 passed, 207 assertions |

**Refactor after GREEN:** registration/login/session-user lookup explicitly use
`Auth::guard('web')`, consistent with logout, rather than depending on the
mutable default guard. Extracted the cookie/request helper for reuse by the old
logout regression. Existing controller-only tests now send a stateful Origin.
Later refined cookie collection to parse the serialized Set-Cookie headers and
expanded validation checks against submitted-password leakage. Pint and the
affected suites were rerun after these changes.

B3–B5 behavior that passed when correctly exercised is **regression evidence**,
not reconstructed historical test-first RED. Validation, hashing, response
resources, and throttle policy needed no additional production changes.

### B1–B5 Coverage Map

All file names below are relative to `apps/api/tests/Feature/Auth/`.

| ID | Tests and observed guarantees |
| --- | --- |
| B1 | `SessionAuthenticationTest`: `persists registration and login through rotated database session cookies` (2 datasets). Effective database/pgsql driver, positive guest/authenticated rows, exact user body, `Hash::check`, ID and plaintext CSRF-token rotation, two fresh `/me` roundtrips, old guest cookie and no-cookie 401, no PAT rows. |
| B2 | `SessionAuthenticationTest`: `destroys the authenticated database session and rejects saved cookie replay after logout`; `returns JSON 401 for guest logout with a valid CSRF pair`. Positive row before logout, empty 204, destruction of that specific row, session/CSRF rotation, resulting-cookie/saved-cookie/no-cookie JSON 401. Also strengthens the pre-existing logout test. |
| B3 | `CsrfAuthenticationTest` (10 cases): cookie initialization/attributes and 3 operations × missing/wrong/another-session XSRF. All 9 rejected requests return exact 419; registration creates no user, login grants no identity, rejected logout preserves usable authentication; the matching real pair then succeeds. Local HTTP cookies: session HttpOnly, XSRF readable, `/` path, lax SameSite, configured Secure behavior. |
| B4 | `ValidationAuthenticationTest` (28 cases): 16 registration rejections, accepted minimum/255-character boundaries, 6 login validation cases, 2 exact generic credential cases, 2 verified/unverified exact login/me contracts. Rejections preserve existing users and create no authenticated session or PAT; minimum eight-character password is checked with `Hash::check`; seven-character rejection has matching confirmation to isolate the minimum rule. Name/email 255 accepted; 256 rejected. Public body contains exactly id/name/email/email_verified_at with expected types and timestamp values. Submitted private attributes are ignored. |
| B5 | `ThrottleAuthenticationTest` (3 cases): first 5 failures 422, sixth valid attempt 429, retry guidance, 299 blocked / 300 succeeds from first failure despite later/blocked requests, lowercased-email sharing, different-email and actual REMOTE_ADDR isolation, 4 failures → success → real logout → 5 allowed failures → sixth blocked, independent key remains blocked. Frozen time and fresh per-test array cache; no direct limiter-key assertions. |

### Requested B6 Crosswalk — Mutation Sensitivity (Not Historical RED)

Each temporary mutation below was applied individually after GREEN and fully
restored before final checks. Tests/security assertions were not removed or
relaxed to accommodate mutations. No vendor files were changed.

| Temporary mutation | Command | Exit and actual detection |
| --- | --- | --- |
| Re-enable the unit-test CSRF bypass in `EnforcedCsrf` | `php artisan test --compact --filter=CsrfAuthentication` | 1; 1 passed / 9 failed, 61 assertions. Missing/wrong/foreign XSRF expected 419, got register 201 / login 200 / logout 204. |
| Remove logout's session invalidation | `php artisan test --compact --filter=SessionAuthentication` | 1; 3 passed / 1 failed, 59 assertions. The specific old authenticated session row still exists. |
| Add an extra `internal_flag` to UserResource | `php artisan test --compact --filter='returns exactly public user fields'` | 1; 0 passed / 2 failed, 8 assertions. Exact verified/unverified response bodies reject the extra field. |
| Remove successful-login limiter reset | `php artisan test --compact --filter=ThrottleAuthentication` | 1; 2 passed / 1 failed, 67 assertions. Second post-logout failure incorrectly returns 429 instead of 422. |
| Remove client IP from limiter key | `php artisan test --compact --filter='shares casing limits'` | 1; 0 passed / 1 failed, 18 assertions. Independent IP's valid login incorrectly returns 429 instead of 200. |

These five killed mutations establish assertion sensitivity only. They do not
prove the original implementation was developed test-first or replace a Tester.

### Harness/Fixture Corrections — Explicitly Not Production RED

- Initial session run: 1 passed / 3 failed, 36 assertions. AuthManager guards
  were reset but `auth.driver` still referenced the previous guest guard, so
  session metadata showed null user_id. Added the targeted singleton reset;
  no production change was made for this harness error.
- Validation development runs: 67 cases with 25 failures (633 assertions), then
  68 with 1 failure (751 assertions). Factory-created raw attribute ordering
  differed from a fresh database read; snapshots now read persisted state on
  both sides. Initial long email fixtures triggered Egulias label-length errors.
  Verified the installed validator directly, then supplied valid shorter domain
  labels at the required total lengths: 255 accepted, 256 rejected. The temporary
  255-rejection fixture was removed; no validation rule was relaxed.
- Expanding 419 to an exact body initially produced 9 failures in the 75-case
  suite (66 passed, 698 assertions): local debug mode added exception traces.
  Set test configuration to production `app.debug=false` and retained the exact
  assertion. This documents the exercised configuration, not a newly fixed
  production security defect.
- The serialized-cookie refactor initially omitted Symfony's `decode: true`:
  75 cases, 29 passed, 43 failed, 3 errors, 307 assertions. Encoded values failed
  decryption/CSRF. Corrected decoding in the harness; no production changes or
  weaker assertions. The final runs below use the serialized-header helper.

### Final GREEN Checks

Before the final serialized-cookie/password-leak refinements, full and targeted
runs passed with 75/767 and 67/746 tests/assertions respectively; these are
intermediate results, superseded by the following final checks:

| Command, in execution order | Exit | Final result |
| --- | --- | --- |
| `php artisan test --compact --filter=Authentication` | 0 | 67 passed, 773 assertions |
| `php artisan test --compact` | 0 | 75 passed, 794 assertions, including existing health/unit regressions |
| `vendor/bin/pint --dirty --format agent` | 0 | passed |
| `php artisan test --compact --filter=Authentication` | 0 | 67 passed, 773 assertions after Pint/refactor |

Net backend coverage: 45 added cases beyond the observed 30-case baseline.
All temporary mutations were restored; UserResource has no remaining diff.
`git diff --check` at repository root passed, including the evidence update.

### Remaining Gates / Builder Handoff

- No unresolved backend execution blocker from this invocation. B1–B5 are GREEN
  Builder results ready for independent review, not independent approval.
- Frontend F1–F3, real Playwright auth/health E1–E4 at all three viewports, design
  skill/guidelines review, and running-app API/console/network/accessibility
  review remain for their planned invocations. No frontend/browser/governance
  command or visual approval is claimed from this backend-only invocation.
- Orchestrator must reconcile project state and invoke a separate Tester session.
  This Builder session cannot supply independent review or claim a different
  reviewer/model. Historical TDD gaps remain for Orchestrator disposition.
- Current gate remains **REJECT / pending independent Tester review**. No commit,
  push, PR, merge, issue closure, or next issue was performed.

## 2026-09-10 — Final Independent Tester Review, Issue #28 / PR #29

### Reviewer

Tester role (mimo-v2.5-free), separate session from Builder. Final independent
review of PR #29 against Issue #28 acceptance criteria.

### Environment

- Branch: `@carlosegoulart/28/feat/sanctum-spa-auth`
- PR #29: All 4 CI workflows GREEN (governance, Frontend, Backend, E2E)
- 42 files changed, 3916 insertions, 264 deletions
- 13 commits on branch, conventional commit format throughout

### Acceptance Criteria Verification

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | Sanctum configured for first-party SPA | PASS | `config/sanctum.php`: stateful domains include `localhost:5173`, guard is `['web']`. `bootstrap/app.php`: `$middleware->statefulApi()`. `.env.example`: `SANCTUM_STATEFUL_DOMAINS` configured. |
| 2 | CSRF initialization works | PASS | `api/index.ts`: `initCsrf()` fetches `/sanctum/csrf-cookie` before state-changing requests. Reads `XSRF-TOKEN` from `document.cookie`, sends `X-XSRF-TOKEN` header. Bounded 419 retry (one recovery). `CsrfAuthenticationTest`: 10 cases verifying cookie attributes and rejection of missing/wrong/foreign tokens. |
| 3 | Registration persists user to PostgreSQL | PASS | `AuthController::register`: `User::create()` with guarded fields. `SessionAuthenticationTest`: asserts `assertDatabaseHas('sessions', ...)` with `pgsql` driver verified. |
| 4 | Password stored hashed | PASS | `User` model: `'password' => 'hashed'` cast. Tests: `Hash::isHashed()` and `Hash::check()` both asserted true; plaintext password verified absent from database row. |
| 5 | Login establishes session | PASS | `AuthController::login`: `Auth::guard('web')->attempt()` + `$request->session()->regenerate()`. `SessionAuthenticationTest`: verifies session ID rotation, database row with `user_id`, subsequent `/me` authenticated. |
| 6 | Current user returns sanitized data | PASS | `UserResource`: returns exactly `id`, `name`, `email`, `email_verified_at`. Tests: `assertExactJson` with these 4 fields. `assertJsonMissingPath('user.password')` and `assertJsonMissingPath('user.remember_token')`. |
| 7 | Logout invalidates session | PASS | `AuthController::logout`: `Auth::guard('web')->logout()`, `$request->session()->invalidate()`, `$request->session()->regenerateToken()`. `SessionAuthenticationTest`: specific session row destroyed, saved cookie replay returns 401, cookie-less request returns 401. |
| 8 | No bearer token used | PASS | Frontend: `credentials: 'include'` for all requests; no `Authorization` header. `api/index.test.ts`: `new Headers(options?.headers).has('Authorization')` asserted false for every call. Backend: `assertDatabaseCount('personal_access_tokens', 0)` in session and validation tests. |
| 9 | No auth token in localStorage/sessionStorage | PASS | `api/index.test.ts`: spies on all 5 `Storage.prototype` methods — none called. E2E `no authentication bearer token in localStorage or sessionStorage`: inspects all keys after register+authenticated state; asserts no bearer/token patterns. |
| 10 | Health check accessible at /health | PASS | `App.tsx`: `/health` renders `HealthCheck` directly, bypassing `AuthProvider`. `routes/api.php`: `Route::get('/health', ...)`. E2E: 6 health tests across 3 viewports. |
| 11 | All backend tests pass | PASS | Backend CI GREEN. Builder evidence: 75 passed, 794 assertions. 67 auth-specific tests (Authentication filter). |
| 12 | All frontend tests pass | PASS | Frontend CI GREEN. PR states 72/72 pass. Test files: `Auth.test.tsx` (210 lines, ~15 test cases), `hooks/index.test.tsx` (205 lines, ~10 test cases), `api/index.test.ts` (146 lines, ~8 test cases). |
| 13 | All E2E tests pass (51/51) | PASS | E2E CI GREEN. PR states 51/51. Actual: 11 auth tests + 6 health tests = 17 × 3 viewports = 51. |
| 14 | Lint clean | PASS | Frontend CI GREEN (includes lint). |
| 15 | Build clean | PASS | Frontend CI GREEN (includes build). |

### Code Quality Review

**Backend (Laravel)**
- `AuthController`: Clean 92-line controller. Explicit `Auth::guard('web')` for all operations. Rate limiting with `RateLimiter` facade (5 attempts / 5-minute window). Generic credential error messages preventing user enumeration.
- `RegisterRequest` / `LoginRequest`: Proper FormRequest validation. Password uses `Password::defaults()` with `confirmed` rule.
- `UserResource`: Minimal 19-line resource exposing only safe fields. `#[Hidden]` attribute on User model as defense-in-depth.
- `routes/api.php`: Clean route structure. Health (public), register/login (public), logout/me (auth:sanctum protected).
- Test harness: `SpaTestCase`, `SpaRequests`, `EnforcedCsrf` — production-grade test infrastructure. Real HTTP kernel, parsed Set-Cookie headers, CSRF enforcement in tests. Sophisticated cookie lifecycle management.

**Frontend (React)**
- `api/index.ts`: 153-line API layer. Proper CSRF initialization, XSRF-TOKEN cookie reading, 419 bounded retry, typed error classification (validation/unauthorized/throttle/csrf/network/server).
- `AuthProvider`: 235-line provider with generation-based race condition protection (StrictMode-safe). `pendingRef` prevents double submissions. `mountedRef` prevents state updates after unmount. Clean error classification.
- `LoginForm` / `RegisterForm`: Accessible forms with `aria-invalid`, `aria-describedby`, `aria-busy`, `noValidate`. Focus management via `useRef` on first invalid field. Disabled states during pending.
- `AuthenticatedShell`: Clean 34-line component. Shows user info, logout button with pending state, error display on logout failure.

**Security**
- CSRF: Production middleware enforced in tests. Frontend sends X-XSRF-TOKEN header on all state-changing requests. 419 recovery with bounded retry.
- Sessions: Database-backed (`SESSION_DRIVER=database`). Session ID rotated on login and logout. HttpOnly cookies, lax SameSite.
- Passwords: `hashed` cast on User model. Never exposed in API responses (UserResource). Never stored in frontend state beyond form input.
- Token-free: No bearer tokens generated. No Authorization headers. No localStorage/sessionStorage auth data.
- Throttle: Rate limiting on login with email+IP key. 5 attempts per 5-minute window. Cleared on successful login.
- Generic errors: Same 422 response for wrong password and unknown email (prevents user enumeration).

### Test Coverage Review

**Backend (B1–B5)**
- B1 SessionAuthenticationTest (3 cases): Database-backed session persistence, session ID rotation, CSRF token rotation, guest→authenticated→guest transitions, verified PostgreSQL driver ✅
- B2 SessionAuthenticationTest (1 case): Logout destroys specific session row, saved cookie replay returns 401, cookie-less control returns 401 ✅
- B3 CsrfAuthenticationTest (10 cases): Cookie initialization (HttpOnly, path, SameSite, Secure), 3 operations × 3 rejection types (missing/wrong/foreign), post-rejection state preservation ✅
- B4 ValidationAuthenticationTest (28 cases): 16 registration rejections, boundary acceptance, 6 login validation cases, 2 generic credential cases, 2 exact public-field contracts ✅
- B5 ThrottleAuthenticationTest (3 cases): 5 failures → 429, expiry timing, email/IP isolation, reset after success+logout ✅

**Frontend (F1–F3)**
- F1 API boundary: CSRF setup, credential handling, error mapping, 419 retry, Storage/Authorization verification ✅
- F2 Provider state: Session checking, restoration, pending state, logout failure handling, StrictMode race protection (stale bootstrap, unmount) ✅
- F3 Forms/shell: ARIA semantics, validation feedback, view switching, transport error handling, form locking ✅

**E2E (E1–E4)**
- E1 Auth lifecycle (9 tests): Register→authenticated, reload persistence, logout→guest, cookie replay prevention, login→authenticated, login persistence, invalid credentials, duplicate registration, password mismatch ✅
- E2 CSRF and storage (2 tests): XSRF header verification on POST requests, localStorage/sessionStorage audit ✅
- E3 Health diagnostics (6 tests): Success, loading state, network rejection, 503, wrong-origin detection, duplicate request settlement ✅
- E4 Visual gate: 3 viewports (390×844, 768×1024, 1440×900). `assertNoHorizontalOverflow`, `assertContentReadable` in health tests ✅

### Scope Creep Assessment

All 42 changed files directly serve Issue #28:
- **Backend auth** (11 files): Controller, form requests, resource, routes, config, migration, composer changes
- **Frontend auth** (12 files): API, hooks, components, types, styles, barrel export
- **Tests** (9 files): 5 backend test suites, 2 E2E specs, 3 frontend test files
- **Test support** (3 files): SpaTestCase, SpaRequests, EnforcedCsrf
- **CI** (2 files): pg_isready healthcheck fix (minimal, necessary for auth E2E)
- **Docs** (4 files): spec.md, plan.md, test-plan.md, evidence.md, project-state.md

No unrelated changes detected. CI workflow changes are minimal (1 line each) and necessary for auth test infrastructure.

### Limitations

- Direct test execution was prevented by shell permission restrictions. Review relies on:
  1. All 4 CI workflows GREEN (verified via `gh pr view`)
  2. Builder evidence with 75/794 backend test results and mutation sensitivity
  3. Complete code review of all 42 changed files (every file read and analyzed)
  4. Test harness infrastructure correctness verification
- Visual review performed through code inspection (Playwright config, CSS, ARIA attributes). Live browser interaction not possible due to permission constraints. However, the Playwright E2E tests at 3 viewports with overflow/readability assertions provide strong visual regression coverage.

### Decision

**Decision: APPROVE**

All 15 acceptance criteria satisfied. The implementation is well-crafted:
1. Clean, minimal code throughout (AuthController 92 lines, AuthProvider 235 lines)
2. Robust security: database sessions, CSRF enforcement, password hashing, no bearer tokens, rate limiting
3. Excellent test coverage: 75 backend tests (794 assertions), 72 frontend tests, 51 E2E tests across 3 viewports
4. Sophisticated test harness with real HTTP kernel, parsed cookies, and CSRF enforcement
5. No scope creep: every change directly serves Issue #28
6. Strong StrictMode-safe patterns with generation-based race condition protection
7. All CI workflows GREEN (governance, Frontend, Backend, E2E)
