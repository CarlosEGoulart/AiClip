# M11 — Instagram Publishing Implementation Plan

## Overview

This plan breaks the implementation into sequential, testable increments following TDD (RED → GREEN → REFACTOR). Each phase delivers a vertical slice that can be independently verified. Issue #135 (GitHub) / specs/120-social-publishing.

M11 reuses the **exact** M10 `SocialPublisher` interface (`publishVideo`, `hasPublishPermission`, `getPlatformLabel`), `PublishResult` value object, `SocialPublish` model, and routes — adding Instagram alongside YouTube.

Branch: `@carlosegoulart/135/feat/instagram-publishing`

---

## Phase 0: Auth Infrastructure Prerequisite (Register/Login 500 Fix)

M11 publishing requires authenticated sessions. The auth 500 fix (sessions table, APP_KEY, SANCTUM_STATEFUL_DOMAINS) is present from M10. This phase **verifies** it functions before/in parallel with Instagram work.

### 0.1 Verify Sessions Table
- [ ] Confirm `0001_01_01_000000_create_users_table.php` creates `sessions` table (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`).
- [ ] Confirm `2026_10_27_000000_create_sessions_table.php` is intentional no-op.

### 0.2 Verify APP_KEY
- [ ] Confirm `apps/api/.env.example` has non-empty `APP_KEY=base64:...`.

### 0.3 Verify SANCTUM_STATEFUL_DOMAINS
- [ ] Confirm `apps/api/.env.example` includes `5173-cs-370822403794-default.cs-us-east1-dogs.cloudshell.dev`.

### 0.4 Verify Auth Flow Endpoints (Feature Test)
**RED**: Write auth flow test
- [ ] `tests/Feature/Auth/AuthFlowVerificationTest.php`
- [ ] Test: `GET /sanctum/csrf-cookie` → 204
- [ ] Test: `POST /api/v1/auth/register` → 201
- [ ] Test: `POST /api/v1/auth/login` → 200
- [ ] Test: session persists across requests (authenticated `/api/v1/auth/me` → 200)
- [ ] Run test → fails (confirms gap)
**GREEN**: Confirm config/migrations in place (no code change if already correct)
- [ ] Run test → passes
**REFACTOR**: none

---

## Phase 0.5: Social Configuration (Instagram)

### 0.5.1 Verify config/social.php
- [ ] Verify `apps/api/config/social.php` has `instagram` provider entry (client_id, client_secret, redirect_uri, scopes: `instagram_content_publish`, `instagram_basic`) — already present from M9/M10; verify only.

---

## Phase 1: InstagramPublisher (Backend)

### 1.1 SocialPublisher Contract Confirmation
**RED**: Write contract test
- [ ] `tests/Unit/Contracts/SocialPublisherContractTest.php`
- [ ] Test: interface exists, `getPlatform()`, `getPlatformLabel()`, `publishVideo(array): PublishResult`, `getPublishStatus(string): PublishStatus`, `hasPublishPermission(string): bool`
- [ ] Run test → fails (or confirm M10 already covers)

**GREEN**: Confirm `app/Contracts/SocialPublisher.php`, `PublishResult`, `PublishStatus` exist from M10 (unchanged)

### 1.2 FakeInstagramPublisher
**RED**: Write fake publisher test
- [ ] `tests/Unit/Social/FakeInstagramPublisherTest.php`
- [ ] Test: implements `SocialPublisher`, `getPlatform()` returns 'instagram', `getPlatformLabel()` returns 'Instagram', `publishVideo()` returns deterministic `PublishResult`, `hasPublishPermission()` returns bool, `getPublishStatus()` returns PublishStatus, no network calls
- [ ] Run test → fails

**GREEN**: Create fake publisher
- [ ] `app/Services/FakeInstagramPublisher.php`
- [ ] Register in `AppServiceProvider` for testing: `SocialPublisher::class . ':instagram'` → `FakeInstagramPublisher::class` when `app()->environment('testing')`
- [ ] Run test → passes

### 1.3 InstagramPublisher (Real)
**RED**: Write publisher test (with fake connection/publisher)
- [ ] `tests/Unit/Social/InstagramPublisherTest.php`
- [ ] Test: `getPlatform()` returns 'instagram', `getPlatformLabel()` returns 'Instagram', `publishVideo()` calls Graph API flow, `hasPublishPermission()` checks scopes/token, `getPublishStatus()` checks post
- [ ] Run test → fails

**GREEN**: Implement publisher
- [ ] `app/Services/InstagramPublisher.php`
- [ ] Inject `SocialConnectionManager` and HTTP client
- [ ] Implement `publishVideo()`: Instagram Graph API media + media_publish flow
- [ ] Implement `hasPublishPermission()`
- [ ] Implement `getPublishStatus()`
- [ ] Register real binding in `AppServiceProvider`: `SocialPublisher::class . ':instagram'` → `InstagramPublisher::class` (non-testing)
- [ ] Run test → passes

**REFACTOR**: Extract private methods, sanitize error messages, verify no token leakage
- [ ] Run full unit test suite

---

## Phase 2: PublishToInstagram Job (Backend)

### 2.1 Job
**RED**: Write job test
- [ ] `tests/Unit/Jobs/PublishToInstagramTest.php`
- [ ] Test: revalidates ownership/approval, resolves `SocialPublisher::class . ':instagram'`, calls `publishVideo()`, marks published on success, marks failed + error_message on failure, retries with backoff, idempotent skip
- [ ] Run test → fails

**GREEN**: Implement job
- [ ] `app/Jobs/PublishToInstagram.php`
- [ ] `handle(int $publishId)`: resolve `SocialPublisher::class . ':instagram'`, call `publishVideo()`, update `SocialPublish` row
- [ ] Retry configuration: `tries = 3`, `backoff = 5`, `uniqueId()` = `social-publish:{publishId}`
- [ ] Run test → passes

### 2.2 SocialPublished Event (if not existing from M10)
- [ ] Verify `app/Events/SocialPublished.php` exists from M10; create if missing
- [ ] Listener if needed

---

## Phase 3: API Endpoints (Backend)

### 3.1 Publish Request Validation
**RED**: Write request test
- [ ] `tests/Feature/Social/PublishRequestTest.php`
- [ ] Test: `platform` must be `youtube`|`instagram`, `clip_review_id` required/integer, `title` required, `description` optional
- [ ] Run test → fails

**GREEN**: Update form request
- [ ] `app/Http/Requests/PublishClipRequest.php`: change `'in:youtube'` → `'in:youtube,instagram'`
- [ ] Run test → passes

### 3.2 SocialPublishController (extend M10)
**RED**: Write controller tests
- [ ] `tests/Feature/Social/SocialPublishControllerTest.php`
- [ ] Test: `store` with platform="instagram" → 202 enqueues PublishToInstagram; platform="youtube" → 202 enqueues PublishToYouTube (regression)
- [ ] Test: `store` with no connected Instagram → 404
- [ ] Test: `store` with no connected YouTube → 404 (regression)
- [ ] Test: `store` 401 guest; 404 unapproved/not owned clip; 429 rate limit; 419 CSRF
- [ ] Test: `index()` paginated list, own publishes only; `show()` 404 non-owner
- [ ] Run test → fails

**GREEN**: Implement/extend controller
- [ ] `app/Http/Controllers/Api/V1/SocialPublishController.php`
- [ ] `store()`: resolve connection by `$validated['platform']`, create `SocialPublish` row, enqueue `PublishToInstagram` or `PublishToYouTube` based on platform, return 202
- [ ] `index()`, `show()` with ownership scoping (already M10, verify)
- [ ] Routes already registered in `routes/api.php` (M10): `POST /api/v1/social/publish`, `GET /api/v1/social/publishes`, `GET /api/v1/social/publishes/{publish}`
- [ ] Rate limiter 20/min on publish (already M10, verify)
- [ ] Run test → passes

### 3.3 SocialPublishResource (M10, reused)
- [ ] Verify strict whitelist, no storage internals, no tokens

---

## Phase 4: Frontend - PublishClipDialog Extension

### 4.1 API Client
- [ ] Verify `apps/web/src/features/social/api/index.ts` has `publishClip(platform, clipId, metadata)` supporting `platform="instagram"`
- [ ] Add tests if missing: `apps/web/src/features/social/api/index.test.ts`

### 4.2 PublishClipDialog
**RED**: Write component tests
- [ ] `apps/web/src/features/social/components/PublishClipDialog.test.tsx`
- [ ] Test: platform selector shows YouTube/Instagram, only connected platforms enabled, Instagram option disabled when no Instagram connection, submits with `platform="instagram"`
- [ ] Run test → fails

**GREEN**: Implement dialog changes
- [ ] `apps/web/src/features/social/components/PublishClipDialog.tsx`
- [ ] Platform selector (YouTube/Instagram)
- [ ] Disable platform without active connection
- [ ] Send `platform` to API
- [ ] Run test → passes

### 4.3 PublishCard / PublishList (M10, reused)
- [ ] Verify Instagram icon/label rendering in `PublishCard`
- [ ] Verify status badges include published/failed
- [ ] Add tests if coverage missing

---

## Phase 5: Integration & E2E Testing

### 5.1 Backend Integration Tests
- [ ] `tests/Feature/Social/SocialPublishIntegrationTest.php`
- [ ] Test: full Instagram publish flow → job (fake) → status=published → appears in GET /publishes
- [ ] Test: full YouTube publish flow → published (regression)
- [ ] Test: YouTube + Instagram both publishable
- [ ] Test: no connected Instagram → 404
- [ ] Test: unapproved clip → 404
- [ ] Test: cross-user 404
- [ ] Test: publish failure marks failed
- [ ] Test: tokens never in API response / logs

### 5.2 Frontend E2E Tests (Playwright)
- [ ] `apps/web/tests/e2e/social-publish.spec.ts`
- [ ] Test: PublishClipDialog open, select Instagram, submit, verify pending state
- [ ] Test: Instagram disabled when no connection
- [ ] Test: PublishList shows publish status transitions
- [ ] Test: 3 viewports (390x844, 768x1024, 1440x900)
- [ ] Test: console/network cleanliness
- [ ] Test: auth flow (register/login) works — prerequisite verification
- [ ] Run test → fails then passes

### 5.3 Governance & Lint
- [ ] `vendor/bin/pint --dirty --format agent` (backend)
- [ ] `npm run lint` (frontend)
- [ ] `npm run build` (TypeScript)
- [ ] `php artisan test --filter=Governance`

---

## Phase 6: Final Verification

### 6.1 Full Test Suite
- [ ] Backend: `php artisan test --compact` → all pass
- [ ] Frontend: `npm run test` → all pass
- [ ] E2E: `npm run test:e2e` → all pass at 3 viewports
- [ ] Governance: `php artisan test --filter=Governance` → all pass

### 6.2 Manual Verification Checklist
- [ ] Auth: register → 201, login → 200, csrf-cookie → 204, session persists
- [ ] Publish clip to Instagram (fake) → status pending → published
- [ ] Publish clip to YouTube still works (regression)
- [ ] No Instagram connection → dialog disables Instagram, API returns 404
- [ ] Unapproved clip → 404
- [ ] Cross-user publish → 404
- [ ] No tokens in browser dev tools / network
- [ ] No tokens in Laravel logs
- [ ] Keyboard navigation, focus indicators
- [ ] Rate limiting: exceed 20/min → 429

### 6.3 Evidence Collection
- [ ] Update `specs/120-social-publishing/evidence.md` (by Builder/Tester, not Planner)
- [ ] Record test results, screenshots, manual verification

---

## File Creation Summary

### Backend (Laravel)
| File | Purpose |
|------|---------|
| `app/Services/InstagramPublisher.php` | Instagram publisher implementation |
| `app/Services/FakeInstagramPublisher.php` | Test fake (testing env) |
| `app/Jobs/PublishToInstagram.php` | Queue job |
| `app/Http/Requests/PublishClipRequest.php` | Validation (extend platform rule) |
| `app/Http/Controllers/Api/V1/SocialPublishController.php` | Controller (extend M10, platform-agnostic) |
| `app/Providers/AppServiceProvider.php` | Add `:instagram` bindings |
| `app/Events/SocialPublished.php` | Event (verify M10 exists) |

### Tests (Backend)
| File | Purpose |
|------|---------|
| `tests/Feature/Auth/AuthFlowVerificationTest.php` | Auth 500 fix verification (csrf-cookie, register, login, session) |
| `tests/Unit/Contracts/SocialPublisherContractTest.php` | Contract |
| `tests/Unit/Social/FakeInstagramPublisherTest.php` | Fake |
| `tests/Unit/Social/InstagramPublisherTest.php` | Publisher |
| `tests/Unit/Jobs/PublishToInstagramTest.php` | Job |
| `tests/Feature/Social/PublishRequestTest.php` | Request |
| `tests/Feature/Social/SocialPublishControllerTest.php` | Controller |
| `tests/Feature/Social/SocialPublishIntegrationTest.php` | Integration |

### Frontend (React)
| File | Purpose |
|------|---------|
| `src/features/social/components/PublishClipDialog.tsx` | Dialog (extend) |
| `src/features/social/components/PublishClipDialog.test.tsx` | Dialog tests |
| `src/features/social/api/index.test.ts` | API tests (extend) |

### E2E
| File | Purpose |
|------|---------|
| `apps/web/tests/e2e/social-publish.spec.ts` | Playwright E2E |

---

## Branch & Commit Strategy

**Branch**: `@carlosegoulart/135/feat/instagram-publishing`

**Commits** (Conventional Commits), each with `Refs #135`:
1. `feat(social): add auth flow verification test (csrf-cookie, register, login)`
2. `feat(social): add FakeInstagramPublisher and testing binding`
3. `feat(social): add InstagramPublisher implementation`
4. `feat(social): add PublishToInstagram queue job`
5. `feat(social): extend social publish API for instagram platform`
6. `feat(social): extend PublishClipDialog for Instagram`
7. `test(social): add backend integration tests`
8. `test(social): add frontend E2E tests`
9. `chore(social): lint, governance, final verification`

---

## Security Rollback Plan

If any security change breaks CI:
1. Revert the specific file change
2. Document the issue in evidence.md
3. Investigate before retrying
