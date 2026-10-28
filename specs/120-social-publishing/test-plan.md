# M11 — Instagram Publishing Test Plan

## Overview

This test plan defines all test scenarios required to verify the Instagram Publishing implementation. Tests are organized by layer (unit, feature, integration, E2E) and mapped to acceptance criteria. M11 reuses M9/M10 infrastructure **exactly** (the `SocialPublisher` interface, `PublishResult`, `SocialPublish` model, routes); tests focus on the Instagram-specific additions plus regression of existing YouTube publishing.

An **Auth Infrastructure Verification** section is included to gate the M10 auth 500 fix (sessions table, APP_KEY, SANCTUM_STATEFUL_DOMAINS) — confirming `GET /sanctum/csrf-cookie` → 204, register → 201, login → 200, session persistence — since M11 publishing requires authenticated sessions.

---

## Test Coverage Matrix

| Acceptance Criterion | Unit Tests | Feature Tests | Integration Tests | E2E Tests |
|---------------------|------------|---------------|-------------------|-----------|
| Auth: csrf-cookie → 204, register → 201, login → 200, session persists | | ✓ | | ✓ |
| SocialPublisher::class . ':instagram' binding → InstagramPublisher | ✓ | | | |
| InstagramPublisher implements SocialPublisher (M10 interface) | ✓ | | | |
| PublishToInstagram job follows PublishToYouTube pattern | ✓ | ✓ | ✓ | |
| SocialPublish model reused (M10, no schema change) | ✓ | ✓ | | |
| POST /api/v1/social/publish supports platform="instagram" | | ✓ | ✓ | ✓ |
| GET /api/v1/social/publishes and GET /api/v1/social/publishes/{publish} | | ✓ | | ✓ |
| PublishClipRequest accepts platform in:youtube,instagram | | ✓ | | |
| PublishClipDialog supports platform="instagram" | ✓ | | | ✓ |
| Tokens encrypted at rest, never leaked | ✓ | ✓ | ✓ | ✓ |
| CSRF, rate limiting 20/min, ownership 404s | | ✓ | ✓ | |
| CI: FakeInstagramPublisher, no network | ✓ | | | |
| E2E Playwright at 3 viewports | | | | ✓ |

---

## 0. Auth Infrastructure Verification (Feature Tests)

**File**: `tests/Feature/Auth/AuthFlowVerificationTest.php`

| Test Case | Description |
|-----------|-------------|
| `csrf_cookie_returns_204` | `GET /sanctum/csrf-cookie` → 204 No Content |
| `register_returns_201` | `POST /api/v1/auth/register` → 201 Created |
| `login_returns_200` | `POST /api/v1/auth/login` → 200 OK |
| `session_persists_across_requests` | After login, authenticated request (e.g. `/api/v1/auth/me`) → 200 |
| `login_regenerates_session` | Login triggers session regenerate (no 500) |

These tests gate the M10 auth 500 fix (sessions table + APP_KEY + SANCTUM_STATEFUL_DOMAINS). They must pass before M11 E2E publish flows are meaningful.

---

## 1. Unit Tests (Backend)

### 1.1 SocialPublisher Contract Tests
**File**: `tests/Unit/Contracts/SocialPublisherContractTest.php`

| Test Case | Description |
|-----------|-------------|
| `social_publisher_interface_exists` | Interface declared in `App\Contracts` |
| `interface_defines_get_platform` | `getPlatform(): string` declared |
| `interface_defines_get_platform_label` | `getPlatformLabel(): string` declared |
| `interface_defines_publish_video` | `publishVideo(array): PublishResult` declared |
| `interface_defines_get_publish_status` | `getPublishStatus(string): PublishStatus` declared |
| `interface_defines_has_publish_permission` | `hasPublishPermission(string): bool` declared |
| `publish_result_has_required_properties` | externalVideoId, videoUrl, status, errorMessage |

### 1.2 FakeInstagramPublisher Tests
**File**: `tests/Unit/Social/FakeInstagramPublisherTest.php`

