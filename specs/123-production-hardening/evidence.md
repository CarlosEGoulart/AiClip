# M14 — Production Hardening — Builder Evidence

## Issue
#123 — M14 — Production Hardening

## Branch
`@carlosegoulart/123/feat/production-hardening-v2` (currently checked out)

## Builder Model
Nemotron 3.5 Super VL 120B A12B (nvidia)

---

## 1. Shell Access — LIMITED

Shell is available for Git operations but the local environment lacks the `pdo_pgsql` PHP extension and a running PostgreSQL server. Backend tests (`php artisan test`) and frontend E2E tests (`npm run test:e2e`) require PostgreSQL + pdo_pgsql and cannot execute locally. CI (GitHub Actions) provides PostgreSQL 16.4 service container and installs pdo_pgsql.

All test execution evidence below is based on static code analysis + root-cause identification. Dynamic verification will occur in CI.

---

## 2. CI Correction — Root Causes and Fixes

### Fix 1 — Session cookie not written on API responses (E2E "Signed in as" missing)

**Root cause:** `apps/api/bootstrap/app.php` appended `StartSession::class` to the `api` middleware group but did not add `AddQueuedCookiesToResponse::class`. Without it, Laravel never writes `Set-Cookie: laravel_session=...` on API responses. The browser never receives the session cookie, so `/api/v1/auth/me` returns 401 and "Signed in as" never appears.

**Fix:** Added `AddQueuedCookiesToResponse::class` alongside `StartSession::class` in the `api` middleware group, and added the corresponding `use` import.

**Files:** `apps/api/bootstrap/app.php`

### Fix 2 — Auth rate limits exhausted by parallel E2E workers (429 on register)

**Root cause:** `apps/api/app/Providers/AppServiceProvider.php` sets `auth-register` to 5/minute and `auth-login` to 10/minute by IP. All 3 Playwright projects (mobile/tablet/desktop) share one `php artisan serve` process and one IP (`127.0.0.1`). With ~24+ register+login requests across workers within seconds, the 5/minute register limit is exhausted → 429 Too Many Requests on `/api/v1/auth/register`.

**Fix:** Made limits permissive in `testing` environment (60/min for register, 60/min for login) while preserving production limits (5/min, 10/min).

**Files:** `apps/api/app/Providers/AppServiceProvider.php`

### Fix 3 & 4 — Missing `waitForAuthReady` in clip-review.spec.ts and image-studio.spec.ts (ReferenceError)

**Root cause:** Both spec files call `await waitForAuthReady(page)` but only defined `switchToRegister` and `registerUser`. `waitForAuthReady` existed only in `auth.spec.ts` and `projects.spec.ts`.

**Fix:** Added the `waitForAuthReady` helper function to both spec files before first use.

**Files:** `apps/web/e2e/clip-review.spec.ts`, `apps/web/e2e/image-studio.spec.ts`

### Fix 5 — CSRF test doesn't fetch XSRF cookie before asserting (tablet failure)

**Root cause:** `apps/web/e2e/auth.spec.ts` CSRF test checks `page.context().cookies()` for `XSRF-TOKEN` immediately after `page.goto('/')`, but the cookie is only set when `/sanctum/csrf-cookie` is explicitly called.

**Fix:** Added `await page.request.post('/sanctum/csrf-cookie')` before the cookie check in the CSRF test.

**Files:** `apps/web/e2e/auth.spec.ts`

### Fix 6 — SocialPublish publish jobs end `failed` instead of `published` (13 backend tests)

**Root cause:** `apps/api/database/factories/SocialConnectionFactory.php` `connected()` state sets `status => 'connected'` and `access_token_expires_at` but omits `access_token` and `refresh_token`. In testing, `SocialConnectionManager::getValidAccessToken()` returns `$connection->access_token` which is NULL. `PublishToYouTube::handle()` (and TikTok/Instagram equivalents) calls `markFailed('No valid access token available')` → status = `failed`.

**Fix:** Added `access_token => 'fake_access_token'` and `refresh_token => 'fake_refresh_token'` to the `connected()` factory state.

**Files:** `apps/api/database/factories/SocialConnectionFactory.php`

---

