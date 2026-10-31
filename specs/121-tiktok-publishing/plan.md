# M12 — TikTok Publishing Implementation Plan

## Overview

This plan breaks down the M12 TikTok Publishing implementation into sequential, testable increments following TDD (RED → GREEN → REFACTOR). Each phase delivers a vertical slice that can be independently verified.

M12 builds on M9 (Social Connection Framework, Issue #118) and M10 (YouTube Publishing, Issue #119). The `SocialPublish` model, `SocialPublishStatus` enum, `SocialPublisher` contract, `PublishResult`/`PublishStatus` value objects, API endpoints, controller, resources, and `PublishToYouTube`/`PublishToInstagram` job topology already exist. M12 adds TikTok as a third platform by mirroring the Instagram implementation (the closest sibling).

Branch: `@carlosegoulart/121/feat/tiktok-publishing` (created by Orchestrator).

---

## Phase 0: Project Setup & Configuration

### 0.1 Verify Spec Directory

- [ ] Verify `specs/121-tiktok-publishing/` exists (spec.md, plan.md, test-plan.md created by Planner)

No new migrations, models, enums, or resources are required (all exist from M10/M9).

---

## Phase 1: Publisher & Job (Backend)

### 1.1 TikTokPublisher Implementation

**RED**: Write unit tests
- [ ] `tests/Unit/Services/TikTokPublisherTest.php`
- [ ] Test: `getPlatform()` returns `'tiktok'`
- [ ] Test: `getPlatformLabel()` returns `'TikTok'`
- [ ] Test: `publishVideo()` success → `PublishResult` (`status = 'published'`, `externalVideoId`, `videoUrl`)
- [ ] Test: `publishVideo()` failure → `PublishResult` (`status = 'failed'`, sanitized `errorMessage`)
- [ ] Test: `publishVideo()` never logs access token
- [ ] Test: `hasPublishPermission()` true for valid token with required scopes, false otherwise
- [ ] Test: `getPublishStatus()` returns `PublishStatus` for a published post
- [ ] Run test → fails

**GREEN**: Implement `app/Services/TikTokPublisher.php`
- [ ] Create `TikTokPublisher` implementing `App\Contracts\SocialPublisher`
- [ ] `getPlatform()` → `'tiktok'`
- [ ] `getPlatformLabel()` → `'TikTok'`
- [ ] `publishVideo(array $input)`: resolves connected TikTok `SocialConnection`, gets valid token via `SocialConnectionManager::getValidAccessToken()`, calls TikTok API, returns `PublishResult` (success: published; failure: failed with sanitized `errorMessage`), never logs tokens
- [ ] `hasPublishPermission(string $accessToken)`: verifies token validity and required scopes via TikTok provider
- [ ] `getPublishStatus(string $externalVideoId)`: checks status of published post
- [ ] Run test → passes

**REFACTOR**: Extract private methods, improve error handling, verify no token logging
- [ ] Run full backend test suite

### 1.2 FakeTikTokPublisher (Testing)

**RED**: Write fake provider tests
- [ ] `tests/Unit/Services/FakeTikTokPublisherTest.php`
- [ ] Test: `FakeTikTokPublisher` implements `SocialPublisher`
- [ ] Test: deterministic, configurable success/failure, no network calls
- [ ] Run test → fails

**GREEN**: Create fake provider
- [ ] Create `app/Services/FakeTikTokPublisher.php`
- [ ] Register in `AppServiceProvider` for testing environment
- [ ] Run test → passes

**REFACTOR**: Mirror `FakeYouTubePublisher`/`FakeInstagramPublisher` shape
- [ ] Run full backend test suite

### 1.3 PublishToTikTok Queue Job

**RED**: Write job tests (unit, with fake publisher + fake connection manager)
- [ ] `tests/Unit/Jobs/PublishToTikTokTest.php`
- [ ] Test: ownership re-validation
- [ ] Test: non-pending idempotency (skip if not pending)
- [ ] Test: pending → uploading → published transition on success
- [ ] Test: pending → uploading → failed transition on failure with sanitized error
- [ ] Test: `uniqueId()` returns `social-publish:{publishId}`
- [ ] Test: `tries = 3`, `backoff = 5`
- [ ] Test: no valid token → failed
- [ ] Test: no completed rendered clip → failed
- [ ] Test: connection platform is not tiktok → failed
- [ ] Run test → fails

**GREEN**: Implement job per spec §3 / §6
- [ ] Create `app/Jobs/PublishToTikTok.php` following `PublishToYouTube`/`PublishToInstagram` topology
- [ ] Run test → passes

**REFACTOR**: Extract private methods, improve error handling
- [ ] Run full backend test suite

---

## Phase 2: Provider Registration & API Extension (Backend)

### 2.1 Publisher Registration in AppServiceProvider

- [ ] Bind `SocialPublisher::class . ':tiktok'` → `TikTokPublisher::class` (production)
- [ ] Override with `FakeTikTokPublisher::class` in testing environment
- [ ] Verify container resolution `app(SocialPublisher::class . ':tiktok')` returns correct implementation per environment

### 2.2 Request Validation Extension

- [ ] Extend `PublishClipRequest` validation: `platform in:youtube,instagram,tiktok`
- [ ] Run `PublishClipRequestTest` → passes (include tiktok in valid platforms)

### 2.3 Controller Extension

- [ ] Extend `SocialPublishController@store` to dispatch `PublishToTikTok` for platform `tiktok` (mirror instagram branch)
- [ ] Run `SocialPublishControllerTest` → passes

### 2.4 Resource Extension

- [ ] Extend `SocialPublishResource::getPlatformLabel` with `'tiktok' => 'TikTok'`
- [ ] Run `SocialPublishResourceTest` → passes

### 2.5 Config Verification

- [ ] Verify `config/social.php` has tiktok provider config (scopes `video.publish`, `user.info.basic`, `client_id`, `client_secret`, `redirect_uri`) — already present from M9; no change needed unless gaps found

**REFACTOR**: Verify all error paths, run full backend test suite
- [ ] Run `php artisan test --compact` → all pass

---

## Phase 3: Integration & E2E Testing (Backend)

### 3.1 Integration Tests

**RED**: Write integration tests
- [ ] `tests/Feature/SocialPublish/SocialPublishTikTokIntegrationTest.php`
- [ ] Test: full publish flow (POST → job runs inline → published)
- [ ] Test: publish uses encrypted token (never in response/logs)
- [ ] Test: failed publish stores sanitized `error_message`
- [ ] Test: duplicate publish blocked (409)
- [ ] Test: cross-user publish 404
- [ ] Run test → fails

**GREEN**: Ensure all integration paths work
- [ ] Run test → passes

---

## Phase 4: Frontend

### 4.1 Frontend Types

- [ ] Update `apps/web/src/features/social-publish/types.ts`:
  - `SocialPublish.platform`: `'youtube' | 'instagram' | 'tiktok'`
  - `PublishClipRequest.platform`: `'youtube' | 'instagram' | 'tiktok'`
  - `PublishResponse.platform`: `'youtube' | 'instagram' | 'tiktok'`
  - `PublishStatusResponse.platform`: `'youtube' | 'instagram' | 'tiktok'`

### 4.2 PublishClipDialog Platform Selector

- [ ] Extend `PublishClipDialog.tsx` `Platform` type to include `'tiktok'`
- [ ] Add TikTok entry to `PLATFORM_CONFIG` (`label: 'TikTok'`, `ariaLabel: 'Publish to TikTok'`)
- [ ] Default `availablePlatforms` remains caller-provided (only connected platforms passed in)
- [ ] TikTok option renders when `'tiktok'` in `availablePlatforms`
- [ ] Selecting TikTok updates platform state and dialog title
- [ ] Run Vitest component tests → pass

### 4.3 Frontend Tests

- [ ] `apps/web/src/features/social-publish/components/PublishClipDialog.test.tsx`:
  - Renders TikTok option when in `availablePlatforms`
  - Does not render TikTok option when not in `availablePlatforms`
  - Selecting TikTok updates platform state
  - Publish button disabled during loading
  - Accessibility: keyboard navigation, focus indicators, ARIA labels
- [ ] Run Vitest → all pass

**REFACTOR**: Verify styling/accessibility per design tokens
- [ ] Run frontend lint (eslint) → clean
- [ ] Run frontend build → clean

---

## Phase 5: E2E Testing (Playwright)

### 5.1 Playwright E2E Tests

**RED**: Write Playwright tests
- [ ] `apps/web/tests/e2e/tiktok-publish.spec.ts` (or extend existing social-publish spec)
- [ ] Test: publish approved clip to TikTok (fake) → appears in publish list
- [ ] Test: publish status reflection (pending → published)
- [ ] Test: failed publish shows error message
- [ ] Test: duplicate publish blocked (409)
- [ ] Test: no TikTok connection → publish button not shown / 404
- [ ] Test: non-approved clip → publish not available
- [ ] Test: no tokens in browser dev tools / network
- [ ] Test: keyboard navigation works throughout
- [ ] Test: focus indicators visible
- [ ] Test: 3 viewports (390x844, 768x1024, 1440x900)
- [ ] Test: console/network cleanliness
- [ ] Run test → fails

**GREEN**: Fix implementation to pass E2E
- [ ] Run test → passes

---

## Phase 6: Governance, Lint & Final Verification

### 6.1 Governance & Lint

- [ ] Run `vendor/bin/pint --dirty --format agent` (backend) → clean
- [ ] Run frontend lint (eslint) → clean
- [ ] Run frontend build → clean
- [ ] Run governance tests (`php artisan test --filter=Governance`) → all pass

### 6.2 Final Verification

- [ ] Backend: `php artisan test --compact` → all pass
- [ ] Frontend: `npm run test` → all pass
- [ ] E2E: `npm run test:e2e` → all pass at 3 viewports
- [ ] Governance: `php artisan test --filter=Governance` → all pass

### 6.3 Manual Verification Checklist

- [ ] Publish approved clip to TikTok (fake) → appears in publish list
- [ ] Publish status reflects published with video link
- [ ] Failed publish shows error message
- [ ] Duplicate publish blocked (409)
- [ ] No TikTok connection → publish button not shown / 404
- [ ] Non-approved clip → publish not available
- [ ] No tokens in browser dev tools / network
- [ ] No tokens in Laravel logs
- [ ] Keyboard navigation works throughout
- [ ] Focus indicators visible

### 6.4 Evidence Collection

- [ ] Update `specs/121-tiktok-publishing/evidence.md` (Orchestrator commits — Planner cannot edit evidence.md)
- [ ] Record test results, screenshots, manual verification

---

## File Creation Summary

### Backend (Laravel)
| File | Purpose |
|------|---------|
| `app/Services/TikTokPublisher.php` | TikTok publisher (SocialPublisher impl) |
| `app/Services/FakeTikTokPublisher.php` | Test fake (testing env only) |
| `app/Jobs/PublishToTikTok.php` | Publish queue job |

### Backend (modified, existing files)
| File | Change |
|------|--------|
| `app/Providers/AppServiceProvider.php` | Bind `SocialPublisher::class . ':tiktok'` (prod: TikTokPublisher; test: FakeTikTokPublisher) |
| `app/Http/Requests/PublishClipRequest.php` | `platform in:youtube,instagram,tiktok` |
| `app/Http/Controllers/Api/V1/SocialPublishController.php` | Dispatch `PublishToTikTok` for platform `tiktok` |
| `app/Http/Resources/SocialPublishResource.php` | `getPlatformLabel`: `'tiktok' => 'TikTok'` |
| `config/social.php` | TikTok provider config (already present from M9) |

### Frontend (React)
| File | Purpose |
|------|---------|
| `apps/web/src/features/social-publish/types.ts` | Add `'tiktok'` to platform union types |
| `apps/web/src/features/social-publish/components/PublishClipDialog.tsx` | Add TikTok to `PLATFORM_CONFIG`, `Platform` type |

### Tests
| File | Purpose |
|------|---------|
| `tests/Unit/Services/TikTokPublisherTest.php` | TikTok publisher unit tests |
| `tests/Unit/Services/FakeTikTokPublisherTest.php` | Fake publisher tests |
| `tests/Unit/Jobs/PublishToTikTokTest.php` | Publish job unit tests |
| `tests/Feature/SocialPublish/SocialPublishTikTokIntegrationTest.php` | TikTok integration tests |
| `apps/web/src/features/social-publish/components/PublishClipDialog.test.tsx` | Dialog Vitest tests (incl. TikTok) |
| `apps/web/tests/e2e/tiktok-publish.spec.ts` | Playwright E2E (3 viewports) |

---

## Branch & Commit Strategy

**Branch**: `@carlosegoulart/121/feat/tiktok-publishing`

**Commits** (Conventional Commits):
1. `feat(social-publish): add TikTokPublisher and PublishToTikTok job`
2. `feat(social-publish): add FakeTikTokPublisher and container registration`
3. `feat(social-publish): extend API for tiktok platform (request, controller, resource)`
4. `feat(social-publish): extend frontend PublishClipDialog with TikTok platform`
5. `test(social-publish): add TikTok unit and integration tests`
6. `test(social-publish): add frontend Vitest and Playwright E2E tests`
7. `docs(social-publish): document TikTok publishing implementation`

Each commit references: `Refs #121`