| Test Case | Description |
|-----------|-------------|
| `fake_instagram_publisher_implements_interface` | Implements all SocialPublisher methods |
| `fake_get_platform_returns_instagram` | Returns 'instagram' |
| `fake_get_platform_label_returns_instagram` | Returns 'Instagram' |
| `fake_publish_video_returns_successful_result` | Returns PublishResult(status: 'published') with post id/url |
| `fake_publish_video_returns_failed_result` | Returns PublishResult(status: 'failed') with sanitized error message |
| `fake_publish_video_makes_no_network_calls` | No HTTP client calls (deterministic) |
| `fake_has_publish_permission_returns_bool` | Returns true/false |
| `fake_get_publish_status_returns_publish_status` | Returns PublishStatus |
| `fake_publish_is_deterministic` | Same input → same output every call |

### 1.3 InstagramPublisher Tests
**File**: `tests/Unit/Social/InstagramPublisherTest.php`

| Test Case | Description |
|-----------|-------------|
| `get_platform_returns_instagram` | Returns 'instagram' |
| `get_platform_label_returns_instagram` | Returns 'Instagram' |
| `publish_video_resolves_connection_for_user` | Uses provided SocialConnection |
| `publish_video_obtains_valid_access_token` | Calls SocialConnectionManager::getValidAccessToken |
| `publish_video_calls_graph_api` | Calls Instagram Graph API media/publish endpoints |
| `publish_video_returns_success_on_valid_response` | PublishResult(status: 'published', externalVideoId, videoUrl) |
| `publish_video_returns_failure_on_api_error` | PublishResult(status: 'failed', sanitized errorMessage) |
| `publish_video_error_message_contains_no_tokens` | errorMessage never exposes access/refresh tokens |
| `has_publish_permission_returns_true_when_scopes_present` | Token valid, scopes present |
| `has_publish_permission_returns_false_when_scopes_missing` | Missing required scopes |
| `get_publish_status_returns_status` | Returns PublishStatus for a post id |
| `no_tokens_in_logs` | All Log:: calls exclude tokens |

### 1.4 PublishToInstagram Job Tests
**File**: `tests/Unit/Jobs/PublishToInstagramTest.php`

| Test Case | Description |
|-----------|-------------|
| `job_revalidates_ownership_and_approval` | Re-checks clip belongs to user and is approved |
| `job_resolves_instagram_publisher` | Resolves `SocialPublisher::class . ':instagram'` |
| `job_calls_publisher_publish_video` | Calls InstagramPublisher::publishVideo() |
| `job_marks_published_on_success` | status='published', sets external_video_id/video_url |
| `job_marks_failed_on_failure` | status='failed', sets errorMessage (sanitized) |
| `job_dispatches_social_published_event` | Event dispatched on success |
| `job_retries_with_exponential_backoff` | tries=3, backoff=5 |
| `job_idempotent_skip_if_not_pending` | Skips if status !== pending |
| `job_unique_id` | `social-publish:{publishId}` |
| `job_no_inference_in_http_process` | Publishing is async via queue only |

---

## 2. Feature Tests (Backend API)

### 2.1 Publish Request Validation Tests
**File**: `tests/Feature/Social/PublishRequestTest.php`

| Test Case | Description |
|-----------|-------------|
| `platform_must_be_youtube_or_instagram` | Rejects other values (422) |
| `platform_accepts_youtube` | YouTube still valid (regression) |
| `platform_accepts_instagram` | Instagram valid |
| `clip_review_id_required_and_integer` | Missing/invalid → 422 |
| `title_required` | Missing → 422 |
| `description_optional` | Accepts string or omits |

### 2.2 SocialPublishController Tests
**File**: `tests/Feature/Social/SocialPublishControllerTest.php`

#### Authentication & Authorization
| Test Case | Description |
|-----------|-------------|
| `store_returns_401_for_guest` | Unauthenticated → 401 |
| `index_returns_401_for_guest` | Unauthenticated → 401 |
| `show_returns_401_for_guest` | Unauthenticated → 401 |
| `store_returns_404_for_unowned_clip` | Clip not owned by user → 404 |
| `store_returns_404_for_unapproved_clip` | Clip not approved → 404 |
| `show_returns_404_for_other_user_publish` | 404 hides existence |