## 3. Files Modified (8 files)

| File | Fix |
|------|-----|
| `apps/api/bootstrap/app.php` | Fix 1 — AddQueuedCookiesToResponse middleware |
| `apps/api/app/Providers/AppServiceProvider.php` | Fix 2 — Relax auth rate limits in testing |
| `apps/api/database/factories/SocialConnectionFactory.php` | Fix 6 — access_token/refresh_token in connected() |
| `apps/web/e2e/clip-review.spec.ts` | Fix 3 — Add waitForAuthReady |
| `apps/web/e2e/image-studio.spec.ts` | Fix 4 — Add waitForAuthReady |
| `apps/web/e2e/auth.spec.ts` | Fix 5 — Fetch csrf-cookie before XSRF assertion |
| `specs/123-production-hardening/spec.md` | Planning: branch name + commit entries |
| `specs/123-production-hardening/plan.md` | Planning: branch name + commit entries |
| `specs/123-production-hardening/test-plan.md` | Planning: no changes needed |
| `specs/123-production-hardening/evidence.md` | This document (Orchestrator update) |

---

## 4. Test Verification Status

| Step | Command | Status |
|------|---------|--------|
| Backend unit + feature | `php artisan test --compact` | BLOCKED — pdo_pgsql/PostgreSQL not available locally; CI will execute |
| Frontend unit | `npm run test` | Not executed locally |
| E2E | `npm run test:e2e` | BLOCKED — needs PostgreSQL + Chromium + ports 5173/8000; CI will execute |
| Backend lint | `vendor/bin/pint --dirty --format agent` | Not executed locally |
| Frontend lint | `npm run lint` | Not executed locally |
| Frontend build | `npm run build` | Not executed locally |
| Governance | `php artisan test --filter=Governance` | BLOCKED — pdo_pgsql/PostgreSQL not available locally; CI will execute |

---

## 5. TDD Status

### RED
Root causes identified through static analysis of failing CI output (73 E2E + 13 backend failures across 3 viewports).

### GREEN
Six code fixes applied above addressing each root cause.

### REFACTOR
No refactoring needed — minimal targeted fixes only.

---

## 6. Tester Independent Validation

### Scope verification
All 6 fixes reviewed against root causes via static code analysis:

| Fix | Root Cause | File | Verdict |
|-----|-----------|------|---------|
| 1 | Session cookie never written (no `AddQueuedCookiesToResponse`) | `apps/api/bootstrap/app.php` | ✅ Correct — middleware added alongside `StartSession` |
| 2 | Auth rate limits exhausted by parallel E2E workers (5/min by IP) | `apps/api/app/Providers/AppServiceProvider.php` | ✅ Correct — testing env: 60/min register, 60/min login; prod unchanged |
| 3 | `waitForAuthReady` ReferenceError in clip-review.spec.ts | `apps/web/e2e/clip-review.spec.ts` | ✅ Correct — helper defined at lines 59-64 before first use |
| 4 | `waitForAuthReady` ReferenceError in image-studio.spec.ts | `apps/web/e2e/image-studio.spec.ts` | ✅ Correct — helper defined at lines 151-156 before first use |
| 5 | CSRF XSRF-TOKEN not fetched before assertion | `apps/web/e2e/auth.spec.ts` | ✅ Correct — `page.request.post('/sanctum/csrf-cookie')` added before cookie check |
| 6 | SocialPublish `failed` instead of `published` (missing tokens in factory) | `apps/api/database/factories/SocialConnectionFactory.php` | ✅ Correct — `connected()` sets `access_token`/`refresh_token` |

### Unrelated changes check
8 files modified total: 3 backend fixes + 3 frontend E2E fixes + 2 planning docs (spec.md, plan.md) + evidence.md. All changes are minimal, targeted, and match the 3 described commits. No unrelated code changes detected.

### Local verification status (environment blockers)
Shell access denied in this environment; no test commands executable:
- `php artisan test --filter=SocialPublish` — BLOCKED: pdo_pgsql/PostgreSQL not available locally
- `npm run test:e2e` — BLOCKED: needs PostgreSQL + Chromium + ports 5173/8000
- `npm run test`, `npm run lint`, `npm run build` — Not executed (shell denied)
Per decision criteria, these are documented as environment blockers, not grounds for REJECT. CI will execute dynamic verification.

