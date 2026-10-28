# M11 — Instagram Publishing — Evidence

## Implementation Status

### Phase 0: Auth Infrastructure Verification — DONE (code written)
- [x] `tests/Feature/Auth/AuthFlowVerificationTest.php` — written
- [ ] `GET /sanctum/csrf-cookie` → 204 — pending test execution (shell unavailable)
- [ ] `POST /api/v1/auth/register` → 201 — pending test execution
- [ ] `POST /api/v1/auth/login` → 200 — pending test execution
- [ ] Session persists across requests — pending test execution

### Phase 1: InstagramPublisher — DONE (code written)
- [x] `app/Services/InstagramPublisher.php` — created
- [x] `app/Services/FakeInstagramPublisher.php` — created
- [x] `app/Providers/AppServiceProvider.php` — `:instagram` bindings added (real + testing fake)
- [x] `tests/Unit/Social/FakeInstagramPublisherTest.php` — created
- [x] `tests/Unit/Social/InstagramPublisherTest.php` — created
- [ ] Unit tests — pending test execution

### Phase 2: PublishToInstagram Job — DONE (code written)
- [x] `app/Jobs/PublishToInstagram.php` — created (follows PublishToYouTube pattern)
- [x] `tests/Unit/Jobs/PublishToInstagramTest.php` — created
- [ ] Unit tests — pending test execution

### Phase 3: API Endpoints — DONE (code written)
- [x] `app/Http/Requests/PublishClipRequest.php` — `in:youtube,instagram`
- [x] `app/Http/Controllers/Api/V1/SocialPublishController.php` — platform-based job dispatch
- [x] `tests/Feature/SocialPublish/SocialPublishRequestTest.php` — updated
- [x] `tests/Feature/SocialPublish/SocialPublishControllerTest.php` — updated with Instagram tests
- [ ] Feature tests — pending test execution

### Phase 4: Frontend — DONE (code written)
- [x] `apps/web/src/features/social-publish/types.ts` — platform type `'youtube' | 'instagram'`
- [x] `apps/web/src/features/social-publish/components/PublishClipDialog.tsx` — platform selector added
- [x] `apps/web/src/features/social-publish/components/PublishCard.tsx` — Instagram icon/label added
- [x] `apps/web/src/features/social-publish/components/PublishClipDialog.test.tsx` — updated
- [x] `apps/web/src/features/social-publish/components/PublishCard.test.tsx` — updated
- [x] `apps/web/src/features/social-publish/api/index.test.ts` — Instagram platform test added
- [x] `apps/web/src/features/social-publish/hooks.test.tsx` — Instagram platform test added
- [ ] Frontend tests — pending test execution

### Phase 5: Integration & E2E — DONE (code written)
- [x] `tests/Feature/SocialPublish/SocialPublishIntegrationTest.php` — Instagram integration tests added
- [x] E2E Playwright (`apps/web/tests/e2e/social-publish.spec.ts`) — rewritten with real auth flow + Instagram scenarios (all 3 viewports)
- [ ] Integration tests — pending test execution

### Phase 6: Final Verification — NOT STARTED
- [ ] Backend test suite — pending
- [ ] Frontend test suite — pending
- [ ] E2E at 3 viewports — pending
- [ ] Governance tests — pending
- [ ] Lint (Pint, ESLint) — pending
- [ ] TypeScript build — pending

---

## RED / GREEN / REFACTOR Records

### Phase 0 — AuthFlowVerificationTest

**RED**: Test written (`tests/Feature/Auth/AuthFlowVerificationTest.php`); not yet executed (shell unavailable).

**GREEN**: No implementation change required — sessions table (`0001_01_01_000000_create_users_table.php`), APP_KEY (`apps/api/.env.example`), SANCTUM_STATEFUL_DOMAINS (`apps/api/.env.example`) are present from M10.

**REFACTOR**: None.

### Phase 1 — InstagramPublisher + FakeInstagramPublisher

**RED**: Tests written (`FakeInstagramPublisherTest.php`, `InstagramPublisherTest.php`); not yet executed.

**GREEN**: Implementation files created (`FakeInstagramPublisher.php`, `InstagramPublisher.php`, `AppServiceProvider.php` bindings).

**REFACTOR**: None yet.

### Phase 2 — PublishToInstagram Job

**RED**: Test written (`PublishToInstagramTest.php`); not yet executed.

**GREEN**: Implementation file created (`PublishToInstagram.php`, follows `PublishToYouTube` pattern exactly).

**REFACTOR**: None yet.

### Phase 3 — API Endpoints

**RED**: Tests written/modified (`SocialPublishRequestTest.php`, `SocialPublishControllerTest.php`, `SocialPublishIntegrationTest.php`); not yet executed.

**GREEN**: `PublishClipRequest.php` (`in:youtube,instagram`), `SocialPublishController.php` (platform-based connection resolution + job dispatch).

**REFACTOR**: None yet.

### Phase 4 — Frontend

**RED**: Tests written/modified (`PublishClipDialog.test.tsx`, `PublishCard.test.tsx`, `api/index.test.ts`, `hooks.test.tsx`); not yet executed.

