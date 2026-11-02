# M14 — One-Click Publishing Test Plan

## Overview

This test plan defines the verification strategy for M14 One-Click Publishing. It follows the repository's TDD (RED → GREEN → REFACTOR) policy and AGENTS.md §13–§14 testing requirements.

M14 adds a campaign orchestration layer over the existing platform adapters (M10 YouTube, M11 Instagram, M12 TikTok). Tests focus on the campaign-specific additions: `SocialPublishCampaign` model, `SocialPublishCampaignStatus` enum, `campaign_id` on `SocialPublish`, `PublishCampaignRequest` validation, `SocialPublishCampaignController` (store/index/show/cancel/retry), campaign resources, pre-execution validation, per-target independent status and retry history, idempotent retry, cancellation, campaign API client/hooks/dialog, and E2E flows.

Test environments must use deterministic fakes only — no real platform API credentials, no network calls to platforms, per AGENTS.md §21 and spec §8.5.

---

## 1. Backend Unit Tests (Pest)

### 1.1 SocialPublishCampaignStatusTest

File: `tests/Unit/Enums/SocialPublishCampaignStatusTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.1.1 | `Pending::label()` | returns `'Pending'` |
| 1.1.2 | `Processing::label()` | returns `'Processing'` |
| 1.1.3 | `Published::label()` | returns `'Published'` |
| 1.1.4 | `PartiallyPublished::label()` | returns `'Partially Published'` |
| 1.1.5 | `Failed::label()` | returns `'Failed'` |
| 1.1.6 | `Cancelled::label()` | returns `'Cancelled'` |
| 1.1.7 | `Pending::color()` | returns `'gray'` |
| 1.1.8 | `Processing::color()` | returns `'blue'` |
| 1.1.9 | `Published::color()` | returns `'green'` |
| 1.1.10 | `PartiallyPublished::color()` | returns `'yellow'` |
| 1.1.11 | `Failed::color()` | returns `'red'` |
| 1.1.12 | `Cancelled::color()` | returns `'gray'` |

### 1.2 SocialPublishCampaignTest

File: `tests/Unit/Models/SocialPublishCampaignTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.2.1 | Campaign has `user` relationship | returns User instance |
| 1.2.2 | Campaign has `clipReview` relationship | returns ClipReview instance |
| 1.2.3 | Campaign has `publishes` relationship | returns HasMany of SocialPublish |
| 1.2.4 | `scopePending()` | returns only pending campaigns |
| 1.2.5 | `scopeProcessing()` | returns only processing campaigns |
| 1.2.6 | `scopePublished()` | returns only published campaigns |
| 1.2.7 | `scopePartiallyPublished()` | returns only partially_published campaigns |
| 1.2.8 | `scopeFailed()` | returns only failed campaigns |
| 1.2.9 | `scopeCancelled()` | returns only cancelled campaigns |
| 1.2.10 | `SocialPublish` has `campaign()` relationship | returns SocialPublishCampaign instance |
| 1.2.11 | `SocialPublish` `scopeByCampaign()` | returns only publishes for given campaign |

### 1.3 PublishCampaignRequestTest

File: `tests/Feature/SocialPublish/PublishCampaignRequestTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 1.3.1 | Valid targets array (2 platforms) | passes validation |
| 1.3.2 | Single valid target | passes validation |
| 1.3.3 | Invalid platform in targets (e.g. `twitter`) | 422 validation error |
| 1.3.4 | Missing `clip_review_id` | 422 validation error |
| 1.3.5 | Empty `targets` array | 422 validation error (min:1) |
| 1.3.6 | Missing `title` in a target | 422 validation error |
| 1.3.7 | `title` > 255 chars in a target | 422 validation error |
| 1.3.8 | `description` present (optional) | passes validation |
| 1.3.9 | `description` omitted | passes validation |

---

## 2. Backend Feature Tests (Pest)

### 2.1 SocialPublishCampaignControllerTest — store

File: `tests/Feature/SocialPublish/SocialPublishCampaignControllerTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 2.1.1 | POST campaign, all valid targets, connected platforms | 202 Accepted, campaign created `pending`, per-target `SocialPublish` records `pending`, jobs queued |
| 2.1.2 | POST campaign, one target has no connected platform | 422/400, all validation errors returned, NO targets queued, NO campaign created |
| 2.1.3 | POST campaign, invalid platform value | 422 validation error |
| 2.1.4 | POST campaign unauthenticated | 401 |
| 2.1.5 | POST campaign non-owned clip review | 404 (hides existence) |
| 2.1.6 | POST campaign not-approved clip review | 404 |
| 2.1.7 | POST campaign no completed rendered clip | 404 |
| 2.1.8 | POST campaign rate limited (>20/min) | 429 |
| 2.1.9 | POST campaign CSRF missing/invalid | 419 |