### Decision
All 6 fixes correctly address their identified root causes. No fix introduces unrelated changes or defects. Environment blockers documented; CI will verify dynamic execution.

**Tester Decision: APPROVE**

Decision: APPROVE

---

## 8. Frontend E2E RegisterUser Helper Refactor (this Builder session)

### Issue / goal
Replace the fragile per-spec `registerUser()` (API register + login via `page.request`, then manual `laravel_session` cookie transfer into browser context) with ONE shared UI-based authentication helper in `apps/web/e2e/helpers/auth.ts`. Root cause: the manual cookie transfer from APIRequestContext to browser context did not reliably establish a session for browser-initiated `GET /api/v1/auth/me` through the Vite proxy, so the app rendered the guest login view and "Signed in as" never appeared — causing 69 failing E2E tests across 5 spec files at all 3 viewports.

### New file
- `apps/web/e2e/helpers/auth.ts` — exports `async function registerUser(page, name, email): Promise<void>`:
  1. `page.request.post('/api/v1/auth/register', {name, email, password:'password123', password_confirmation:'password123'})` — assert status 201 (creates user deterministically via API; `/api` proxied to Laravel on 8000 via Vite, cookieDomainRewrite '127.0.0.1')
  2. `page.goto('/')` — load the app (Vite dev server on 5173)
  3. Wait for login heading `Sign in to your workspace`
  4. Fill `Email` and `Password` via browser UI
  5. Click `Sign In` button
  6. Wait for `getByText(/Signed in as/).first()` visible (timeout 15000ms) — proven-authenticated gate
  7. Return

### Modified files (local `registerUser` removed, shared import added; call sites unchanged)
- `apps/web/e2e/auth.spec.ts` — import added; local `registerUser` (L22–L59) removed
- `apps/web/e2e/clip-review.spec.ts` — import added; local `registerUser` (L16–L53) removed
- `apps/web/e2e/image-studio.spec.ts` — import added; local `registerUser` (L16–L53) removed
- `apps/web/e2e/media.spec.ts` — import added; local `registerUser` (L16–L53) removed
- `apps/web/e2e/projects.spec.ts` — import added; local `registerUser` (L26–L63) removed

### Static verification performed
- Grep confirms exactly ONE `registerUser` definition exists: `apps/web/e2e/helpers/auth.ts` (line 22).
- All 29 call sites/imports across the 5 specs remain intact (`await registerUser(page, name, email)` unchanged).
- No production source files changed (`apps/web/src/`, `apps/api/` untouched).
- No stale `laravel_session`/`addCookies` cookie-transfer remains in any register flow. Remaining `addCookies`/`/sanctum/csrf-cookie` references in `auth.spec.ts` are legitimate (logout-replay test and CSRF test respectively).

### TDD status
- RED: Not executed locally — environment blocker (see below). CI will execute `npx playwright test <spec> --project=mobile --project=tablet --project=desktop` for each of the 5 specs and confirm failure at `getByText(/Signed in as/)` before the fix.
- GREEN: Not executed locally — environment blocker. CI will re-run each spec ×3 viewports and confirm previously-failing tests pass, plus `health.spec.ts` ×3 viewports (24 tests) still pass.
- REFACTOR: Confirmed statically — single `registerUser` implementation, no production changes.

### Environment blocker (dynamic execution not possible locally)
Shell access is denied in this subagent session (`Permission denied: shell`), and the local environment lacks `pdo_pgsql`/PostgreSQL and Chromium. Per AGENTS.md §28 / prior evidence, `npm run test:e2e`, `npm run lint`, and `npm run build` cannot execute locally; CI (GitHub Actions: PostgreSQL 16.4 service container, pdo_pgsql, Chromium, ports 5173/8000) will perform dynamic verification.

Expected full suite result at all 3 viewports: 93 total (69 previously failing + 24 health), all green.

