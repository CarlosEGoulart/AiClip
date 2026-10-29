# M11 — Instagram Publishing Implementation Plan

## Overview

This plan breaks the implementation into sequential, testable increments following TDD (RED → GREEN → REFACTOR). Each phase delivers a vertical slice that can be independently verified. Issue #135 (GitHub) / specs/120-social-publishing.

M11 reuses the **exact** M10 `SocialPublisher` interface (`publishVideo`, `hasPublishPermission`, `getPlatformLabel`), `PublishResult` value object, `SocialPublish` model, and routes — adding Instagram alongside YouTube.

This plan also includes:
- **Auth 500 fix**: remove duplicate `EncryptCookies` from `bootstrap/app.php`.
- **Frontend redesign**: modernize `PublishClipDialog`, `PublishCard`, `PublishList`, `SocialConnections` using the `design-taste-frontend` skill process.

Branch: `@carlosegoulart/135/feat/instagram-publishing`

---

## Phase 0: Auth Infrastructure Fix — `laravel_session` cookie encryption

### Root cause

`apps/api/bootstrap/app.php` registers `EncryptCookies` exactly once (duplicate registration already removed in a prior round):

```php
$middleware->statefulApi();
$middleware->encryptCookies(except: ['XSRF-TOKEN']);
$middleware->validateCsrfTokens(except: ['api/v1/projects/*/media/upload']);
```

`XSRF-TOKEN` is excepted from encryption, but `laravel_session` is **not**. Laravel encrypts `laravel_session` on the response; when read back manually via `Cookie::fromString()` (which does not decrypt) by the `SpaRequests` test helper or frontend cookie handling, the session cannot be restored. This causes CSRF mismatch (419) on every authenticated state-changing request (POST/PATCH/DELETE).

### 0.1 Write auth flow verification test (RED)

- [ ] `tests/Feature/Auth/AuthFlowVerificationTest.php`
- [ ] Test: `GET /sanctum/csrf-cookie` → 204
- [ ] Test: `POST /api/v1/auth/register` → 201
- [ ] Test: `POST /api/v1/auth/login` → 200
- [ ] Test: session persists across requests (`GET /api/v1/auth/me` → 200 after login)
- [ ] Test: no 419 CSRF mismatch on authenticated POST/PATCH/DELETE
- [ ] Run test → **fails with 419** (confirms `laravel_session` encryption defect)

### 0.2 Add `laravel_session` to `except` list (GREEN)

- [ ] Edit `apps/api/bootstrap/app.php` line 17:
  - From: `$middleware->encryptCookies(except: ['XSRF-TOKEN']);`
  - To: `$middleware->encryptCookies(except: ['XSRF-TOKEN', 'laravel_session']);`
- [ ] Run `AuthFlowVerificationTest` → **passes** (204, 201, 200, session persists, no 419)

### 0.3 Verify supporting config (no code change needed)

- [ ] Confirm `0001_01_01_000000_create_users_table.php` creates `sessions` table
- [ ] Confirm `2026_10_27_000000_create_sessions_table.php` is intentional no-op
- [ ] Confirm `apps/api/.env.example` has non-empty `APP_KEY`
- [ ] Confirm `apps/api/.env.example` `SANCTUM_STATEFUL_DOMAINS` includes the Cloud Shell dev domain
- [ ] Confirm `SESSION_DRIVER=database`

### 0.4 REFACTOR

- [ ] None (config-only fix)

---

## Phase 0.5: Social Configuration (Instagram)

### 0.5.1 Verify config/social.php

- [ ] Verify `apps/api/config/social.php` has `instagram` provider entry (client_id, client_secret, redirect_uri, scopes: `instagram_content_publish`, `instagram_basic`) — already present from M9/M10; verify only.

---

## Phase 1: InstagramPublisher (Backend)

### 1.1 SocialPublisher Contract Confirmation (RED)