#### POST /api/v1/social/publish
| Test Case | Description |
|-----------|-------------|
| `store_instagram_returns_202` | Valid Instagram request → 202, job enqueued |
| `store_youtube_returns_202` | Valid YouTube request → 202 (regression) |
| `store_instagram_creates_social_publish_row` | Row status='pending', platform='instagram' |
| `store_youtube_creates_social_publish_row` | Row status='pending', platform='youtube' (regression) |
| `store_instagram_404_when_no_connected_instagram` | No Instagram connection → 404 |
| `store_youtube_404_when_no_connected_youtube` | No YouTube connection → 404 (regression) |
| `store_dispatches_correct_job` | Instagram → PublishToInstagram, YouTube → PublishToYouTube |
| `store_respects_rate_limit` | 20/min → 429 on excess |
| `store_requires_csrf_token` | 419 without valid X-XSRF-TOKEN |

#### GET /api/v1/social/publishes
| Test Case | Description |
|-----------|-------------|
| `index_returns_own_publishes_only` | Other user's publishes excluded |
| `index_paginates_results` | Standard pagination envelope |
| `index_includes_platform_field` | Each item has platform field |

#### GET /api/v1/social/publishes/{publish}
| Test Case | Description |
|-----------|-------------|
| `show_returns_publish_details` | 200 with publish object |
| `show_excludes_tokens` | No token fields in response |

---

## 3. Integration Tests (Backend)

**File**: `tests/Feature/Social/SocialPublishIntegrationTest.php`

| Test Case | Description |
|-----------|-------------|
| `full_instagram_publish_flow` | POST publish (instagram) → job (fake) → status=published → appears in GET /publishes |
| `full_youtube_publish_flow` | POST publish (youtube) → published (regression) |
| `youtube_and_instagram_both_publishable` | Publish same clip to both platforms |
| `no_connected_instagram_returns_404` | API returns 404, no row created |
| `unapproved_clip_returns_404` | Clip review not approved → 404 |
| `cross_user_publish_returns_404` | Other user's clip → 404 |
| `publish_failure_marks_failed` | Publisher throws → status='failed', errorMessage set |
| `publish_retry_on_transient_failure` | Job retries up to 3 times |
| `social_published_event_dispatched` | Event fired on successful publish |
| `tokens_never_in_api_response` | Publish responses lack token fields |
| `tokens_never_in_logs` | Logs lack access_token/refresh_token |
| `ownership_enforced_on_all_endpoints` | 404 for cross-user access |

---

## 4. Frontend Unit Tests (Vitest + React Testing Library)

### 4.1 API Client Tests
**File**: `apps/web/src/features/social/api/index.test.ts`

| Test Case | Description |
|-----------|-------------|
| `publishClip_returns_publish_object` | Maps response to typed object |
| `publishClip_sends_platform_field` | Sends `platform: 'instagram'` |
| `publishClip_handles_404_no_connection` | Classified error for missing connection |
| `publishClip_handles_404_unapproved` | Classified error for unapproved/not owned |
| `publishClip_includes_csrf_token` | Adds X-XSRF-TOKEN header |
| `getPublishes_returns_own_publishes` | Filters to authenticated user |
| `getPublishes_handles_network_error` | Classified network error |

### 4.2 PublishClipDialog Component Tests
**File**: `apps/web/src/features/social/components/PublishClipDialog.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `renders_platform_selector` | YouTube and Instagram options |
| `instagram_disabled_when_no_connection` | Instagram option disabled, tooltip explains |
| `youtube_enabled_when_connection_exists` | YouTube option enabled |
| `submits_with_platform_instagram` | API called with platform='instagram' |
| `submits_with_platform_youtube` | API called with platform='youtube' (regression) |
| `shows_loading_during_submission` | Spinner + disabled submit |
| `shows_success_on_publish_accepted` | Toast + close dialog |
| `shows_error_on_submission_failure` | Error message |
| `keyboard_navigation_works` | Tab to platform selector, Enter/Space activates |
| `focus_indicators_visible` | 3px outline on focused elements |
| `aria_labels_on_platform_buttons` | Platform buttons have aria-label |

### 4.3 PublishCard Component Tests
**File**: `apps/web/src/features/social/components/PublishCard.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `renders_instagram_icon_and_label` | Instagram branding |
| `renders_youtube_icon_and_label` | YouTube branding (regression) |
| `renders_pending_status` | Yellow badge "Processing..." |
| `renders_published_status` | Green badge + link to post |
| `renders_failed_status` | Red badge + retry option |

