# M12 — TikTok Publishing Test Plan

## Overview

This test plan defines the verification strategy for M12 TikTok Publishing. It follows the repository's TDD (RED → GREEN → REFACTOR) policy and AGENTS.md §13–§14 testing requirements.

TikTok publishing reuses the existing M10/M11 infrastructure (SocialPublish model, SocialPublisher contract, PublishToYouTube/PublishToInstagram job topology, API endpoints, controller, resources). Tests focus on the TikTok-specific additions: `TikTokPublisher`, `PublishToTikTok` job, `FakeTikTokPublisher`, container registration, API validation for `platform=tiktok`, frontend dialog extension, and E2E flows.

Test environments must use deterministic fakes only — no real TikTok API credentials, no network calls to TikTok, per AGENTS.md §21 and spec §8.5.

---

## 1. Backend Unit Tests (Pest)

### 1.1 TikTokPublisherTest

File: `tests/Unit/Services/TikTokPublisherTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.1.1 | `getPlatform()` | returns `'tiktok'` |
| 1.1.2 | `getPlatformLabel()` | returns `'TikTok'` |
| 1.1.3 | `publishVideo()` success | returns `PublishResult` with `status = 'published'`, non-empty `externalVideoId`, non-empty `videoUrl` |
| 1.1.4 | `publishVideo()` provider failure | returns `PublishResult` with `status = 'failed'`, sanitized `errorMessage` (no tokens) |
| 1.1.5 | `publishVideo()` no valid token | returns `PublishResult` with `status = 'failed'`, `errorMessage = 'No valid access token available'` |
| 1.1.6 | `publishVideo()` never logs access token | log context contains no `access_token`, `refresh_token`, or token values |
| 1.1.7 | `hasPublishPermission()` valid token with required scopes | returns `true` |
| 1.1.8 | `hasPublishPermission()` invalid/empty token | returns `false` |
| 1.1.9 | `getPublishStatus()` published post | returns `PublishStatus` with `status = 'published'`, `externalVideoId` set |
| 1.1.10 | `getPublishStatus()` unknown post | returns `PublishStatus` with `status = 'not_found'` or `'failed'` |

### 1.2 FakeTikTokPublisherTest

File: `tests/Unit/Services/FakeTikTokPublisherTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.2.1 | `FakeTikTokPublisher` implements `SocialPublisher` | `instanceof SocialPublisher` is `true` |
| 1.2.2 | `getPlatform()` | returns `'tiktok'` |
| 1.2.3 | `getPlatformLabel()` | returns `'TikTok'` |
| 1.2.4 | `publishVideo()` success (default) | returns `PublishResult` `status = 'published'` |
| 1.2.5 | `publishVideo()` failure (constructed with success=false) | returns `PublishResult` `status = 'failed'`, sanitized `errorMessage` |
| 1.2.6 | No network calls | fake makes no HTTP requests (verified via Http::fake or no network) |
| 1.2.7 | Deterministic | same constructor args → same results across calls |

### 1.3 PublishToTikTokTest