- [ ] `tests/Unit/Contracts/SocialPublisherContractTest.php`
- [ ] Test: interface exists, `getPlatform()`, `getPlatformLabel()`, `publishVideo(array): PublishResult`, `getPublishStatus(string): PublishStatus`, `hasPublishPermission(string): bool`
- [ ] Run test → fails (or confirm M10 already covers)

### 1.2 Confirm M10 contract exists (GREEN)

- [ ] Confirm `app/Contracts/SocialPublisher.php`, `PublishResult`, `PublishStatus` exist from M10 (unchanged)
- [ ] Run test → passes

### 1.3 FakeInstagramPublisher (RED)

- [ ] `tests/Unit/Social/FakeInstagramPublisherTest.php`
- [ ] Test: implements `SocialPublisher`, `getPlatform()` returns 'instagram', `getPlatformLabel()` returns 'Instagram', `publishVideo()` returns deterministic `PublishResult`, `hasPublishPermission()` returns bool, `getPublishStatus()` returns PublishStatus, no network calls
- [ ] Run test → fails

### 1.4 Create FakeInstagramPublisher (GREEN)

- [ ] `app/Services/FakeInstagramPublisher.php`
- [ ] Register in `AppServiceProvider` for testing: `SocialPublisher::class . ':instagram'` → `FakeInstagramPublisher::class` when `app()->environment('testing')`
- [ ] Run test → passes

### 1.5 InstagramPublisher (Real) (RED)

- [ ] `tests/Unit/Social/InstagramPublisherTest.php`
- [ ] Test: `getPlatform()` returns 'instagram', `getPlatformLabel()` returns 'Instagram', `publishVideo()` calls Graph API flow, `hasPublishPermission()` checks scopes/token, `getPublishStatus()` checks post, error messages sanitized (no tokens)
- [ ] Run test → fails

### 1.6 Implement InstagramPublisher (GREEN)

- [ ] `app/Services/InstagramPublisher.php`
- [ ] Inject `SocialConnectionManager` and HTTP client
- [ ] Implement `publishVideo()`: Instagram Graph API media + media_publish flow
- [ ] Implement `hasPublishPermission()`
- [ ] Implement `getPublishStatus()`
- [ ] Register real binding in `AppServiceProvider`: `SocialPublisher::class . ':instagram'` → `InstagramPublisher::class` (non-testing)
- [ ] Run test → passes

### 1.7 REFACTOR

- [ ] Extract private methods, sanitize error messages, verify no token leakage
- [ ] Run full unit test suite

---

## Phase 2: PublishToInstagram Job (Backend)

### 2.1 Job (RED)

- [ ] `tests/Unit/Jobs/PublishToInstagramTest.php`
- [ ] Test: revalidates ownership/approval, resolves `SocialPublisher::class . ':instagram'`, calls `publishVideo()`, marks published on success, marks failed + error_message on failure, retries with backoff, idempotent skip
- [ ] Run test → fails

### 2.2 Implement job (GREEN)

- [ ] `app/Jobs/PublishToInstagram.php`
- [ ] `handle(int $publishId)`: resolve `SocialPublisher::class . ':instagram'`, call `publishVideo()`, update `SocialPublish` row
- [ ] Retry configuration: `tries = 3`, `backoff = 5`, `uniqueId()` = `social-publish:{publishId}`
- [ ] Run test → passes

### 2.3 REFACTOR

- [ ] Extract private methods, verify idempotency logic
- [ ] Run full unit test suite

---

## Phase 3: API Endpoints (Backend)

### 3.1 Publish Request Validation (RED)

- [ ] `tests/Feature/Social/PublishRequestTest.php`
- [ ] Test: `platform` must be `youtube`|`instagram`, `clip_review_id` required/integer, `title` required, `description` optional
- [ ] Run test → fails

### 3.2 Update form request (GREEN)

- [ ] `app/Http/Requests/PublishClipRequest.php`: change `'in:youtube'` → `'in:youtube,instagram'`
- [ ] Run test → passes