**GREEN**: `types.ts` (platform union type), `PublishClipDialog.tsx` (platform selector), `PublishCard.tsx` (Instagram icon/label).

**REFACTOR**: None yet.

---

## Files Created / Modified

### Created
- `specs/120-social-publishing/evidence.md` (this file)
- `tests/Feature/Auth/AuthFlowVerificationTest.php`
- `tests/Unit/Social/FakeInstagramPublisherTest.php`
- `tests/Unit/Social/InstagramPublisherTest.php`
- `tests/Unit/Jobs/PublishToInstagramTest.php`
- `app/Services/InstagramPublisher.php`
- `app/Services/FakeInstagramPublisher.php`
- `app/Jobs/PublishToInstagram.php`

### Modified
- `app/Providers/AppServiceProvider.php` (added `:instagram` bindings + imports)
- `app/Http/Requests/PublishClipRequest.php` (platform rule)
- `app/Http/Controllers/Api/V1/SocialPublishController.php` (platform-based dispatch)
- `tests/Feature/SocialPublish/SocialPublishRequestTest.php`
- `tests/Feature/SocialPublish/SocialPublishControllerTest.php`
- `tests/Feature/SocialPublish/SocialPublishIntegrationTest.php`
- `apps/web/src/features/social-publish/types.ts`
- `apps/web/src/features/social-publish/components/PublishClipDialog.tsx`
- `apps/web/src/features/social-publish/components/PublishClipDialog.test.tsx`
- `apps/web/src/features/social-publish/components/PublishCard.tsx`
- `apps/web/src/features/social-publish/components/PublishCard.test.tsx`
- `apps/web/src/features/social-publish/api/index.test.ts`
- `apps/web/src/features/social-publish/hooks.test.tsx`

---

## Tester Findings (Independent Validation)

### Auth Infrastructure Prerequisites (Static Verification — PASSED)

| Check | Status | Evidence |
|-------|--------|----------|
| Sessions table migration | PASS | `database/migrations/0001_01_01_000000_create_users_table.php` creates `sessions` table (id, user_id, ip_address, user_agent, payload, last_activity) |
| Sessions table no-op migration | PASS | `2026_10_27_000000_create_sessions_table.php` is intentional no-op (avoids "Duplicate table") |
| APP_KEY non-empty | PASS | `apps/api/.env.example` line 3: `APP_KEY=base64:8jR9mK2pL5nQ7vW3xYzAbCdEfGhIjKlMnOpQrStUvWx=` |
| SANCTUM_STATEFUL_DOMAINS | PASS | `apps/api/.env.example` line 33 includes `5173-cs-370822403794-default.cs-us-east1-dogs.cloudshell.dev` |
| SESSION_DRIVER=database | PASS | `apps/api/.env.example` line 27 |

### Backend Test Execution — CANNOT EXECUTE (BLOCKER)

Attempted `php artisan test --filter=AuthFlowVerificationTest --compact`. Result: **FAILED**, 6 tests, 0 passed, 6 errors.

Error: `could not find driver (Connection: pgsql, Host: 127.0.0.1, Port: 5432, Database: aiclip)` — PostgreSQL is not available in this environment. The pdo_pgsql driver/connection cannot be established.

Because the database is unavailable, the following mandatory verification **cannot execute**:
- `php artisan test --filter=AuthFlowVerificationTest` (Phase 0 gate: csrf-cookie → 204, register → 201, login → 200, session persists)
- `php artisan test --compact` (full backend suite: Unit, Feature, Integration)
- `php artisan test --filter=Governance`
- `vendor/bin/pint --dirty --format agent` (backend lint)

Shell access is blocked, so PostgreSQL cannot be started (via `docker compose up postgres`) and no PHP commands can be run.

### Frontend Test Execution — CANNOT EXECUTE (BLOCKER)

Shell access is blocked, so the following cannot execute:
- `npm test` (Vitest unit/component tests in `apps/web`)
- `npm run lint` (ESLint)
- `npm run build` (TypeScript compile)

### E2E Test Execution — CANNOT EXECUTE (BLOCKER)

Shell access is blocked, so `npm run test:e2e` (Playwright at 390x844, 768x1024, 1440x900) cannot execute.

### Static Code Review Findings

**Implementation reviewed (all files present and syntactically consistent):**
- `app/Services/InstagramPublisher.php` — implements `SocialPublisher`, `getPlatform()` → 'instagram', `getPlatformLabel()` → 'Instagram', publishVideo with Graph API media + media_publish flow, sanitized error messages, no token logging
- `app/Services/FakeInstagramPublisher.php` — deterministic fake, no network calls, implements `SocialPublisher`
- `app/Jobs/PublishToInstagram.php` — follows `PublishToYouTube` pattern, tries=3, backoff=5, uniqueId `social-publish:{publishId}`, idempotent skip, ownership re-validation
- `app/Providers/AppServiceProvider.php` — real + testing bindings for `SocialPublisher::class . ':instagram'`
- `app/Http/Requests/PublishClipRequest.php` — `in:youtube,instagram`
- `app/Http/Controllers/Api/V1/SocialPublishController.php` — platform-based job dispatch, ownership 404s, duplicate 409, CSRF
- Frontend: `types.ts`, `PublishClipDialog.tsx` (platform selector), `PublishCard.tsx` (Instagram icon/label), API client, hooks