### 2.2 SocialPublishCampaignControllerTest — index/show

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 2.2.1 | GET campaigns list | 200, user-scoped (only own campaigns) |
| 2.2.2 | GET campaign/{id} owned | 200, resource shape correct, includes per-target publishes |
| 2.2.3 | GET campaign/{id} non-owned | 404 (hides existence) |

### 2.3 SocialPublishCampaignControllerTest — cancel

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 2.3.1 | POST campaign/{id}/cancel, status pending | 200, status → `cancelled`, pending targets cancelled |
| 2.3.2 | POST campaign/{id}/cancel, status processing | 200, status → `cancelled`, remaining pending targets cancelled, in-flight untouched |
| 2.3.3 | POST campaign/{id}/cancel, already published | cannot cancel published targets; those remain published |
| 2.3.4 | POST campaign/{id}/cancel non-owned | 404 |

### 2.4 SocialPublishCampaignControllerTest — retry

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 2.4.1 | POST campaign/{id}/retry, has failed targets | 202, failed targets re-queued, no duplicate publications |
| 2.4.2 | POST campaign/{id}/retry, no failed targets | 409, nothing to retry |
| 2.4.3 | POST campaign/{id}/retry non-owned | 404 |

### 2.5 SocialPublishCampaignResourceTest

File: `tests/Feature/SocialPublish/SocialPublishCampaignResourceTest.php`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 2.5.1 | Resource includes campaign `status` | status present and correct |
| 2.5.2 | Resource includes per-target `publishes` | publishes array present with per-target statuses |
| 2.5.3 | Resource does not include tokens | no `access_token`, `refresh_token` fields |
| 2.5.4 | `SocialPublish` resource includes `campaign_id` | `campaign_id` present when set |

---

## 3. Backend Integration Tests (Pest)

### 3.1 SocialPublishCampaignIntegrationTest

File: `tests/Feature/SocialPublish/SocialPublishCampaignIntegrationTest.php`

Uses fakes (`FakeYouTubePublisher`, `FakeInstagramPublisher`, `FakeTikTokPublisher`) bound in testing env; database transactions/rollback; no network. Queue: sync driver so jobs run inline.

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 3.1.1 | Full campaign flow all-success | 202 → campaign `published`, all per-target `SocialPublish` `published`, `external_video_id`/`video_url` set per target |
| 3.1.2 | Full campaign flow partial-success (one target fails) | campaign `partially_published`; published target `published`, failed target `failed` with sanitized `error_message`; statuses independent |
| 3.1.3 | Full campaign flow all-failed | campaign `failed`; all per-target `failed` |
| 3.1.4 | Disconnected account target | that target `failed`, other targets proceed and publish |
| 3.1.5 | Retry failed target | no duplicate publication; target republished, single `external_video_id` per platform |
| 3.1.6 | Cancel campaign before execution | pending targets cancelled, status `cancelled`; already-published targets remain `published` |
| 3.1.7 | Publish uses encrypted token (never in response) | response JSON contains no token values |
| 3.1.8 | Publish token never in logs | log records contain no `access_token`/`refresh_token` |
| 3.1.9 | Failed publish stores sanitized `error_message` | `error_message` present, no tokens |
| 3.1.10 | Duplicate publish blocked via job idempotency | second execution of same publish skipped (pending-skip) |
| 3.1.11 | Pre-execution validation: all targets checked before any queued | if any target invalid, no campaign created, no jobs queued |
| 3.1.12 | Cross-user campaign | user B attempts campaign on user A's clip → 404 |

---

## 4. Frontend Unit Tests (Vitest)