### 3.3 SocialPublishController (RED)

- [ ] `tests/Feature/Social/SocialPublishControllerTest.php`
- [ ] Test: `store` with platform="instagram" → 202 enqueues PublishToInstagram; platform="youtube" → 202 enqueues PublishToYouTube (regression)
- [ ] Test: `store` with no connected Instagram → 404
- [ ] Test: `store` with no connected YouTube → 404 (regression)
- [ ] Test: `store` 401 guest; 404 unapproved/not owned clip; 429 rate limit; 419 CSRF
- [ ] Test: `index()` paginated list, own publishes only; `show()` 404 non-owner
- [ ] Run test → fails

### 3.4 Extend controller (GREEN)

- [ ] `app/Http/Controllers/Api/V1/SocialPublishController.php`
- [ ] `store()`: resolve connection by `$validated['platform']`, create `SocialPublish` row, enqueue `PublishToInstagram` or `PublishToYouTube` based on platform, return 202
- [ ] `index()`, `show()` with ownership scoping (already M10, verify)
- [ ] Routes already registered in `routes/api.php` (M10): `POST /api/v1/social/publish`, `GET /api/v1/social/publishes`, `GET /api/v1/social/publishes/{publish}`
- [ ] Rate limiter 20/min on publish (already M10, verify)
- [ ] Run test → passes

### 3.5 SocialPublishResource (M10, reused)

- [ ] Verify strict whitelist, no storage internals, no tokens

---

## Phase 4: Frontend Design — Publishing Components (using design-taste-frontend skill)

### 4.1 Design Direction (art direction from design-taste-frontend skill)

- [ ] Load `design-taste-frontend` skill; select researched direction (idea, composition, type, palette, photographs, section blueprints)
- [ ] Define design direction for publishing components: name, palette, typography, layout archetype, "live thing" interaction
- [ ] Document house style compliance: square corners, Switzer type, mono-accent palette, flat buttons, visible focus rings, 44px touch targets, color contrast ≥ 4.5:1, no generic/templated appearance

### 4.2 PublishClipDialog Redesign (RED)

- [ ] `apps/web/src/features/social-publish/components/PublishClipDialog.test.tsx`
- [ ] Test: platform selector shows YouTube/Instagram with modern styling, only connected platforms enabled, Instagram option disabled when no Instagram connection, submits with `platform="instagram"`, keyboard navigation, focus indicators, ARIA labels
- [ ] Run test → fails

### 4.3 PublishClipDialog Redesign (GREEN)

- [ ] `apps/web/src/features/social-publish/components/PublishClipDialog.tsx` — rebuild with selected design direction
- [ ] `apps/web/src/features/social-publish/design-tokens.css` — update design tokens for modern publishing design
- [ ] Platform selector (YouTube/Instagram) with modern styling
- [ ] Disable platform without active connection with tooltip
- [ ] Send `platform` to API
- [ ] Run test → passes

### 4.4 PublishCard Redesign (RED)

- [ ] `apps/web/src/features/social-publish/components/PublishCard.test.tsx`
- [ ] Test: renders Instagram icon/label with modern styling, status badges with modern design, published/failed states
- [ ] Run test → fails

### 4.5 PublishCard Redesign (GREEN)

- [ ] `apps/web/src/features/social-publish/components/PublishCard.tsx` — rebuild with modern card design
- [ ] Instagram icon/label, status badge (pending/uploading/published/failed)
- [ ] Run test → passes

### 4.6 PublishList Redesign (RED)

- [ ] `apps/web/src/features/social-publish/components/PublishList.test.tsx`
- [ ] Test: renders loading skeletons, empty state with CTA, list of cards, optional platform filter
- [ ] Run test → fails

### 4.7 PublishList Redesign (GREEN)

- [ ] `apps/web/src/features/social-publish/components/PublishList.tsx` — rebuild with modern list design
- [ ] Loading skeletons, empty state, platform filter
- [ ] Run test → passes

