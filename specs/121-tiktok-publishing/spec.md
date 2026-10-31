# M12 — TikTok Publishing Specification

## Overview

This specification extends AiClip's social publishing from YouTube/Instagram to TikTok (M12). It builds on M9's Social Connection Framework (Issue #118), which established TikTok OAuth connections with encrypted token storage, and M10 YouTube Publishing (Issue #119), which established the provider-neutral `SocialPublisher` contract, `SocialPublish` model, `SocialPublishStatus` enum, API endpoints, and the `PublishToYouTube`/`PublishToInstagram` queue-job topology.

M12 introduces:

- A `TikTokPublisher` (`App\Services`) implementing `App\Contracts\SocialPublisher` that publishes an approved rendered clip to TikTok using the connected user's encrypted OAuth access token.
- A `PublishToTikTok` queue job (`App\Jobs`) following the `PublishToYouTube`/`PublishToInstagram` topology (ownership re-validation, non-pending idempotency, `uniqueId()`, `tries = 3`, `backoff = 5`).
- Provider registration in `AppServiceProvider`: production binds `SocialPublisher::class . ':tiktok'` → `TikTokPublisher`; testing binds it → `FakeTikTokPublisher`.
- API extension: `POST /api/v1/social/publish` accepts `platform="tiktok"`; `PublishClipRequest` validation `platform in:youtube,instagram,tiktok`; `SocialPublishResource` platform label map includes `'tiktok' => 'TikTok'`; `SocialPublishController@store` dispatches `PublishToTikTok`.
- TikTok provider config in `config/social.php` (already present from M9: scopes `video.publish`, `user.info.basic`).
- `FakeTikTokPublisher` for testing (no network, deterministic).
- Frontend: extend `PublishClipDialog` platform selector to include TikTok (only show connected platforms).

M12 is part of the publishing milestones: M10 YouTube → M11 Instagram → M12 TikTok → M14 Unified One-Click Publishing.

---

## Description

Goal: allow an authenticated user who owns an approved rendered clip and has a connected TikTok `SocialConnection` to publish that clip to TikTok, track the publish via `SocialPublish`, and monitor status through the existing API and frontend UI.

Why it is needed: TikTok is the third supported publishing platform in the roadmap (M12), following YouTube (M10) and Instagram (M11). The provider-neutral `SocialPublisher` contract and the `PublishToYouTube`/`PublishToInstagram` job topology make TikTok a straightforward, consistent addition without introducing new infrastructure.

Only approved rendered clips may be published. Publishing never runs inside an HTTP request process; it is enqueued as a `PublishToTikTok` job. Tokens remain encrypted at rest (M9 `encrypted` cast), are never logged, and are never returned to the frontend.

---

## Technical Tasks

- [ ] Implement `app/Services/TikTokPublisher.php` implementing `App\Contracts\SocialPublisher`
- [ ] Implement `app/Jobs/PublishToTikTok.php` following `PublishToYouTube`/`PublishToInstagram` topology
- [ ] Register `SocialPublisher::class . ':tiktok'` → `TikTokPublisher` (production) and → `FakeTikTokPublisher` (testing) in `AppServiceProvider`
- [ ] Extend `PublishClipRequest` validation `platform in:youtube,instagram,tiktok`
- [ ] Extend `SocialPublishController@store` to dispatch `PublishToTikTok` for platform `tiktok`
- [ ] Extend `SocialPublishResource::getPlatformLabel` with `'tiktok' => 'TikTok'`
- [ ] Implement `app/Services/FakeTikTokPublisher.php` (testing only, no network, deterministic)
- [ ] Extend frontend `PublishClipDialog` platform selector to include TikTok (only connected platforms)
- [ ] Update frontend `types.ts` to include `'tiktok'` in platform union types
- [ ] Write backend unit tests (publisher, job, fake, registration)
- [ ] Write backend feature tests (controller validation, endpoints, rate limiting, CSRF, ownership 404)
- [ ] Write backend integration tests (full publish flow with fake publisher)
- [ ] Write frontend Vitest component tests (dialog platform selector incl. TikTok)
- [ ] Write Playwright E2E tests at 3 viewports (390x844, 768x1024, 1440x900)
- [ ] Run lint (Pint), frontend lint/build, governance tests

---

## Acceptance Criteria

- [ ] `TikTokPublisher` implements `SocialPublisher` contract
- [ ] `TikTokPublisher::getPlatform()` returns `'tiktok'`
- [ ] `TikTokPublisher::getPlatformLabel()` returns `'TikTok'`
- [ ] `TikTokPublisher::publishVideo()` resolves connected TikTok `SocialConnection`, gets valid token via `SocialConnectionManager::getValidAccessToken()`, calls TikTok API, returns `PublishResult` (success: published; failure: failed with sanitized `errorMessage`), never logs tokens
- [ ] `TikTokPublisher::hasPublishPermission(string $accessToken)` verifies token validity and required scopes
- [ ] `TikTokPublisher::getPublishStatus(string $externalVideoId)` checks status of published post
- [ ] `PublishToTikTok` job follows `PublishToYouTube` pattern (`tries = 3`, `backoff = 5`, `uniqueId()` = `social-publish:{publishId}`, idempotent skip if not pending, ownership re-validation, pending → uploading → published/failed)
- [ ] `SocialPublisher::class . ':tiktok'` binding works (production: `TikTokPublisher`; testing: `FakeTikTokPublisher`)
- [ ] `POST /api/v1/social/publish` supports `platform="tiktok"`
- [ ] `PublishClipRequest` validation `platform in:youtube,instagram,tiktok`
- [ ] `FakeTikTokPublisher` in testing env (no network, deterministic)
- [ ] Tokens encrypted at rest, never leaked (no tokens in API responses, logs, or browser)
- [ ] CSRF on state-changing endpoints
- [ ] Rate limiting 20/min on publish
- [ ] Ownership: 404 for non-owner
- [ ] Unit tests pass, integration tests pass
- [ ] E2E Playwright tests at 3 viewports (390x844, 768x1024, 1440x900)
- [ ] Independent Tester approval

---

## Out of Scope

- TikTok API v2-specific features beyond basic video publish
- Real TikTok credentials in CI (`FakeTikTokPublisher` used instead)
- Multi-platform one-click publishing (M14)
- Scheduling posts
- Deleting or editing already-published content on TikTok
- Token auto-refresh background job (refresh happens on-demand during publish)
- Webhook handling for platform-initiated video removal (future)
- Publishing clips with non-approved review status
- Publishing images from M8 Image Studio (M12 publishes video clips only)

---

## Security Considerations

### Token Protection

- OAuth `access_token`/`refresh_token` remain encrypted at rest (M9 `encrypted` cast on `SocialConnection`), never exposed.
- Publishing uses the valid access token obtained via `SocialConnectionManager::getValidAccessToken()` — token is never returned to the frontend.
- Tokens never logged (explicitly excluded from log context).
- No tokens in API responses, browser dev tools, or network.
- `error_message` never contains tokens or secrets.

### Authorization

- All endpoints require authenticated user (`auth:sanctum`).
- Publish queries scoped to `auth()->id()`.
- 404 for non-owned clip reviews, connections, and publishes (hides existence).
- A user can only publish clips from projects they own.
- A user can only publish via their own connected TikTok connection.

### CSRF & Rate Limiting

- CSRF token required for `POST /api/v1/social/publish` (Sanctum `VerifyCsrfToken`).
- Rate limit: 20/min for publish (`social-publish` limiter, matches M9 connect rate limit).

### Audit Logging

- Publish initiated (`user_id`, `platform`, `clip_review_id`, `publish_id`, no tokens).
- Publish completed (`external_video_id`, no tokens).
- Publish failed (reason only, no tokens).
- NEVER log: `access_token`, `refresh_token`, upload responses.

### CI Safety

- No real TikTok API credentials in CI; `FakeTikTokPublisher` used in tests.
- CI uses deterministic fakes; no network calls to TikTok.

---

## UX Considerations

- The TikTok platform option appears in `PublishClipDialog` only when the user has a connected TikTok `SocialConnection` (via `availablePlatforms`).
- Disabled/loading states during publish with clear feedback.
- Error states are understandable and actionable (e.g., "Reconnect TikTok" when the connection expired).
- Published videos show a direct link to TikTok.
- Publish list shows status badges using color + text.
- Responsive layout: single column below 1024px, side-by-side above.
- Reduced-motion support respected.
- Styling follows `design-taste-frontend` art direction and project design tokens (Switzer font, mono-accent palette, square corners).
- All interactive elements keyboard accessible (Tab, Enter, Space, Escape); visible focus indicators (3px solid outline, offset 2px); ARIA labels on icon-only buttons; live region for status announcements; color contrast ≥ 4.5:1 for text, 3:1 for UI components; 44px touch targets.

---

## Test Scenarios

### Unit Tests (Backend)

- `TikTokPublisherTest`:
  - `getPlatform()` returns `'tiktok'`
  - `getPlatformLabel()` returns `'TikTok'`
  - `publishVideo()` success returns `PublishResult` with `status = 'published'`, `externalVideoId`, `videoUrl`
  - `publishVideo()` failure returns `PublishResult` with `status = 'failed'`, sanitized `errorMessage`
  - `publishVideo()` never logs access token
  - `hasPublishPermission()` returns true for valid token with required scopes, false otherwise
  - `getPublishStatus()` returns `PublishStatus` for a published post
- `FakeTikTokPublisherTest`:
  - Implements `SocialPublisher`
  - Deterministic, configurable success/failure, no network calls
- `PublishToTikTokTest`:
  - Ownership re-validation fails when connection user_id != publish user_id
  - Non-pending publish is skipped (idempotency)
  - Pending → uploading → published transition on success
  - Pending → uploading → failed transition on failure with sanitized error
  - `uniqueId()` returns `social-publish:{publishId}`
  - `tries = 3`, `backoff = 5`
  - No valid token → failed
  - No completed rendered clip → failed
  - Connection platform is not tiktok → failed

### Feature Tests (Backend)

- `PublishClipRequestTest`:
  - `platform in:youtube,instagram,tiktok` accepts tiktok, rejects invalid platform
  - `clip_review_id` required/integer/exists
  - `title` required/string/max:255
- `SocialPublishControllerTest`:
  - POST publish with `platform=tiktok` → 202 enqueued
  - POST publish unauthenticated → 401
  - POST publish non-owner clip review → 404
  - POST publish not-approved clip review → 404
  - POST publish no completed rendered clip → 404
  - POST publish no connected TikTok connection → 404
  - POST publish duplicate → 409
  - POST publish rate limited → 429
  - POST publish CSRF missing/invalid → 419
  - GET publishes list → 200, user-scoped
  - GET publishes/{publish} → 200 owned, 404 non-owned

### Integration Tests (Backend)

- `SocialPublishTikTokIntegrationTest`:
  - Full publish flow (POST → job runs inline → published)
  - Publish uses encrypted token (never in response/logs)
  - Failed publish stores sanitized error_message
  - Duplicate publish blocked (409)
  - Cross-user publish 404

### Frontend Tests (Vitest)

- `PublishClipDialog.test.tsx`:
  - Renders TikTok option when in `availablePlatforms`
  - Does not render TikTok option when not in `availablePlatforms`
  - Selecting TikTok updates platform state
  - Publish button disabled during loading
  - Accessibility: keyboard navigation, focus indicators, ARIA labels

### E2E Tests (Playwright, 3 viewports: 390x844, 768x1024, 1440x900)

- Publish approved clip to TikTok (fake) → appears in publish list
- Publish status reflection (pending → published)
- Failed publish shows error message
- Duplicate publish blocked (409)
- No TikTok connection → publish button not shown / 404
- Non-approved clip → publish not available
- No tokens in browser dev tools / network
- Keyboard navigation works throughout
- Focus indicators visible
- Console/network cleanliness

---

## Dependencies

- M9 Social Connection Framework (Issue #118): `SocialConnection` model, `SocialConnectionProvider` contract, `SocialConnectionManager`, encrypted token storage, `FakeTikTokProvider`, `config/social.php`.
- M10 YouTube Publishing (Issue #119): `SocialPublisher` contract, `PublishResult`/`PublishStatus` value objects, `SocialPublish` model, `SocialPublishStatus` enum, `PublishToYouTube`/`PublishToInstagram` job topology, `SocialPublishController`, `PublishClipRequest`, `SocialPublishResource`, API endpoints, fakes.
- M7 Clip Review Experience (Issue #106): `ClipReview` model with `approved` status, `DerivedAsset` rendered clips.
- M2 Media Storage (Issue #35): S3-compatible storage for rendered clip video files.
- Laravel Sanctum authentication (existing).
- Queue system for async jobs (existing).
- `config/social.php` provider configuration (M9, tiktok already present).

---

## CI Requirements

All four GitHub Actions CI workflows must pass on the issue branch before the issue is considered done:

| Workflow | File | Status target |
|----------|------|---------------|
| Backend CI | `.github/workflows/backend.yml` | green |
| E2E CI | `.github/workflows/e2e.yml` | green |
| Frontend CI | `.github/workflows/frontend.yml` | green |
| Governance | `.github/workflows/governance.yml` | green |

Failure of any single workflow blocks merge via `scripts/merge_gate.py` and blocks issue closure.