### 4.4 PublishList Component Tests
**File**: `apps/web/src/features/social/components/PublishList.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `renders_loading_skeletons` | Skeletons when loading |
| `renders_empty_state` | "No publishes yet" + CTA |
| `renders_list_of_cards` | Maps publishes to PublishCard |
| `filters_by_platform_optional` | Platform filter works |

---

## 5. E2E Tests (Playwright)

**File**: `apps/web/tests/e2e/social-publish.spec.ts`

**Viewports**: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)

**Test User**: Pre-authenticated via test setup, with connected Instagram (fake) and connected YouTube (fake)

| Test Case | Description | Viewports |
|-----------|-------------|-----------|
| `Auth - Register & Login` | Verify register → login → session persists (prerequisite) | All 3 |
| `Auth - CSRF Cookie` | Verify GET /sanctum/csrf-cookie → 204 | All 3 |
| `Publish Clip - Open Dialog` | Open PublishClipDialog from approved clip | All 3 |
| `Publish Clip - Select Instagram` | Select Instagram platform, verify enabled | All 3 |
| `Publish Clip - Instagram Disabled` | With no Instagram connection, verify disabled | All 3 |
| `Publish Clip - Submit Instagram` | Submit, verify pending state, toast | All 3 |
| `Publish Clip - Submit YouTube` | Submit YouTube (regression) | All 3 |
| `Publish List - Shows Publish` | Verify publish appears in PublishList | All 3 |
| `Publish List - Status Transition` | pending → published (fake job completes) | All 3 |
| `Publish Card - Published Link` | Click link to provider post | All 3 |
| `Publish - Console Cleanliness` | No unexpected console errors | All 3 |
| `Publish - Network Cleanliness` | No 4xx/5xx except expected | All 3 |
| `Publish - Keyboard Navigation` | Tab through dialog, all actions reachable | All 3 |
| `Publish - Focus Indicators` | 3px outline on focused elements | All 3 |
| `Publish - Responsive Layout` | Dialog usable at 390px width | All 3 |
| `Publish - Touch Targets` | All interactive ≥ 44px | All 3 |

### 5.3 Fake Publisher Strategy for E2E

Since real Instagram isn't available in CI:
- `FakeInstagramPublisher` bound in testing environment returns deterministic success.
- Job runs via queue worker in test environment; publish status transitions pending → published.
- E2E polls PublishList for status change.

---

## 6. Governance & Quality Gates

### 6.1 Backend Lint (Pint)
```bash
vendor/bin/pint --dirty --format agent
```
- 0 errors

### 6.2 Frontend Lint (ESLint)
```bash
npm run lint
```
- 0 errors

### 6.3 TypeScript Build
```bash
npm run build
```
- Compiles without errors

### 6.4 Governance Tests
```bash
php artisan test --filter=Governance
```
- All pass

### 6.5 Full Test Suite
```bash
# Backend
php artisan test --compact

# Frontend Unit
npm run test