### 4.8 SocialConnections Redesign (RED)

- [ ] `apps/web/src/features/social/components/SocialConnections.test.tsx`
- [ ] `apps/web/src/features/social/components/SocialConnectionCard.test.tsx`
- [ ] Test: modern hero section, connection cards as interface fragments, connect/disconnect/retry actions, keyboard navigation, focus indicators
- [ ] Run test → fails

### 4.9 SocialConnections Redesign (GREEN)

- [ ] `apps/web/src/features/social/components/SocialConnections.tsx` — rebuild with modern design
- [ ] `apps/web/src/features/social/components/SocialConnectionCard.tsx` — modern card
- [ ] `apps/web/src/features/social/design-tokens.css` — update design tokens
- [ ] Run test → passes

### 4.10 REFACTOR (design polish)

- [ ] Refine spacing, typography, color, hover/focus states
- [ ] Verify no token leakage, no secrets in UI
- [ ] Run `npm run lint` (ESLint) → 0 errors in social-publish/social files
- [ ] Run `npm run build` (TypeScript) → compiles without errors

---

## Phase 5: Visual QA (playwright-visual-qa + web-design-guidelines)

### 5.1 Visual review at 3 viewports

- [ ] Run `playwright-visual-qa` on PublishClipDialog at 390x844, 768x1024, 1440x900
- [ ] Run `playwright-visual-qa` on PublishCard/PublishList at 390x844, 768x1024, 1440x900
- [ ] Run `playwright-visual-qa` on SocialConnections at 390x844, 768x1024, 1440x900
- [ ] Run `web-design-guidelines` review on all redesigned components
- [ ] Fix visual defects: layout, overflow, spacing, alignment, typography, responsiveness, dialogs, loading states, empty states, error states, success states, focus behavior, keyboard navigation, media sizing, disabled states, navigation behavior

### 5.2 Console/Network cleanliness

- [ ] No unexpected console errors
- [ ] No unexpected 4xx/5xx API responses
- [ ] No token leakage in network requests

---

## Phase 6: Integration & E2E Testing

### 6.1 Backend Integration Tests

- [ ] `tests/Feature/Social/SocialPublishIntegrationTest.php`
- [ ] Test: full Instagram publish flow → job (fake) → status=published → appears in GET /publishes
- [ ] Test: full YouTube publish flow → published (regression)
- [ ] Test: YouTube + Instagram both publishable
- [ ] Test: no connected Instagram → 404
- [ ] Test: unapproved clip → 404
- [ ] Test: cross-user 404
- [ ] Test: publish failure marks failed
- [ ] Test: publish retry on transient failure
- [ ] Test: SocialPublished event dispatched
- [ ] Test: tokens never in API response / logs

### 6.2 Frontend E2E Tests (Playwright)

- [ ] `apps/web/tests/e2e/social-publish.spec.ts`
- [ ] Test: Auth flow — register → login → session persists (prerequisite) — all 3 viewports
- [ ] Test: Auth — CSRF cookie → 204 — all 3 viewports
- [ ] Test: Publish Clip — Open Dialog — all 3 viewports
- [ ] Test: Publish Clip — Select Instagram — all 3 viewports
- [ ] Test: Publish Clip — Instagram disabled when no connection — all 3 viewports
- [ ] Test: Publish Clip — Submit Instagram → pending state → all 3 viewports
- [ ] Test: Publish Clip — Submit YouTube (regression) — all 3 viewports
- [ ] Test: Publish List — Shows publish — all 3 viewports
- [ ] Test: Publish List — Status transition pending → published — all 3 viewports
- [ ] Test: Publish Card — Published link — all 3 viewports
- [ ] Test: Console cleanliness — all 3 viewports
- [ ] Test: Network cleanliness — all 3 viewports
- [ ] Test: Keyboard navigation — all 3 viewports
- [ ] Test: Focus indicators — all 3 viewports
- [ ] Test: Responsive layout — all 3 viewports
- [ ] Test: Touch targets ≥ 44px — all 3 viewports