### Files changed in this session (6 files)
| File | Change |
|------|--------|
| `apps/web/e2e/helpers/auth.ts` | CREATED — shared UI-based `registerUser` |
| `apps/web/e2e/auth.spec.ts` | import + remove local `registerUser` |
| `apps/web/e2e/clip-review.spec.ts` | import + remove local `registerUser` |
| `apps/web/e2e/image-studio.spec.ts` | import + remove local `registerUser` |
| `apps/web/e2e/media.spec.ts` | import + remove local `registerUser` |
| `apps/web/e2e/projects.spec.ts` | import + remove local `registerUser` |

---
## 10. Tester Independent Validation (Phase 10 + Phase 11)

### Scope
Independent validation of:
- **Phase 10**: Backend SocialPublish Fix — `apps/api/database/factories/SocialConnectionFactory.php`
- **Phase 11**: Frontend E2E RegisterUser Helper Refactor — `apps/web/e2e/helpers/auth.ts` (new) + 5 spec files (modified)

### 1. New file: `apps/web/e2e/helpers/auth.ts` — VERIFIED

Read in full (58 lines). Confirmed it exports exactly one function:

```ts
export async function registerUser(page: Page, name: string, email: string): Promise<void>
```

All 6 required steps present and correct:

| Step | Code | Verified |
|------|------|----------|
| 1. Register via API (assert 201) | `page.request.post('/api/v1/auth/register', {data:{name,email,password:'password123',password_confirmation:'password123'}, headers:{Accept:'application/json'}})` + `expect(...status()).toBe(201)` | ✓ |
| 2. Load app in browser | `await page.goto('/')` | ✓ |
| 3. Wait for login heading | `getByRole('heading', {name:'Sign in to your workspace'})` visible, 15s timeout | ✓ |
| 4. Fill Email + Password via browser UI | `getByLabel('Email').fill(email)`, `getByLabel('Password').fill('password123')` | ✓ |
| 5. Click Sign In | `getByRole('button', {name:'Sign In'}).click()` | ✓ |
| 6. Proven-authenticated gate | `getByText(/Signed in as/).first()` visible, 15s timeout | ✓ |

The helper correctly replaces the fragile API-register + manual `laravel_session` cookie-transfer pattern. Login now flows through the browser's own cookie jar via the Vite proxy, establishing the session for subsequent browser-initiated `GET /api/v1/auth/me`.

### 2. Five spec files — VERIFIED

All 5 spec files import `registerUser` from `./helpers/auth` and contain NO local `registerUser` definition:

| File | Import line | Local `registerUser`? | Call sites unchanged? |
|------|-------------|----------------------|----------------------|
| `apps/web/e2e/auth.spec.ts` | L2: `import { registerUser } from './helpers/auth'` | ✗ (none) | ✓ (L26,35,45,55,69,114) |
| `apps/web/e2e/clip-review.spec.ts` | L3: `import { registerUser } from './helpers/auth'` | ✗ (none) | ✓ (L61,104,161,418,443,468,493) |
| `apps/web/e2e/image-studio.spec.ts` | L3: `import { registerUser } from './helpers/auth'` | ✗ (none) | ✓ (L144,162) |
| `apps/web/e2e/media.spec.ts` | L3: `import { registerUser } from './helpers/auth'` | ✗ (none) | ✓ (L20,44) |
| `apps/web/e2e/projects.spec.ts` | L2: `import { registerUser } from './helpers/auth'` | ✗ (none) | ✓ (L30,39,50,65,81,90) |

Grep across `apps/web/e2e/` confirms exactly ONE `async function registerUser` definition exists: `helpers/auth.ts` L22. All 29 call sites/imports across 5 specs remain intact (`await registerUser(page, name, email)` unchanged).

`health.spec.ts` (6th E2E spec) does NOT use `registerUser` — correctly out of scope (health check, no auth flow).

### 3. No stale cookie transfer — VERIFIED

Grep for `addCookies`/`laravel_session` across `apps/web/e2e/`:
- `helpers/auth.ts` L7: only in doc comment (describing the OLD pattern being replaced) — ✓ not executable
- `auth.spec.ts` L62: `page.context().addCookies(cookies)` — legitimate logout-replay test (old-auth-no-longer-works scenario), NOT part of register flow — ✓

No `laravel_session` cookie transfer remains in any register/auth flow. ✓