### 4.1 PublishCampaignDialog.test.tsx

File: `apps/web/src/features/social-publish/components/PublishCampaignDialog.test.tsx`

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 4.1.1 | Renders platform multi-select only for connected platforms | checkboxes present for platforms in `availablePlatforms` |
| 4.1.2 | Does not render unconnected platforms | platforms not in `availablePlatforms` absent |
| 4.1.3 | Per-platform metadata fields render per selected platform | title/description inputs appear for each selected platform |
| 4.1.4 | Confirm-before-execute: no publish API call until confirm | `createPublishCampaign` not called until confirm clicked |
| 4.1.5 | Pre-execution validation errors shown, nothing queued when invalid | validation errors displayed, no campaign created |
| 4.1.6 | Per-platform states: queued/publishing/published/failed | state badges reflect per-target status |
| 4.1.7 | Retry failed target | retry button re-queues failed target |
| 4.1.8 | Cancel campaign | cancel button cancels pending targets |
| 4.1.9 | Accessibility: keyboard navigation | Tab cycles through platform checkboxes, metadata inputs, confirm/cancel buttons |
| 4.1.10 | Accessibility: focus indicators | visible focus ring on interactive elements |
| 4.1.11 | Accessibility: ARIA labels | platform checkboxes have `aria-label`, confirm/cancel buttons have `aria-label` |
| 4.1.12 | Accessibility: live region announcements | status changes and errors announced via live region |

### 4.2 PublishCampaignList/Card tests

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 4.2.1 | Campaign badge shown | badge rendered when `campaign_id` present |
| 4.2.2 | Per-platform status badges shown | badges for each platform's status |

- [ ] Run Vitest → all pass

---

## 5. Frontend E2E Tests (Playwright)

File: `apps/web/tests/e2e/one-click-publish.spec.ts`

Default viewports: `390x844`, `768x1024`, `1440x900` (run all tests at all 3 viewports).

| # | Scenario | Expected Result |
|---|----------|-----------------|
| 5.1 | Publish approved clip to all connected platforms (all-success) | campaign `published`, all per-target badges `published` |
| 5.2 | Publish with one failing target (partial-success) | campaign `partially_published`; one published, one failed |
| 5.3 | Publish with all targets failing | campaign `failed`; all per-target badges red |
| 5.4 | Disconnected account target | that target failed with "Reconnect" guidance, other targets proceed |
| 5.5 | Retry failed target → no duplicate publication | retry succeeds, single `external_video_id` per platform |
| 5.6 | Cancel campaign before execution | pending targets cancelled, status `cancelled` |
| 5.7 | Confirm-before-execute validation error on invalid target → nothing queued | errors shown, no campaign created |
| 5.8 | No tokens in browser dev tools / network | no `access_token`/`refresh_token` in DevTools or network payloads |
| 5.9 | Keyboard navigation works throughout | Tab/Enter/Space/Escape operate all controls |
| 5.10 | Focus indicators visible | 3px outline on focused elements |
| 5.11 | Console cleanliness | no unexpected JS console errors |
| 5.12 | Network cleanliness | no failed resource loads, no unexpected 4xx/5xx unless scenario requires |

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

- Use `SocialConnection` factory with `platform` in (`youtube`, `instagram`, `tiktok`), `status = 'connected'`, encrypted `access_token`/`refresh_token`.
- Use `ClipReview` factory with `status = 'approved'` and a completed `DerivedAsset` (`type = 'clip_rendered'`, `render_status = 'completed'`).
- Use `SocialPublish` factory for per-target publish records.
- Use `SocialPublishCampaign` factory for campaign records.
- Fakes (`FakeYouTubePublisher`, `FakeInstagramPublisher`, `FakeTikTokPublisher`) bound in testing env via `AppServiceProvider` — no real platform API, no network.
- Queue: sync driver in tests so jobs run inline.

---

## 9. Environment Guards

- E2E needs prepared PostgreSQL, installed Chromium browsers, and free ports 5173/8000 (AGENTS.md Known Limitations).
- CI uses deterministic fakes; no multi-gigabyte model downloads, no real platform API credentials.
- No secrets in fixtures committed to Git (AGENTS.md §23).