**Gaps identified (code-review level, not execution-verified):**
1. `apps/web/src/features/social-publish/types.ts` line 54: `PublishStatusResponse.platform` is hardcoded to `'youtube'` instead of `'youtube' \| 'instagram'` — type mismatch for Instagram publishes.
2. `apps/web/tests/e2e/social-publish.spec.ts` is titled "YouTube Publishing — E2E Tests" and does **not** cover Instagram-specific E2E scenarios required by test-plan §5.2: Instagram platform selection, Instagram disabled state (no connection), Instagram submit flow. It also mocks authentication via `localStorage` rather than exercising the actual register/login/CSRF-cookie auth flow, so the auth prerequisite is not verified end-to-end in E2E.
3. `apps/api/tests/Feature/SocialPublish/SocialPublishIntegrationTest.php` line 13: unused `use Mockery;` import (minor).

### Decision

**Decision: REJECT**

Mandatory verification cannot execute. The Phase 0 auth gate (`AuthFlowVerificationTest`) failed due to PostgreSQL being unavailable (`could not find driver (Connection: pgsql)`), and shell access is blocked so no database, backend, frontend, E2E, lint, build, or governance verification can be run. Per AGENTS.md §14 and the task decision criteria: "When mandatory verification cannot execute: Decision: REJECT."

The implementation code appears complete and consistent with the spec based on static review, but it cannot be independently verified without a running PostgreSQL database and shell access.

---

## Builder Fixes — Resolved Tester Defects (Issue #135 REJECT → fixes applied)

The following defects from the Tester's REJECT decision have been fixed on branch `@carlosegoulart/135/feat/instagram-publishing`:

| # | Defect | Severity | File | Fix |
|---|--------|----------|------|-----|
| 1 | E2E spec missing Instagram scenarios; mocked auth via localStorage | HIGH | `apps/web/tests/e2e/social-publish.spec.ts` | Rewrote spec with real auth flow (GET `/sanctum/csrf-cookie` → POST `/api/v1/auth/register` → POST `/api/v1/auth/login` → session persists) replacing the `localStorage` mock. Added Instagram E2E scenarios at all 3 viewports (390x844, 768x1024, 1440x900): Instagram platform selection (dialog title → "Publish to Instagram"), Instagram disabled state when no connection, Instagram submit flow (submit → "Publishing…" pending state). YouTube publishing retained as regression. Console/Network cleanliness, keyboard navigation, focus indicators, ARIA labels, responsive layout, and touch-target tests retained at all 3 viewports. |
| 2 | `PublishStatusResponse.platform` hardcoded to `'youtube'` | HIGH | `apps/web/src/features/social-publish/types.ts` line 54 | Changed `platform: 'youtube'` → `platform: 'youtube' | 'instagram'` to match the existing union type used by `SocialPublish`, `PublishClipRequest`, and `PublishResponse`. |
| 3 | Unused `use Mockery;` import | LOW | `apps/api/tests/Feature/SocialPublish/SocialPublishIntegrationTest.php` line 13 | Removed the unused `use Mockery;` import. (`Mockery::mock()` calls resolve to the global `Mockery` class in this namespace-less file, so removal is safe.) |

### Verification status

- Backend test execution: **CANNOT EXECUTE** — PostgreSQL unavailable (`could not find driver (Connection: pgsql)`); shell access blocked.
- Frontend test execution: **CANNOT EXECUTE** — shell access blocked.
- E2E test execution: **CANNOT EXECUTE** — shell access blocked.

The three code defects above are resolved statically. Execution-dependent verification (backend suite, frontend suite, E2E at 3 viewports, lint, build, governance) remains blocked by the missing PostgreSQL database and blocked shell access, as documented in the Tester Findings section.

---

## Notes

- M10 infrastructure (SocialPublisher interface, PublishResult, PublishStatus, SocialPublish model, SocialPublishResource, PublishToYouTube job, YouTubePublisher, FakeYouTubePublisher, SocialPublishController, routes/api.php, config/social.php) is reused as-is — no modifications needed.
- `SocialPublishResource::getPlatformLabel()` already returns 'Instagram' for `ucfirst('instagram')` via default case — no change needed.
- `SocialPublishStatus` enum already handles all four statuses — no change needed.
- Routes (`POST /api/v1/social/publish`, `GET /api/v1/social/publishes`, `GET /api/v1/social/publishes/{publish}`) already registered from M10 — no change needed.
- Rate limiter `social-publish` (20/min) already registered from M10 — no change needed.
- `config/social.php` already has `instagram` provider entry from M9/M10 — no change needed.
- `SocialConnection` model already uses `encrypted` cast for tokens — no change needed.
