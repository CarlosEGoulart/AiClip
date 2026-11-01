# M14 — One-Click Publishing Specification

## Overview

This specification adds M14 "One-Click Publishing" — an orchestration layer over the existing platform adapters (M10 YouTube, M11 Instagram, M12 TikTok) that lets an authenticated user publish an approved rendered clip to one or more already-connected social platforms in a single, user-confirmed action.

M14 builds on M9 (Social Connection Framework, Issue #118), M10 YouTube Publishing (Issue #119), M11 Instagram Publishing (Issue #135), and M12 TikTok Publishing (Issue #121). The provider-neutral `SocialPublisher` contract, `PublishResult`/`PublishStatus` value objects, `SocialPublish` model, `SocialPublishStatus` enum, `PublishToYouTube`/`PublishToInstagram`/`PublishToTikTok` job topology, API endpoints, controller, resources, and frontend components all exist. M14 introduces:

- A `SocialPublishCampaign` model representing a multi-target publish campaign with statuses: `pending`, `processing`, `published`, `partially_published`, `failed`, `cancelled`.
- A `campaign_id` foreign key on `SocialPublish` linking per-target publications to their campaign.
- Pre-execution validation endpoint that checks ALL targets before queueing ANY; invalid targets are reported, valid targets are not silently dropped.
- New API endpoints: `POST /api/v1/social/publish/campaign` (validation + queue), `GET /api/v1/social/publish/campaigns` (list), `GET /api/v1/social/publish/campaigns/{campaign}` (status with per-target publishes), `POST /api/v1/social/publish/campaigns/{campaign}/cancel` (cancel before execution), `POST /api/v1/social/publish/campaigns/{campaign}/retry` (retry failed targets).
- Durable per-target publication records (reuse `SocialPublish`) with independent status and retry history; partial success represented accurately without hiding it.
- Idempotency: retrying a failed target does not create a duplicate publication where the platform supports idempotency or reconciliation (job `uniqueId()` + pending-skip).
- Cancellation before execution where possible.
- Frontend: multi-target publish dialog with platform multi-selection, per-platform metadata, confirm-before-execute, per-platform states (queued/publishing/published/failed), retry, cancellation, clear recovery guidance.

Only approved rendered clips may be published. Publishing never runs inside an HTTP request process; it is enqueued as per-target queue jobs. Tokens remain encrypted at rest (M9 `encrypted` cast), are never logged, and are never returned to the frontend.

M14 is part of the publishing milestones: M10 YouTube → M11 Instagram → M12 TikTok → M14 Unified One-Click Publishing.

---

## Description

Goal: allow an authenticated user who owns an approved rendered clip and has one or more connected social connections to publish that clip to multiple platforms in a single user-confirmed action, track the campaign and each per-target publish independently, and monitor status through the API and frontend UI.

Why it is needed: today publishing is single-target (one clip → one platform per request). Users who want to publish to YouTube, Instagram, and TikTok must repeat the flow three times. M14 provides a single orchestrated workflow with upfront validation, per-target durability, partial-success transparency, idempotent retry, and cancellation — reducing friction while preserving safety and ownership guarantees.

Scope boundaries:
- Only already-connected and supported platforms (YouTube, Instagram, TikTok) may be targets.
- Publishing requires explicit user confirmation of all targets and metadata before any job is queued.
- Automatic publishing without explicit user confirmation is out of scope.
- Publishing to platforms without supported integration is out of scope.

---

## Technical Tasks

- [ ] Create migration: `create_social_publish_campaigns_table` (id, user_id, clip_review_id, status, created_at, updated_at)
- [ ] Create migration: `add_campaign_id_to_social_publishes_table` (nullable campaign_id foreign key → social_publish_campaigns.id)
- [ ] Create `SocialPublishCampaignStatus` enum (pending, processing, published, partially_published, failed, cancelled) with label()/color()
- [ ] Implement `app/Models/SocialPublishCampaign.php` (relationships: user, clipReview, publishes; scopes by status)
- [ ] Add `campaign_id` to `SocialPublish` fillable + `campaign()` relationship + scopeByCampaign
- [ ] Implement `PublishCampaignRequest` validation (clip_review_id, targets array, per-target platform/title/description)
- [ ] Implement `SocialPublishCampaignController`:
  - `store` — pre-execution validation of all targets, create campaign + per-target `SocialPublish` records (pending), queue per-target jobs, return campaign
  - `index` — list user's campaigns
  - `show` — campaign status with per-target publishes
  - `cancel` — cancel campaign (pending/processing) without touching already-published targets
  - `retry` — retry failed targets of a campaign (idempotent, no duplicates)
- [ ] Register new routes under `auth:sanctum` + CSRF + rate limiting (`social-campaign-publish`, `social-campaign-retry`, `social-campaign-cancel`)
- [ ] Implement `PublishCampaignJob` (orchestrator job) — optional: transitions campaign pending→processing, dispatches per-target `PublishTo*` jobs; or controller dispatches directly. Spec allows controller-direct dispatch with campaign status updated by per-target jobs.
- [ ] Update per-target `PublishToYouTube`/`PublishToInstagram`/`PublishToTikTok` jobs to accept optional `campaignId` and update campaign aggregate status on completion (pending→processing→published/partially_published/failed/cancelled)
- [ ] Register campaign resource(s): `SocialPublishCampaignResource`, `SocialPublishCampaignCollection`
- [ ] Update `AppServiceProvider` if any new bindings needed (none expected beyond existing publisher bindings)
- [ ] Backend unit tests: campaign model, campaign status enum, campaign request validation, campaign controller (store/index/show/cancel/retry), campaign resource
- [ ] Backend feature tests: campaign endpoints, pre-execution validation (all targets checked, none queued on failure), ownership 404, disconnected account, rate limiting, CSRF, duplicate retry idempotency
- [ ] Backend integration tests: full campaign flow (all-success, partial-success, all-failed, disconnected account, retry)
- [ ] Frontend types: `SocialPublishCampaign`, `PublishCampaignRequest`, `PublishCampaignTarget`, `PublishCampaignResponse`, `PublishCampaignTargetRequest`, `PublishCampaignTargetResponse`, campaign status union; extend `SocialPublish` with optional `campaign_id`
- [ ] Frontend API client: `createPublishCampaign`, `getPublishCampaigns`, `getPublishCampaign`, `cancelPublishCampaign`, `retryPublishCampaign`
- [ ] Frontend hooks: `usePublishCampaign`, `usePublishCampaigns`, `usePublishCampaign`, `useCancelPublishCampaign`, `useRetryPublishCampaign`
- [ ] Frontend: multi-target publish dialog (`PublishCampaignDialog`) — platform multi-selection (only connected platforms), per-platform metadata (title/description), confirm-before-execute, per-platform states (queued/publishing/published/failed), retry failed target, cancel campaign, live region announcements
- [ ] Frontend: update `PublishList`/`PublishCard` to show campaign badge and per-platform states where applicable
- [ ] Frontend Vitest component tests (dialog, campaign list/card)
- [ ] Playwright E2E tests at 3 viewports (390x844, 768x1024, 1440x900): all-success, partial-success, all-failed, disconnected account, retry, cancellation, keyboard accessibility, console/network cleanliness
- [ ] Run lint (Pint), frontend lint/build, governance tests

---

## Acceptance Criteria

- [ ] `SocialPublishCampaignStatus` enum with statuses: pending, processing, published, partially_published, failed, cancelled
- [ ] `SocialPublishCampaign` model with user/clipReview/publishes relationships and status scopes
- [ ] `campaign_id` on `SocialPublish` linking per-target publications to campaign
- [ ] `POST /api/v1/social/publish/campaign` validates ALL targets before queueing ANY; invalid targets reported, valid targets not silently dropped
- [ ] Campaign created with per-target `SocialPublish` records (status pending) and jobs queued per target
- [ ] Each target has independent status and retry history; partial success represented accurately (partially_published)
- [ ] Retrying a failed target does not create a duplicate publication (job uniqueId + pending-skip idempotency)
- [ ] Cancellation before execution stops pending targets; already-published targets remain published
- [ ] Authorization and project ownership enforced for every target (404 for non-owner)
- [ ] Tokens encrypted at rest, never leaked (no tokens in API responses, logs, or browser)
- [ ] CSRF on state-changing campaign endpoints
- [ ] Rate limiting on campaign publish/retry/cancel
- [ ] Frontend: multi-target dialog with platform multi-selection, per-platform metadata, confirm-before-execute
- [ ] Frontend: per-platform states queued/publishing/published/failed; retry and cancel available; errors announced via live region
- [ ] UI keyboard accessible and responsive (3 viewports); status changes and errors announced clearly
- [ ] Integration and E2E tests cover all-success, partial-success, all-failed, disconnected account, and retry scenarios
- [ ] Unit tests pass, integration tests pass, E2E tests pass at 3 viewports
- [ ] Independent Tester approval

---

## Out of Scope

- Publishing to platforms without supported integration (only YouTube, Instagram, TikTok)
- Automatic publishing without explicit user confirmation
- Scheduling posts for future publication
- Deleting or editing already-published content on platforms
- Token auto-refresh background job (refresh happens on-demand during publish)
- Webhook handling for platform-initiated video removal (future)
- Publishing clips with non-approved review status
- Publishing images from M8 Image Studio (M14 publishes video clips only)
- Real platform credentials in CI (fakes used instead)
- Cross-clip or cross-project campaigns (one campaign = one clip → multiple platforms)

---

## Security Considerations

### Token Protection

- OAuth `access_token`/`refresh_token` remain encrypted at rest (M9 `encrypted` cast on `SocialConnection`), never exposed.
- Publishing uses the valid access token obtained via `SocialConnectionManager::getValidAccessToken()` — token is never returned to the frontend.
- Tokens never logged (explicitly excluded from log context).
- No tokens in API responses, browser dev tools, or network.
- `error_message` never contains tokens or secrets.

### Authorization & Ownership

- All campaign endpoints require authenticated user (`auth:sanctum`).
- Campaign and per-target publish queries scoped to `auth()->id()`.
- 404 for non-owned clip reviews, connections, publishes, and campaigns (hides existence).
- A user can only publish clips from projects they own.
- A user can only publish via their own connected social connections.
- Ownership re-validated inside each per-target job (connection user_id == publish user_id).
- Every target in a campaign is independently authorized before its job is queued.

### CSRF & Rate Limiting

- CSRF token required for all state-changing campaign endpoints (`POST /social/publish/campaign`, `POST /social/publish/campaigns/{campaign}/cancel`, `POST /social/publish/campaigns/{campaign}/retry`) (Sanctum `VerifyCsrfToken`).
- Rate limiting: `social-campaign-publish`, `social-campaign-retry`, `social-campaign-cancel` limiters (matches M9/M10 social-publish rate limit, 20/min).

### Audit Logging

- Campaign initiated (`user_id`, `clip_review_id`, `campaign_id`, target count, platforms, no tokens).
- Per-target publish initiated (`user_id`, `platform`, `clip_review_id`, `publish_id`, `campaign_id`, no tokens).
- Campaign completed / partially completed / failed (aggregate outcome, no tokens).
- Campaign cancelled (`campaign_id`, remaining pending targets cancelled, no tokens).
- NEVER log: `access_token`, `refresh_token`, upload responses.

### CI Safety

- No real platform API credentials in CI; fakes used in tests.
- CI uses deterministic fakes; no network calls to platforms.

---

## UX Considerations

- The multi-target publish dialog shows only connected platforms (via `availablePlatforms`) as selectable targets.
- User selects one or more target platforms and configures per-platform metadata (title, description) before confirming.
- Confirm-before-execute: no jobs are queued until the user explicitly confirms all targets and metadata.
- Pre-execution validation runs on confirm; if any target is invalid, all validation errors are shown and nothing is queued.
- Per-platform states: queued → publishing → published/failed, shown with color + text badges.
- Published videos show a direct link to the platform where available.
- Failed targets show an understandable, actionable error message (e.g., "Reconnect TikTok" when the connection expired) and a retry button.
- Retry of a failed target is idempotent (no duplicate publication).
- Cancel campaign stops remaining pending/queued targets; already-published targets remain published.
- Publish list shows campaign badge and per-platform status badges; aggregate campaign status shown.
- Responsive layout: single column below 1024px, side-by-side above.
- Reduced-motion support respected.
- Styling follows `design-taste-frontend` art direction and project design tokens (Switzer font, mono-accent palette, square corners).
- All interactive elements keyboard accessible (Tab, Enter, Space, Escape); visible focus indicators (3px solid outline, offset 2px); ARIA labels on icon-only buttons; live region for status announcements; color contrast ≥ 4.5:1 for text, 3:1 for UI components; 44px touch targets.

---

## Test Scenarios

### Unit Tests (Backend)

#### SocialPublishCampaignStatusTest
- `label()` returns correct label for each status
- `color()` returns correct color for each status

#### SocialPublishCampaignTest
- Campaign has user, clipReview, publishes relationships
- Status scopes work (pending, processing, published, partially_published, failed, cancelled)
- `campaign_id` on SocialPublish links to campaign

#### PublishCampaignRequestTest
- Valid targets array passes validation
- Invalid platform in targets → 422
- Missing clip_review_id → 422
- Empty targets array → 422 (min:1)
- Missing title in a target → 422
- Title > 255 chars → 422

#### SocialPublishCampaignControllerTest (store)
- POST campaign with all valid targets → 202, campaign created, per-target publishes pending, jobs queued
- POST campaign with one invalid target (disconnected platform) → 422/400, no targets queued, no campaign created
- POST campaign with invalid platform → 422
- POST campaign unauthenticated → 401
- POST campaign non-owned clip review → 404
- POST campaign not-approved clip review → 404
- POST campaign no completed rendered clip → 404
- POST campaign rate limited → 429
- POST campaign CSRF missing/invalid → 419

#### SocialPublishCampaignControllerTest (index/show/cancel/retry)
- GET campaigns list → 200, user-scoped
- GET campaign/{id} owned → 200 with per-target publishes
- GET campaign/{id} non-owned → 404
- POST campaign/{id}/cancel (pending) → 200, status cancelled, pending targets cancelled
- POST campaign/{id}/cancel (already published) → cannot cancel published targets
- POST campaign/{id}/retry (failed targets) → 202, failed targets re-queued, no duplicates
- POST campaign/{id}/retry (no failed targets) → 409/400 nothing to retry

#### SocialPublishCampaignResourceTest
- Resource includes campaign status, per-target publishes
- Resource does not include tokens

### Feature Tests (Backend)

- Pre-execution validation: all targets checked before any queued; invalid target → none queued
- Valid targets not silently dropped when one target invalid (entire campaign rejected)
- Per-target independent status and retry history
- Partial success: some published, some failed → campaign status `partially_published`
- Idempotent retry: retrying failed target does not duplicate publication
- Ownership: cross-user campaign 404

### Integration Tests (Backend)

#### SocialPublishCampaignIntegrationTest
- Full campaign flow all-success (POST → jobs run inline → all published, campaign `published`)
- Full campaign flow partial-success (one target fails → campaign `partially_published`, per-target statuses independent)
- Full campaign flow all-failed (all targets fail → campaign `failed`)
- Disconnected account target → target marked failed, other targets proceed
- Retry failed target → no duplicate, target republished
- Cancel campaign before execution → pending targets cancelled, status `cancelled`
- Publish uses encrypted token (never in response/logs)
- Failed publish stores sanitized error_message (no tokens)
- Duplicate publish blocked via job idempotency

### Frontend Unit Tests (Vitest)

#### PublishCampaignDialog.test.tsx
- Renders platform multi-select only for connected platforms
- Per-platform metadata fields (title/description) render per selected platform
- Confirm-before-execute: no publish API call until confirm clicked
- Pre-execution validation errors shown, nothing queued when invalid
- Per-platform states: queued/publishing/published/failed
- Retry failed target
- Cancel campaign
- Accessibility: keyboard navigation, focus indicators, ARIA labels, live region announcements

#### PublishCampaignList/Card tests
- Campaign badge shown
- Per-platform status badges shown

### E2E Tests (Playwright, 3 viewports: 390x844, 768x1024, 1440x900)

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 5.1 | Publish approved clip to all connected platforms (all-success) → campaign `published`, all per-target publishes `published` | campaign card shows published, per-platform badges green |
| 5.2 | Publish with one failing target (partial-success) → campaign `partially_published` | one platform published, one failed, campaign badge reflects partial |
| 5.3 | Publish with all targets failing → campaign `failed` | all per-target badges red, campaign failed |
| 5.4 | Disconnected account target → that target failed, others proceed | failed target shows "Reconnect" guidance, other targets published |
| 5.5 | Retry failed target → no duplicate publication | retry succeeds, single external_video_id per platform |
| 5.6 | Cancel campaign before execution → pending targets cancelled | status cancelled, no jobs executed for cancelled targets |
| 5.7 | Confirm-before-execute: validation error on invalid target → nothing queued | errors shown, no campaign created |
| 5.8 | No tokens in browser dev tools / network | no `access_token`/`refresh_token` in DevTools or network payloads |
| 5.9 | Keyboard navigation works throughout | Tab/Enter/Space/Escape operate all controls |
| 5.10 | Focus indicators visible | 3px outline on focused elements |
| 5.11 | Console cleanliness | no unexpected JS console errors |
| 5.12 | Network cleanliness | no failed resource loads, no unexpected 4xx/5xx unless scenario requires |

---

## CI Requirements

### Backend Lint
- `vendor/bin/pint --dirty --format agent` → clean (no diffs)

### Frontend Lint & Build
- `npm run lint` (eslint) → clean
- `npm run build` → clean

### Governance Tests
- `php artisan test --filter=Governance` → all pass

### Full Test Execution (Definition of Done)

| Step | Command | Target |
|------|---------|--------|
| Backend unit + feature | `php artisan test --compact` (apps/api) | all pass |
| Frontend unit | `npm run test` (apps/web) | all pass |
| E2E | `npm run test:e2e` (apps/web) | all pass at 3 viewports |
| Governance | `php artisan test --filter=Governance` | all pass |
| Backend lint | `vendor/bin/pint --dirty --format agent` | clean |
| Frontend lint | `npm run lint` | clean |
| Frontend build | `npm run build` | clean |

Failure of any step blocks merge via `scripts/merge_gate.py` and blocks issue closure (AGENTS.md §11).

---

## Test Data & Fixtures

- Use `SocialConnection` factory with `platform` in (`youtube`, `instagram`, `tiktok`), `status = 'connected'`, encrypted `access_token`/`refresh_token`.
- Use `ClipReview` factory with `status = 'approved'` and a completed `DerivedAsset` (`type = 'clip_rendered'`, `render_status = 'completed'`).
- Use `SocialPublish` factory for existing-publish / per-target publish records.
- Use `SocialPublishCampaign` factory for campaign records.
- Fakes (`FakeYouTubePublisher`, `FakeInstagramPublisher`, `FakeTikTokPublisher`) bound in testing env via `AppServiceProvider` — no real platform API, no network.
- Queue: sync driver in tests so jobs run inline.

### Environment Guards
- E2E needs prepared PostgreSQL, installed Chromium browsers, and free ports 5173/8000 (AGENTS.md Known Limitations).
- CI uses deterministic fakes; no multi-gigabyte model downloads, no real platform API credentials.
- No secrets in fixtures committed to Git (AGENTS.md §23).

---

## Dependencies

- M9 Social Connection Framework (Issue #118): `SocialConnection` model, `SocialConnectionProvider` contract, `SocialConnectionManager`, encrypted token storage, `config/social.php`.
- M10 YouTube Publishing (Issue #119): `SocialPublisher` contract, `PublishResult`/`PublishStatus` value objects, `SocialPublish` model, `SocialPublishStatus` enum, `PublishToYouTube`/`PublishToInstagram` job topology, `SocialPublishController`, `PublishClipRequest`, `SocialPublishResource`, API endpoints, fakes.
- M11 Instagram Publishing (Issue #135): Instagram publisher, job, fake, registration.
- M12 TikTok Publishing (Issue #121): TikTok publisher, job, fake, registration.
- M7 Clip Review Experience (Issue #106): `ClipReview` model with `approved` status, `DerivedAsset` rendered clips.
- M2 Media Storage (Issue #35): S3-compatible storage for rendered clip video files.
- Laravel Sanctum authentication (existing).
- Queue system for async jobs (existing).
- `config/social.php` provider configuration (M9, youtube/instagram/tiktok already present).

---

## Files Touched Summary

### Backend (new)
| File | Purpose |
|------|---------|
| `database/migrations/xxxx_create_social_publish_campaigns_table.php` | Campaigns table |
| `database/migrations/xxxx_add_campaign_id_to_social_publishes_table.php` | campaign_id FK on social_publishes |
| `app/Enums/SocialPublishCampaignStatus.php` | Campaign status enum |
| `app/Models/SocialPublishCampaign.php` | Campaign model |
| `app/Http/Requests/PublishCampaignRequest.php` | Campaign validation |
| `app/Http/Controllers/Api/V1/SocialPublishCampaignController.php` | Campaign CRUD + cancel + retry |
| `app/Http/Resources/SocialPublishCampaignResource.php` | Campaign resource |
| `app/Http/Resources/SocialPublishCampaignCollection.php` | Campaign collection |
| `app/Jobs/PublishCampaignJob.php` | Optional orchestrator job (or controller-direct dispatch) |

### Backend (modified)
| File | Change |
|------|--------|
| `app/Models/SocialPublish.php` | Add `campaign_id` fillable + `campaign()` relationship + scope |
| `app/Providers/AppServiceProvider.php` | No new publisher bindings (existing); verify campaign not needing binding |
| `routes/api.php` | Add campaign routes under auth:sanctum + CSRF + rate limit |

### Frontend (new)
| File | Purpose |
|------|---------|
| `apps/web/src/features/social-publish/types.ts` | Campaign types + extend SocialPublish with campaign_id |
| `apps/web/src/features/social-publish/api/index.ts` | Campaign API client functions |
| `apps/web/src/features/social-publish/hooks.ts` | Campaign hooks |
| `apps/web/src/features/social-publish/components/PublishCampaignDialog.tsx` | Multi-target dialog |
| `apps/web/src/features/social-publish/components/PublishCampaignDialog.test.tsx` | Dialog Vitest tests |
| `apps/web/tests/e2e/one-click-publish.spec.ts` | Playwright E2E (3 viewports) |

### Frontend (modified)
| File | Change |
|------|--------|
| `apps/web/src/features/social-publish/components/PublishList.tsx` | Campaign badge / per-platform states |
| `apps/web/src/features/social-publish/components/PublishCard.tsx` | Campaign badge |