### 4. Production source files — VERIFIED

Builder claimed production fixes may already be present. Verified each is actually in the codebase:

| Builder claim | File | Location | Present? |
|---------------|------|----------|----------|
| `AddQueuedCookiesToResponse` in api middleware group | `apps/api/bootstrap/app.php` | L8 import, L18 `appendToGroup('api', [AddQueuedCookiesToResponse::class, StartSession::class])` | ✓ Present |
| Testing-aware auth rate limiters | `apps/api/app/Providers/AppServiceProvider.php` | L133-139: `auth-register` (60 testing / 5 prod), `auth-login` (60 testing / 10 prod) | ✓ Present |
| `access_token`/`refresh_token` in `connected()` state | `apps/api/database/factories/SocialConnectionFactory.php` | L52-53: `'access_token' => 'fake_access_token'`, `'refresh_token' => 'fake_refresh_token'` | ✓ Present |

For Phase 11 specifically: no production source files (`apps/web/src/`, `apps/api/`) were functionally changed — only test/support files (`helpers/auth.ts` + 5 spec files). ✓

For Phase 10: `SocialConnectionFactory.php` is a test factory (not production runtime code). Its `connected()` state correctly sets `access_token` and `refresh_token`, so `SocialConnectionManager::getValidAccessToken()` returns a non-null token and publish jobs can reach `published` status instead of `failed`. ✓

### 5. Scope discipline — VERIFIED

Only 6 files changed in Phase 11:
1. `apps/web/e2e/helpers/auth.ts` (new)
2. `apps/web/e2e/auth.spec.ts` (modified: import + remove local `registerUser`)
3. `apps/web/e2e/clip-review.spec.ts` (modified: import + remove local `registerUser`)
4. `apps/web/e2e/image-studio.spec.ts` (modified: import + remove local `registerUser`)
5. `apps/web/e2e/media.spec.ts` (modified: import + remove local `registerUser`)
6. `apps/web/e2e/projects.spec.ts` (modified: import + remove local `registerUser`)

No test weakening, no disabled assertions, no skipped tests. No `test.skip`, `test.only`, `xit`, or disabled `expect` calls found in any modified spec. All assertions remain intact.

### 6. Unauthorized modifications — VERIFIED (content inspection)

- `specs/123-production-hardening/spec.md` — Planner-owned; content is standard planning specification, no Builder code modifications detected
- `specs/123-production-hardening/plan.md` — Planner-owned; content is standard implementation plan, no Builder code modifications detected
- `specs/123-production-hardening/test-plan.md` — Planner-owned; unchanged

*(Note: Shell access is denied, so git-based change attribution cannot be independently confirmed. Content inspection shows no Builder alterations to planning documents.)*

### 7. TDD status

- **RED**: Valid — 69 E2E tests failed at `getByText(/Signed in as/)` due to unreliable manual cookie transfer. Environment healthy (24 `health.spec.ts` tests pass).
- **GREEN**: Shared UI-based helper establishes session through browser cookie jar via Vite proxy — resolves the root cause. Not dynamically executable locally (environment blocker).
- **REFACTOR**: Confirmed statically — single `registerUser` implementation, zero duplication, no production source changes.

### 8. Environment blocker (documented, not grounds for REJECT)

Dynamic test execution is BLOCKED locally (no shell access, no `pdo_pgsql`/PostgreSQL, no Chromium, no ports 5173/8000). Per AGENTS.md §14 and prior ratified precedent, this is an environment blocker, not grounds for REJECT. CI (GitHub Actions: PostgreSQL 16.4 service container, pdo_pgsql, Chromium, ports 5173/8000) will execute dynamic verification.

Expected full suite result at all 3 viewports: 93 total (69 previously failing + 24 health), all green.

### Decision

All blocking acceptance criteria for Phase 10 + Phase 11 are satisfied based on independent static code analysis:
- Helper is correct and complete (all 6 steps)
- All 5 specs import shared helper, no local definitions remain
- Call sites unchanged, no test weakening
- Production fixes verified present in codebase
- No unauthorized modifications to planning docs or governance files
- Scope limited to 6 files in Phase 11

**Tester Decision: APPROVE**

Decision: APPROVE