# Frontend E2E
npm run test:e2e
```
- All pass

---

## 7. Manual Verification Checklist

| Check | Verification Method |
|-------|---------------------|
| Auth: register → 201, login → 200, csrf-cookie → 204 | Browser / API client |
| Session persists across requests | Reload page, remain authenticated |
| Publish clip to Instagram (fake) → pending → published | Browser: open dialog, select Instagram, submit, verify list |
| Publish clip to YouTube still works | Regression: same flow with YouTube |
| No Instagram connection → dialog disables Instagram | Remove connection, open dialog, Instagram grayed out |
| API 404 when no connected Instagram | Submit via dialog, verify 404 |
| Unapproved clip → 404 | Try publishing non-approved clip |
| Cross-user publish → 404 | Try publishing another user's clip |
| No tokens in browser dev tools / network | DevTools Network tab |
| No tokens in Laravel logs | `tail -f storage/logs/laravel.log` |
| Rate limiting: 20/min → 429 | Exceed limit, verify 429 |
| CSRF protection: missing token → 419 | Submit without X-XSRF-TOKEN |
| Keyboard navigation throughout | Tab through entire dialog |
| Focus indicators visible | 3px outline on each focused element |

---

## 8. Test Data Requirements

### 8.1 Fake Provider Behaviors
- `FakeInstagramPublisher`: deterministic success/failure, no network, implements `SocialPublisher`.

### 8.2 Database Seeding
- `SocialConnectionFactory`: connected Instagram and YouTube connections.
- `SocialPublishFactory`: publishes in various states.
- Factories for User, Project, ClipReview, DerivedAsset (existing).

---

## 9. Evidence Collection

Upon completion, update `specs/120-social-publishing/evidence.md` with:

1. **Test Results Summary**
   - Backend test count/pass/fail
   - Frontend test count/pass/fail
   - E2E test count/pass/fail (per viewport)
   - Governance test count

2. **Screenshots**
   - PublishClipDialog with Instagram (3 viewports)
   - PublishList with published Instagram post (3 viewports)
   - Instagram disabled state (3 viewports)
   - Focus indicators visible

3. **Console/Network Logs**
   - Clean console output
   - No token leakage in network requests

4. **Manual Verification Sign-off**
   - Each checklist item confirmed (including auth flow)

5. **Performance Notes**
   - API response times
   - Frontend render times

---

## 10. Test Execution Order (CI Pipeline)

```yaml
# Conceptual CI job order
jobs:
  backend-tests:
    - php artisan test --compact --filter=Unit
    - php artisan test --compact --filter=Feature   # includes AuthFlowVerificationTest
    - php artisan test --compact --filter=Integration
  frontend-tests:
    - npm run test       # Vitest unit/component
    - npm run lint       # ESLint
    - npm run build      # TypeScript compile
  e2e-tests:
    - npm run test:e2e   # Playwright at 3 viewports
  governance:
    - php artisan test --filter=Governance
  merge-gate:
    - scripts/merge_gate.py
```

---

## 11. Risk Areas & Mitigation

| Risk | Mitigation |
|------|------------|
| Auth 500 still present at runtime | AuthFlowVerificationTest gates csrf-cookie/register/login/session before E2E |
| Token leakage in logs | Unit test `no_tokens_in_logs`, code review of all Log:: calls |
| Token leakage in API | Resource tests verify whitelist, integration test `tokens_never_in_api_response` |
| Cross-user access | Feature tests `*_returns_404_for_other_user` on all endpoints |
| CSRF bypass | Feature tests `store_requires_csrf_token` |
| Rate limit bypass | Feature tests `store_respects_rate_limit` |
| No connected Instagram not handled | Feature test `store_instagram_404_when_no_connected_instagram` |
| Unapproved clip published | Feature test `store_returns_404_for_unapproved_clip` |
| UI accessibility gaps | E2E tests for keyboard, focus, ARIA |
| Mobile layout broken | E2E tests at 390x844 viewport |
| Interface drift from M10 | Spec enforces exact M10 interface (`publishVideo`, `hasPublishPermission`, `getPlatformLabel`); contract test catches drift |

---

## 12. Definition of Done (Testing)

- [ ] All unit tests pass (backend + frontend)
- [ ] All feature tests pass (including AuthFlowVerificationTest)
- [ ] All integration tests pass
- [ ] All E2E tests pass at 3 viewports
- [ ] Lint passes (backend + frontend)
- [ ] TypeScript build passes
- [ ] Governance tests pass
- [ ] Manual verification checklist complete (including auth flow)
- [ ] Evidence.md updated with results
- [ ] Independent Tester review approves
