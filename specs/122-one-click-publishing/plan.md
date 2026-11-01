# M14 — One-Click Publishing Implementation Plan

## Overview

This plan breaks down the M14 One-Click Publishing implementation into sequential, testable increments following TDD (RED → GREEN → REFACTOR). Each phase delivers a vertical slice that can be independently verified.

M14 builds on M9 (Social Connection Framework, Issue #118), M10 YouTube Publishing (Issue #119), M11 Instagram Publishing (Issue #135), and M12 TikTok Publishing (Issue #121). The `SocialPublish` model, `SocialPublishStatus` enum, `SocialPublisher` contract, `PublishResult`/`PublishStatus` value objects, API endpoints, controller, resources, and `PublishToYouTube`/`PublishToInstagram`/`PublishToTikTok` job topology already exist. M14 adds the campaign orchestration layer: a `SocialPublishCampaign` model, `campaign_id` on `SocialPublish`, pre-execution validation, new campaign API endpoints, cancel/retry, and a multi-target frontend dialog.

Branch: `@carlosegoulart/122/feat/one-click-publishing` (created by Orchestrator).

---

## Phase 0: Project Setup & Configuration

### 0.1 Verify Spec Directory

- [ ] Verify `specs/122-one-click-publishing/` exists (spec.md, plan.md, test-plan.md created by Planner)

No new platform adapters are required (all exist from M10/M11/M12). M14 adds the campaign model, migration, controller, resources, and frontend dialog.

---

## Phase 1: Campaign Data Model & Migration (Backend)

### 1.1 Migrations

**RED**: No behavior tests for migrations directly; migrations are verified via model tests.

**GREEN**:
- [ ] Create migration `create_social_publish_campaigns_table`:
  - `id` (bigIncrements, primary)
  - `user_id` (unsignedBigInteger, indexed, foreign → users.id)
  - `clip_review_id` (unsignedBigInteger, indexed, foreign → clip_reviews.id)
  - `status` (string, default 'pending')
  - `timestamps`
- [ ] Create migration `add_campaign_id_to_social_publishes_table`:
  - `campaign_id` (unsignedBigInteger, nullable, indexed, foreign → social_publish_campaigns.id)

**REFACTOR**: Verify foreign key conventions match existing migrations; run `php artisan migrate:fresh --env=testing` in test bootstrap to confirm schema loads.

### 1.2 SocialPublishCampaignStatus Enum

**RED**: Write unit tests
- [ ] `tests/Unit/Enums/SocialPublishCampaignStatusTest.php`
- [ ] Test: `label()` returns correct label for each status (pending/processing/published/partially_published/failed/cancelled)
- [ ] Test: `color()` returns correct color for each status
- [ ] Run test → fails

**GREEN**: Create `app/Enums/SocialPublishCampaignStatus.php`
- [ ] Cases: Pending='pending', Processing='processing', Published='published', PartiallyPublished='partially_published', Failed='failed', Cancelled='cancelled'
- [ ] `label()` match → human labels
- [ ] `color()` match → gray/blue/green/yellow/red/gray
- [ ] Run test → passes

**REFACTOR**: Verify enum shape consistent with `SocialPublishStatus`; run full backend test suite.

### 1.3 SocialPublishCampaign Model

**RED**: Write model tests
- [ ] `tests/Unit/Models/SocialPublishCampaignTest.php`
- [ ] Test: campaign has `user`, `clipReview`, `publishes` relationships
- [ ] Test: status scopes (pending, processing, published, partially_published, failed, cancelled) work
- [ ] Test: `SocialPublish` belongs to campaign via `campaign()` relationship; `campaign()` scope on SocialPublish
- [ ] Run test → fails

**GREEN**: Create `app/Models/SocialPublishCampaign.php`
- [ ] Fillable: `user_id`, `clip_review_id`, `status`
- [ ] Casts: `status` → `SocialPublishCampaignStatus`
- [ ] Relationships: `user()`, `clipReview()`, `publishes()` (hasMany SocialPublish)
- [ ] Scopes: by each status
- [ ] Run test → passes

**GREEN**: Modify `app/Models/SocialPublish.php`
- [ ] Add `campaign_id` to `$fillable`
- [ ] Add `campaign()` belongsTo relationship
- [ ] Add `scopeByCampaign($query, $campaignId)`
- [ ] Run test → passes

**REFACTOR**: Run full backend test suite.

---

## Phase 2: Campaign Request Validation & Controller (Backend)

### 2.1 PublishCampaignRequest

**RED**: Write validation tests
- [ ] `tests/Feature/SocialPublish/PublishCampaignRequestTest.php`
- [ ] Test: valid targets array passes
- [ ] Test: invalid platform in targets → 422
- [ ] Test: missing clip_review_id → 422
- [ ] Test: empty targets array → 422 (min:1)
- [ ] Test: missing title in a target → 422
- [ ] Test: title > 255 chars → 422
- [ ] Run test → fails

**GREEN**: Create `app/Http/Requests/PublishCampaignRequest.php`
- [ ] Rules: `clip_review_id` required|integer|exists:clip_reviews,id
- [ ] Rules: `targets` required|array|min:1
- [ ] Rules: `targets.*.platform` required|string|in:youtube,instagram,tiktok
- [ ] Rules: `targets.*.title` required|string|max:255
- [ ] Rules: `targets.*.description` nullable|string
- [ ] Run test → passes

### 2.2 SocialPublishCampaignController

**RED**: Write controller tests
- [ ] `tests/Feature/SocialPublish/SocialPublishCampaignControllerTest.php`
- [ ] Test: POST campaign all-valid → 202, campaign created, per-target publishes pending, jobs queued
- [ ] Test: POST campaign one invalid target (disconnected platform) → 422/400, no targets queued, no campaign created
- [ ] Test: POST campaign invalid platform → 422
- [ ] Test: POST campaign unauthenticated → 401
- [ ] Test: POST campaign non-owned clip review → 404
- [ ] Test: POST campaign not-approved clip review → 404
- [ ] Test: POST campaign no completed rendered clip → 404
- [ ] Test: POST campaign rate limited → 429
- [ ] Test: POST campaign CSRF missing/invalid → 419
- [ ] Test: GET campaigns list → 200, user-scoped
- [ ] Test: GET campaign/{id} owned → 200 with per-target publishes
- [ ] Test: GET campaign/{id} non-owned → 404
- [ ] Test: POST campaign/{id}/cancel (pending) → 200, status cancelled
- [ ] Test: POST campaign/{id}/retry (failed targets) → 202, failed targets re-queued, no duplicates
- [ ] Test: POST campaign/{id}/retry (no failed targets) → 409 nothing to retry
- [ ] Run test → fails

**GREEN**: Create `app/Http/Controllers/Api/V1/SocialPublishCampaignController.php`
- [ ] `store`: pre-execution validation of ALL targets (clip review owned/approved/has rendered clip; each target connection exists+connected+platform matches); if any invalid → return all errors, queue nothing; else create campaign + per-target `SocialPublish` (pending) + queue per-target jobs
- [ ] `index`: user-scoped campaign list
- [ ] `show`: campaign + per-target publishes, ownership 404
- [ ] `cancel`: pending/processing campaign → status cancelled, pending targets cancelled; already-published untouched
- [ ] `retry`: failed targets re-queued idempotently (job uniqueId + pending-skip); no duplicates
- [ ] Audit logging (no tokens)
- [ ] Run test → passes

### 2.3 Campaign Resources

**RED**: Write resource tests
- [ ] `tests/Feature/SocialPublish/SocialPublishCampaignResourceTest.php`
- [ ] Test: resource includes campaign status + per-target publishes
- [ ] Test: resource does not include tokens
- [ ] Run test → fails

**GREEN**: Create `app/Http/Resources/SocialPublishCampaignResource.php` and `SocialPublishCampaignCollection.php`
- [ ] Campaign resource: id, user_id, clip_review_id, status, publishes (SocialPublish collection), timestamps
- [ ] SocialPublish resource: already exists; ensure it includes `campaign_id`
- [ ] Run test → passes

**REFACTOR**: Verify error paths, run full backend test suite.

---

## Phase 3: Routes & Registration (Backend)

### 3.1 Route Registration

- [ ] Add routes under `auth:sanctum` group in `routes/api.php`:
  - `POST /social/publish/campaign` → `SocialPublishCampaignController@store` (throttle:social-campaign-publish, VerifyCsrfToken)
  - `GET /social/publish/campaigns` → `SocialPublishCampaignController@index` (throttle:social-list)
  - `GET /social/publish/campaigns/{campaign}` → `SocialPublishCampaignController@show` (throttle:social-list)
  - `POST /social/publish/campaigns/{campaign}/cancel` → `SocialPublishCampaignController@cancel` (throttle:social-campaign-cancel, VerifyCsrfToken)
  - `POST /social/publish/campaigns/{campaign}/retry` → `SocialPublishCampaignController@retry` (throttle:social-campaign-retry, VerifyCsrfToken)
- [ ] Verify rate limiter names exist in `AppServiceProvider`/`RateLimiter` (add `social-campaign-publish`, `social-campaign-retry`, `social-campaign-cancel` at 20/min if missing)

**REFACTOR**: Run full backend test suite.

---

## Phase 4: Per-Target Job Campaign Integration (Backend)

### 4.1 Update PublishTo* Jobs for Campaign Support

**RED**: Write job campaign tests
- [ ] Extend `tests/Unit/Jobs/PublishToYouTubeTest.php`, `PublishToInstagramTest.php`, `PublishToTikTokTest.php` (or new campaign job tests)
- [ ] Test: job accepts optional campaignId and updates campaign aggregate status on completion
- [ ] Test: all targets published → campaign `published`
- [ ] Test: some published some failed → campaign `partially_published`
- [ ] Test: all failed → campaign `failed`
- [ ] Test: retry idempotent (uniqueId + pending-skip) → no duplicate
- [ ] Run test → fails

**GREEN**: Update `app/Jobs/PublishToYouTube.php`, `PublishToInstagram.php`, `PublishToTikTok.php`
- [ ] Constructor accepts optional `campaignId`
- [ ] On completion (published/failed), recompute campaign aggregate status:
  - all publishes published → campaign `published`
  - at least one published and none pending/processing → campaign `partially_published` (if any failed)
  - all failed → campaign `failed`
  - any pending/processing → campaign `processing`
- [ ] Idempotency: skip if not pending (already handled); uniqueId per publish
- [ ] Run test → passes

**REFACTOR**: Extract campaign status recomputation into a shared trait/helper to avoid duplication across three jobs; run full backend test suite.

### 4.2 (Optional) PublishCampaignJob Orchestrator

- [ ] Decide: controller-direct dispatch vs. orchestrator job. Spec permits controller-direct dispatch with per-target jobs updating campaign aggregate.
- [ ] If orchestrator job used: create `PublishCampaignJob` that transitions campaign pending→processing then dispatches per-target jobs.

---

## Phase 5: Frontend Types, API Client, Hooks

### 5.1 Frontend Types

- [ ] Update `apps/web/src/features/social-publish/types.ts`:
  - `SocialPublishCampaignStatus = 'pending' | 'processing' | 'published' | 'partially_published' | 'failed' | 'cancelled'`
  - `SocialPublishCampaign` interface (id, user_id, clip_review_id, status, publishes[], timestamps)
  - `PublishCampaignTarget` (platform, title, description?)
  - `PublishCampaignRequest` (clip_review_id, targets: PublishCampaignTarget[])
  - `PublishCampaignResponse` (campaign)
  - `PublishCampaignTargetResponse` (per-target publish)
  - Extend `SocialPublish` with optional `campaign_id: number | null`

### 5.2 Frontend API Client

- [ ] Update `apps/web/src/features/social-publish/api/index.ts`:
  - `createPublishCampaign(body)` → POST /api/v1/social/publish/campaign
  - `getPublishCampaigns()` → GET /api/v1/social/publish/campaigns
  - `getPublishCampaign(id)` → GET /api/v1/social/publish/campaigns/{id}
  - `cancelPublishCampaign(id)` → POST /api/v1/social/publish/campaigns/{id}/cancel
  - `retryPublishCampaign(id)` → POST /api/v1/social/publish/campaigns/{id}/retry
  - CSRF handling for state-changing calls (reuse existing `request()`)

### 5.3 Frontend Hooks

- [ ] Update `apps/web/src/features/social-publish/hooks.ts`:
  - `usePublishCampaign()`: create campaign, isPending, error
  - `usePublishCampaigns()`: list campaigns
  - `usePublishCampaign(id)`: fetch single campaign
  - `useCancelPublishCampaign(id)`: cancel
  - `useRetryPublishCampaign(id)`: retry
  - Error classification extended for campaign errors

**REFACTOR**: Run frontend lint (eslint) → clean; run frontend build → clean.

---

## Phase 6: Frontend Multi-Target Dialog

### 6.1 PublishCampaignDialog Component

- [ ] Create `apps/web/src/features/social-publish/components/PublishCampaignDialog.tsx`:
  - Platform multi-select (checkboxes) showing only connected platforms (`availablePlatforms`)
  - Per-platform metadata fields (title, description) for each selected platform
  - Confirm-before-execute: validation on confirm, no API call until confirmed
  - Pre-execution validation errors shown; nothing queued when invalid
  - Per-platform states: queued/publishing/published/failed (color + text badges)
  - Retry failed target button
  - Cancel campaign button
  - Live region for status announcements
  - Keyboard accessible (Tab, Enter, Space, Escape); visible focus indicators; ARIA labels

### 6.2 Update PublishList/PublishCard

- [ ] `PublishCard.tsx`: show campaign badge when `campaign_id` present; per-platform state badge
- [ ] `PublishList.tsx`: support campaign grouping/badge display

**REFACTOR**: Verify styling/accessibility per design tokens; run frontend lint (eslint) → clean; run frontend build → clean.

---

## Phase 7: Frontend Tests (Vitest)

### 7.1 PublishCampaignDialog.test.tsx

- [ ] Renders platform multi-select only for connected platforms
- [ ] Per-platform metadata fields render per selected platform
- [ ] Confirm-before-execute: no publish API call until confirm clicked
- [ ] Pre-execution validation errors shown, nothing queued when invalid
- [ ] Per-platform states: queued/publishing/published/failed
- [ ] Retry failed target
- [ ] Cancel campaign
- [ ] Accessibility: keyboard navigation, focus indicators, ARIA labels, live region announcements

### 7.2 PublishCampaignList/Card tests

- [ ] Campaign badge shown
- [ ] Per-platform status badges shown

- [ ] Run Vitest → all pass

---

## Phase 8: E2E Testing (Playwright)

### 8.1 Playwright E2E Tests

- [ ] Create `apps/web/tests/e2e/one-click-publish.spec.ts`
- [ ] Test: publish approved clip to all connected platforms (all-success) → campaign `published`, all per-target publishes `published` (3 viewports)
- [ ] Test: one failing target (partial-success) → campaign `partially_published` (3 viewports)
- [ ] Test: all targets failing → campaign `failed` (3 viewports)
- [ ] Test: disconnected account target → that target failed, others proceed (3 viewports)
- [ ] Test: retry failed target → no duplicate publication (3 viewports)
- [ ] Test: cancel campaign before execution → pending targets cancelled (3 viewports)
- [ ] Test: confirm-before-execute validation error → nothing queued (3 viewports)
- [ ] Test: no tokens in browser dev tools / network (3 viewports)
- [ ] Test: keyboard navigation works throughout (3 viewports)
- [ ] Test: focus indicators visible (3 viewports)
- [ ] Test: console cleanliness (3 viewports)
- [ ] Test: network cleanliness (3 viewports)
- [ ] Run test → fails, then → passes

---

## Phase 9: Integration Tests (Backend)

### 9.1 SocialPublishCampaignIntegrationTest

- [ ] `tests/Feature/SocialPublish/SocialPublishCampaignIntegrationTest.php`
- [ ] Test: full campaign flow all-success (POST → jobs run inline → all published, campaign `published`)
- [ ] Test: full campaign flow partial-success (one target fails → campaign `partially_published`, per-target statuses independent)
- [ ] Test: full campaign flow all-failed (all targets fail → campaign `failed`)
- [ ] Test: disconnected account target → target marked failed, other targets proceed
- [ ] Test: retry failed target → no duplicate, target republished
- [ ] Test: cancel campaign before execution → pending targets cancelled, status `cancelled`
- [ ] Test: publish uses encrypted token (never in response/logs)
- [ ] Test: failed publish stores sanitized error_message (no tokens)
- [ ] Test: duplicate publish blocked via job idempotency
- [ ] Uses fakes bound in testing env; no network

---

## Phase 10: Governance, Lint & Final Verification

### 10.1 Governance & Lint

- [ ] Run `vendor/bin/pint --dirty --format agent` (backend) → clean
- [ ] Run frontend lint (eslint) → clean
- [ ] Run frontend build → clean
- [ ] Run governance tests (`php artisan test --filter=Governance`) → all pass

### 10.2 Final Verification

- [ ] Backend: `php artisan test --compact` → all pass
- [ ] Frontend: `npm run test` → all pass
- [ ] E2E: `npm run test:e2e` → all pass at 3 viewports
- [ ] Governance: `php artisan test --filter=Governance` → all pass

### 10.3 Manual Verification Checklist

- [ ] Publish approved clip to all connected platforms → campaign published, per-target published
- [ ] Partial success represented accurately (partially_published)
- [ ] All-failed → campaign failed
- [ ] Disconnected account → that target failed, others proceed, reconnect guidance shown
- [ ] Retry failed target → no duplicate publication
- [ ] Cancel campaign → pending targets cancelled, published targets remain
- [ ] No tokens in browser dev tools / network / Laravel logs
- [ ] Keyboard navigation works throughout
- [ ] Focus indicators visible

### 10.4 Evidence Collection

- [ ] Update `specs/122-one-click-publishing/evidence.md` (Orchestrator commits — Planner cannot edit evidence.md)
- [ ] Record test results, screenshots, manual verification

---

## File Creation Summary

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

### Backend (modified)
| File | Change |
|------|--------|
| `app/Models/SocialPublish.php` | Add `campaign_id` fillable + `campaign()` relationship + scope |
| `routes/api.php` | Add campaign routes under auth:sanctum + CSRF + rate limit |
| `app/Jobs/PublishToYouTube.php` | Accept campaignId, update campaign aggregate on completion |
| `app/Jobs/PublishToInstagram.php` | Accept campaignId, update campaign aggregate on completion |
| `app/Jobs/PublishToTikTok.php` | Accept campaignId, update campaign aggregate on completion |

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

### Tests
| File | Purpose |
|------|---------|
| `tests/Unit/Enums/SocialPublishCampaignStatusTest.php` | Campaign status enum unit tests |
| `tests/Unit/Models/SocialPublishCampaignTest.php` | Campaign model unit tests |
| `tests/Feature/SocialPublish/PublishCampaignRequestTest.php` | Campaign request validation |
| `tests/Feature/SocialPublish/SocialPublishCampaignControllerTest.php` | Campaign controller feature tests |
| `tests/Feature/SocialPublish/SocialPublishCampaignResourceTest.php` | Campaign resource tests |
| `tests/Feature/SocialPublish/SocialPublishCampaignIntegrationTest.php` | Campaign integration tests |
| `apps/web/src/features/social-publish/components/PublishCampaignDialog.test.tsx` | Dialog Vitest tests |
| `apps/web/tests/e2e/one-click-publish.spec.ts` | Playwright E2E (3 viewports) |

---

## Branch & Commit Strategy

**Branch**: `@carlosegoulart/122/feat/one-click-publishing`

**Commits** (Conventional Commits):
1. `feat(social-publish): add SocialPublishCampaign model, enum, and migrations`
2. `feat(social-publish): add PublishCampaignRequest and campaign controller`
3. `feat(social-publish): add campaign routes, resources, and rate limiting`
4. `feat(social-publish): integrate campaign_id into per-target publish jobs`
5. `feat(social-publish): add frontend campaign types, API client, hooks, and dialog`
6. `test(social-publish): add backend unit, feature, and integration tests`
7. `test(social-publish): add frontend Vitest and Playwright E2E tests`
8. `docs(social-publish): document one-click publishing implementation`

Each commit references: `Refs #122`