File: `tests/Unit/Jobs/PublishToTikTokTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.3.1 | Publish not found | job logs warning and returns (no exception) |
| 1.3.2 | Non-pending publish skipped (idempotency) | status unchanged, job returns early |
| 1.3.3 | Ownership validation failed (connection user_id != publish user_id) | status → `failed`, sanitized `error_message` |
| 1.3.4 | Connection platform is not tiktok | status → `failed`, sanitized `error_message` |
| 1.3.5 | No valid access token | status → `failed`, `error_message = 'No valid access token available'` |
| 1.3.6 | No completed rendered clip | status → `failed`, `error_message = 'No completed rendered clip found'` |
| 1.3.7 | Success path | status → `published`, `external_video_id` and `video_url` set |
| 1.3.8 | Publisher returns failed | status → `failed`, sanitized `error_message` |
| 1.3.9 | Publisher throws exception | status → `failed`, sanitized `error_message` (no exception leak) |
| 1.3.10 | `uniqueId()` | returns `social-publish:{publishId}` |
| 1.3.11 | `tries` | equals `3` |
| 1.3.12 | `backoff` | equals `5` |

---

## 2. Backend Feature Tests (Pest)

### 2.1 PublishClipRequestTest

File: `tests/Feature/SocialPublish/PublishClipRequestTest.php` (extend existing)

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 2.1.1 | `platform=tiktok` valid | passes validation |
| 2.1.2 | `platform=youtube` valid | passes validation |
| 2.1.3 | `platform=instagram` valid | passes validation |
| 2.1.4 | `platform=invalid` | 422 validation error |
| 2.1.5 | Missing `clip_review_id` | 422 validation error |
| 2.1.6 | Missing `title` | 422 validation error |
| 2.1.7 | `title` > 255 chars | 422 validation error |

### 2.2 SocialPublishControllerTest

File: `tests/Feature/SocialPublish/SocialPublishControllerTest.php` (extend existing)

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 2.2.1 | POST publish `platform=tiktok`, approved clip, connected TikTok | 202 Accepted, job enqueued, `SocialPublish` status `pending` |
| 2.2.2 | POST publish unauthenticated | 401 |
| 2.2.3 | POST publish non-owned clip review | 404 (hides existence) |
| 2.2.4 | POST publish not-approved clip review | 404 |
| 2.2.5 | POST publish no completed rendered clip | 404 |
| 2.2.6 | POST publish no connected TikTok connection | 404 (message hides platform existence generically) |
| 2.2.7 | POST publish duplicate (existing publish for clip_review+platform) | 409 |
| 2.2.8 | POST publish rate limited (>20/min) | 429 |
| 2.2.9 | POST publish CSRF missing/invalid | 419 |
| 2.2.10 | GET publishes list | 200, user-scoped (only own publishes) |
| 2.2.11 | GET publishes/{publish} owned | 200, resource shape correct, `platform_label = 'TikTok'` |
| 2.2.12 | GET publishes/{publish} non-owned | 404 (hides existence) |

### 2.3 SocialPublishResourceTest

File: `tests/Feature/SocialPublish/SocialPublishResourceTest.php` (extend existing)

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 2.3.1 | Resource includes `platform_label` | `'tiktok'` → `'TikTok'` |
| 2.3.2 | Resource does not include tokens | no `access_token`, `refresh_token` fields |

---

## 3. Backend Integration Tests (Pest)

### 3.1 SocialPublishTikTokIntegrationTest

File: `tests/Feature/SocialPublish/SocialPublishTikTokIntegrationTest.php`

Uses `FakeTikTokPublisher` (bound in testing env) and database transactions/rollback. No network.

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 3.1.1 | Full publish flow (POST → job runs inline via `Bus::fake` or sync queue → published) | 202 → `SocialPublish` status `published`, `external_video_id` and `video_url` set |
| 3.1.2 | Publish uses encrypted token (token never in response) | response JSON contains no token values |
| 3.1.3 | Publish token never in logs | log records contain no `access_token`/`refresh_token` |
| 3.1.4 | Failed publish stores sanitized `error_message` | `error_message` present, no tokens |
| 3.1.5 | Duplicate publish blocked (409) | second POST for same clip_review+platform → 409 |
| 3.1.6 | Cross-user publish 404 | user B attempts publish on user A's clip → 404 |

---

## 4. Frontend Unit Tests (Vitest)

### 4.1 PublishClipDialog.test.tsx

File: `apps/web/src/features/social-publish/components/PublishClipDialog.test.tsx`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 4.1.1 | Renders TikTok option when `'tiktok'` in `availablePlatforms` | TikTok radio option present |
| 4.1.2 | Does not render TikTok option when not in `availablePlatforms` | TikTok radio option absent |
| 4.1.3 | Selecting TikTok updates platform state | `platform === 'tiktok'`, dialog title "Publish to TikTok" |
| 4.1.4 | Publish button disabled during loading | button `disabled` when `loading = true` |
| 4.1.5 | Publish button disabled when title empty | button `disabled` when title blank |
| 4.1.6 | Accessibility: keyboard navigation | Tab cycles through platform options, title input, buttons |
| 4.1.7 | Accessibility: focus indicators | visible focus ring on interactive elements |
| 4.1.8 | Accessibility: ARIA labels | platform radio has `aria-label`, publish button has `aria-label` |

---

## 5. Frontend E2E Tests (Playwright)

File: `apps/web/tests/e2e/tiktok-publish.spec.ts` (or extend existing social-publish spec)

Default viewports: `390x844`, `768x1024`, `1440x900` (run all tests at all 3 viewports).

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 5.1 | Publish approved clip to TikTok (fake) → appears in publish list | publish card shows platform TikTok, status published |
| 5.2 | Publish status reflection (pending → published) | status badge updates after job completes |
| 5.3 | Failed publish shows error message | error state rendered, understandable message |
| 5.4 | Duplicate publish blocked (409) | second publish attempt rejected |
| 5.5 | No TikTok connection → publish button not shown | TikTok option absent from dialog |
| 5.6 | Non-approved clip → publish not available | publish action unavailable |
| 5.7 | No tokens in browser dev tools / network | no `access_token`/`refresh_token` in DevTools or network payloads |
| 5.8 | Keyboard navigation works throughout | Tab/Enter/Space/Escape operate all controls |
| 5.9 | Focus indicators visible | 3px outline on focused elements |
| 5.10 | Console cleanliness | no unexpected JS console errors |
| 5.11 | Network cleanliness | no failed resource loads, no unexpected 4xx/5xx unless scenario requires |

---

## 6. Governance & Lint

### 6.1 Backend Lint

- `vendor/bin/pint --dirty --format agent` → clean (no diffs)

### 6.2 Frontend Lint & Build

- `npm run lint` (eslint) → clean
- `npm run build` → clean

### 6.3 Governance Tests

- `php artisan test --filter=Governance` → all pass

---

## 7. Full Test Execution (Definition of Done)

| Step | Command | Target |
|------|---------|--------|
| Backend unit + feature | `php artisan test --compact` (apps/api) | all pass |
| Frontend unit | `npm run test` (apps/web) | all pass |
| E2E | `npm run test:e2e` (apps/web) | all pass at 3 viewports |
| Governance | `php artisan test --filter=Governance` | all pass |
| Backend lint | `vendor/bin/pint --dirty --format agent` | clean |
| Frontend lint | `npm run lint` | clean |
| Frontend build | `npm run build` | clean |

Failure of any step blocks merge via `scripts/merge_gate.py` and blocks issue closure (AGENTS.md §11, spec §CI Requirements).

---

## 8. Test Data & Fixtures

- Use `SocialConnection` factory with `platform = 'tiktok'`, `status = 'connected'`, encrypted `access_token`/`refresh_token`.
- Use `ClipReview` factory with `status = 'approved'` and a completed `DerivedAsset` (`type = 'clip_rendered'`, `render_status = 'completed'`).
- Use `SocialPublish` factory for existing-publish (duplicate) scenarios.
- `FakeTikTokPublisher` bound in testing env via `AppServiceProvider` — no real TikTok API, no network.
- Queue: sync driver in tests so jobs run inline (or `Bus::fake` + assert dispatch, then run job manually).

---

## 9. Environment Guards

- E2E needs prepared PostgreSQL, installed Chromium browsers, and free ports 5173/8000 (AGENTS.md Known Limitations).
- CI uses deterministic fakes; no multi-gigabyte model downloads, no real TikTok API credentials.
- No secrets in fixtures committed to Git (AGENTS.md §23).