### 6.3 Fake Publisher Strategy for E2E

- `FakeInstagramPublisher` bound in testing environment returns deterministic success.
- Job runs via queue worker in test environment; publish status transitions pending → published.
- E2E polls PublishList for status change.

### 6.4 Governance & Lint

- [ ] `vendor/bin/pint --dirty --format agent` (backend) → 0 errors
- [ ] `npm run lint` (frontend) → 0 errors in social-publish/social files
- [ ] `npm run build` (TypeScript) → compiles without errors
- [ ] `php artisan test --filter=Governance` → all pass

---

## Phase 7: Final Verification

### 7.1 Full Test Suite

- [ ] Backend: `php artisan test --compact` → all pass
- [ ] Frontend: `npm run test` → all pass
- [ ] E2E: `npm run test:e2e` → all pass at 3 viewports
- [ ] Governance: `php artisan test --filter=Governance` → all pass

### 7.2 Manual Verification Checklist

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
- [ ] Visual QA passes at 3 viewports
- [ ] web-design-guidelines review passes

### 7.3 Evidence Collection

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
| `apps/api/bootstrap/app.php` | Remove duplicate EncryptCookies (auth 500 fix) |

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

### Frontend (React) — Design + Tests

| File | Purpose |
|------|---------|
| `apps/web/src/features/social-publish/components/PublishClipDialog.tsx` | Dialog (redesign + Instagram platform) |
| `apps/web/src/features/social-publish/components/PublishClipDialog.test.tsx` | Dialog tests |
| `apps/web/src/features/social-publish/components/PublishCard.tsx` | Card (redesign + Instagram) |
| `apps/web/src/features/social-publish/components/PublishCard.test.tsx` | Card tests |
| `apps/web/src/features/social-publish/components/PublishList.tsx` | List (redesign) |
| `apps/web/src/features/social-publish/components/PublishList.test.tsx` | List tests |
| `apps/web/src/features/social-publish/design-tokens.css` | Design tokens (modern publishing) |
| `apps/web/src/features/social-publish/api/index.test.ts` | API tests (extend) |
| `apps/web/src/features/social/components/SocialConnections.tsx` | Connections page (redesign) |
| `apps/web/src/features/social/components/SocialConnectionCard.tsx` | Connection card (redesign) |
| `apps/web/src/features/social/components/SocialConnections.test.tsx` | Connections tests |
| `apps/web/src/features/social/components/SocialConnectionCard.test.tsx` | Connection card tests |
| `apps/web/src/features/social/design-tokens.css` | Design tokens (modern social connections) |

### E2E

| File | Purpose |
|------|---------|
| `apps/web/tests/e2e/social-publish.spec.ts` | Playwright E2E (auth + Instagram publishing, 3 viewports) |

---

## Branch & Commit Strategy

**Branch**: `@carlosegoulart/135/feat/instagram-publishing`

**Commits** (Conventional Commits), each with `Refs #135`:

1. `fix(auth): remove duplicate EncryptCookies middleware causing csrf-cookie 500`
2. `feat(social): add auth flow verification test (csrf-cookie, register, login)`
3. `feat(social): add FakeInstagramPublisher and testing binding`
4. `feat(social): add InstagramPublisher implementation`
5. `feat(social): add PublishToInstagram queue job`
6. `feat(social): extend social publish API for instagram platform`
7. `feat(web): redesign PublishClipDialog with modern design + Instagram platform`
8. `feat(web): redesign PublishCard with modern design + Instagram badge`
9. `feat(web): redesign PublishList with modern design`
10. `feat(web): redesign SocialConnections with modern design`
11. `test(social): add backend integration tests`
12. `test(social): add frontend E2E tests`
13. `chore(social): lint, governance, final verification`

---

## Security Rollback Plan

If any security change breaks CI:
1. Revert the specific file change
2. Document the issue in evidence.md
3. Investigate before retrying