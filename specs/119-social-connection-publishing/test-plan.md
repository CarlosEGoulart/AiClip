# M10 — YouTube Publishing Test Plan

## Overview

This test plan defines all test scenarios required to verify the YouTube Publishing implementation. Tests are organized by layer (unit, feature, integration, E2E) and mapped to acceptance criteria. M10 builds on M9's Social Connection Framework and M7's Clip Review Experience.

---

## Test Coverage Matrix

| Acceptance Criterion | Unit Tests | Feature Tests | Integration Tests | E2E Tests |
|---------------------|------------|---------------|-------------------|-----------|
| Publish approved clip to YouTube | | ✓ | ✓ | ✓ |
| Publish job tracked via SocialPublish | ✓ | ✓ | ✓ | |
| Tokens encrypted at rest, never exposed | ✓ | ✓ | ✓ | ✓ |
| User ownership enforced (clip, connection, publish) | | ✓ | ✓ | ✓ |
| CSRF + rate limiting on publish endpoint | | ✓ | | |
| FakeYouTubePublisher in CI (no real network) | ✓ | | | |
| Frontend publish dialog + publish list UI | ✓ | | | ✓ |
| Accessibility (keyboard, focus, ARIA, touch targets) | | | | ✓ |
| Backend/frontend tests, lint, build, governance, E2E pass | ✓ | ✓ | ✓ | ✓ |

---

## 1. Unit Tests (Backend)

### 1.1 SocialPublish Model Tests
**File**: `tests/Unit/Models/SocialPublishTest.php`

| Test Case | Description |
|-----------|-------------|
| `it_has_correct_fillable_attributes` | Verify fillable includes all publish fields |
| `it_has_pending_scope` | Scope filters to status=pending |
| `it_has_uploading_scope` | Scope filters to status=uploading |
| `it_has_published_scope` | Scope filters to status=published |
| `it_has_failed_scope` | Scope filters to status=failed |
| `it_belongs_to_user` | Relationship returns User instance |
| `it_belongs_to_social_connection` | Relationship returns SocialConnection instance |
| `it_belongs_to_clip_review` | Relationship returns ClipReview instance |
| `it_enforces_user_platform_index` | Index on [user_id, status] exists |

### 1.2 SocialPublishStatus Enum Tests
**File**: `tests/Unit/Enums/SocialPublishStatusTest.php`

| Test Case | Description |
|-----------|-------------|
| `it_defines_pending_status` | Enum case exists with value 'pending' |
| `it_defines_uploading_status` | Enum case exists with value 'uploading' |
| `it_defines_published_status` | Enum case exists with value 'published' |
| `it_defines_failed_status` | Enum case exists with value 'failed' |

### 1.3 SocialPublisher Contract Tests
**File**: `tests/Unit/Contracts/SocialPublisherTest.php`

| Test Case | Description |
|-----------|-------------|
| `it_defines_required_interface_methods` | All 5 methods declared |
| `publish_result_has_required_properties` | PublishResult has externalVideoId, videoUrl, status, errorMessage |
| `publish_status_has_required_properties` | PublishStatus has externalVideoId, status, videoUrl, errorMessage |

### 1.4 YouTubePublisher Tests
**File**: `tests/Unit/Services/YouTubePublisherTest.php`

| Test Case | Description |
|-----------|-------------|
| `get_platform_returns_youtube` | Returns 'youtube' |
| `get_platform_label_returns_youtube` | Returns 'YouTube' |
| `publish_video_success_returns_publish_result` | Returns PublishResult with external ID and URL |
| `publish_video_failure_returns_failed_result` | Returns PublishResult with status='failed' and error message |
| `get_publish_status_returns_publish_status` | Returns PublishStatus object |
| `has_publish_permission_returns_boolean` | Returns true/false |
| `publish_video_does_not_leak_token` | Token not in result or logs |

### 1.5 FakeYouTubePublisher Tests
**File**: `tests/Unit/Services/FakeYouTubePublisherTest.php`

| Test Case | Description |
|-----------|-------------|
| `fake_youtube_publisher_implements_interface` | Implements all methods |
| `fake_publisher_is_deterministic` | Same input → same output |
| `fake_publisher_no_network_calls` | No HTTP client / network usage |
| `fake_publisher_success_scenario` | Returns published result when configured |
| `fake_publisher_failure_scenario` | Returns failed result when configured |
| `fake_publisher_get_publish_status_returns_status` | Returns configured status |

### 1.6 PublishToYouTube Job Tests
**File**: `tests/Unit/Jobs/PublishToYouTubeTest.php`

| Test Case | Description |
|-----------|-------------|
| `job_implements_should_queue` | Uses ShouldQueue contract |
| `job_has_tries_and_backoff` | tries=3, backoff=5 |
| `unique_id_based_on_publish_id` | Returns "social-publish:{id}" |
| `skips_when_not_pending` | Non-pending publish is skipped (idempotency) |
| `transitions_to_uploading_then_published` | Status flow on success |
| `transitions_to_uploading_then_failed` | Status flow on failure |
| `stores_external_video_id_and_url_on_success` | Populates external_video_id, video_url |
| `stores_sanitized_error_on_failure` | Error message without tokens |
| `revalidates_ownership` | User must own clip review and connection |
| `uses_valid_access_token` | Calls getValidAccessToken (refreshes if needed) |

---

## 2. Feature Tests (Backend API)

### 2.1 SocialPublishResource Tests
**File**: `tests/Feature/SocialPublish/SocialPublishResourceTest.php`

| Test Case | Description |
|-----------|-------------|
| `resource_includes_all_public_fields` | id, platform, platform_label, status, external_video_id, video_url, title, description, error_message, timestamps |
| `resource_maps_platform_labels` | youtube→YouTube |
| `collection_wraps_in_pagination_envelope` | Standard Laravel pagination structure |

### 2.2 Request Validation Tests
**File**: `tests/Feature/SocialPublish/SocialPublishRequestTest.php`

| Test Case | Description |
|-----------|-------------|
| `publish_request_validates_clip_review_id` | Required, integer, exists |
| `publish_request_validates_platform` | Must be 'youtube' (422 otherwise) |
| `publish_request_validates_title` | Required, string |
| `publish_request_accepts_optional_description` | Nullable string |

### 2.3 SocialPublishController Tests
**File**: `tests/Feature/SocialPublish/SocialPublishControllerTest.php`

#### 2.3.1 Authentication & Authorization
| Test Case | Description |
|-----------|-------------|
| `publish_returns_401_for_guest` | Unauthenticated → 401 |
| `list_returns_401_for_guest` | Unauthenticated → 401 |
| `status_returns_401_for_guest` | Unauthenticated → 401 |
| `publish_returns_only_own_clips` | Other user's clip reviews not publishable |
| `publish_returns_404_for_other_user_clip` | Hides existence (404) |
| `status_returns_404_for_other_user_publish` | Hides existence (404) |
| `list_returns_only_own_publishes` | Other user's publishes not in response |

#### 2.3.2 POST /api/v1/social/publish (Initiate)
| Test Case | Description |
|-----------|-------------|
| `publish_returns_202_on_success` | Publish job enqueued, SocialPublish row created |
| `publish_creates_social_publish_row` | Row exists with status=pending |
| `publish_requires_approved_clip_review` | Non-approved → 404 |
| `publish_requires_completed_render` | Render not completed → 404 |
| `publish_requires_connected_youtube` | No connected YouTube connection → 404 |
| `publish_blocks_duplicate` | Existing publish for clip review → 409 |
| `publish_respects_rate_limit` | 20/min → 429 on excess |
| `publish_requires_csrf_token` | 419 without valid X-XSRF-TOKEN |
| `publish_does_not_return_token` | Response lacks access_token/refresh_token |

#### 2.3.3 GET /api/v1/social/publishes (List)
| Test Case | Description |
|-----------|-------------|
| `list_returns_empty_array_when_no_publishes` | 200, data: [] |
| `list_returns_user_publishes` | 200, data with publish objects |
| `list_paginates_results` | Pagination envelope when > 15 |

#### 2.3.4 GET /api/v1/social/publishes/{publish} (Status)
| Test Case | Description |
|-----------|-------------|
| `status_returns_publish_details` | 200 with publish object |
| `status_returns_404_for_nonexistent` | Publish not found |
| `status_returns_404_for_other_user` | Hides existence |

---

## 3. Integration Tests (Backend)

### 3.1 Full Publish Flow Tests
**File**: `tests/Feature/SocialPublish/SocialPublishIntegrationTest.php`

| Test Case | Description |
|-----------|-------------|
| `full_publish_flow_approved_clip_to_youtube` | POST → job runs inline → published → list shows published |
| `publish_status_reflects_published` | Status endpoint returns published with video URL |
| `failed_publish_stores_error_message` | Publisher fails → status=failed, error_message set |
| `duplicate_publish_blocked` | Second publish for same clip review → 409 |
| `publish_uses_encrypted_token` | Token used internally, never in response/logs |
| `publish_refreshes_expired_token` | Expired token refreshed before publish |
| `cross_user_publish_blocked` | Other user's clip review → 404 |
| `publish_without_youtube_connection_blocked` | No connected YouTube → 404 |

### 3.2 Security Integration Tests
| Test Case | Description |
|-----------|-------------|
| `tokens_never_in_api_response` | Publish responses lack token fields |
| `tokens_never_in_logs` | Log output lacks access_token, refresh_token |
| `ownership_enforced_on_all_endpoints` | 404 for cross-user access |
| `state_single_use_enforced` | (inherited from M9) reused connection state rejected |

---

## 4. Frontend Unit Tests (Vitest + React Testing Library)

### 4.1 API Client Tests
**File**: `apps/web/src/features/social-publish/api/index.test.ts`

| Test Case | Description |
|-----------|-------------|
| `publishClip_returns_publish_data` | Returns { publishId, status, platform } |
| `publishClip_includes_csrf_token` | Adds X-XSRF-TOKEN header |
| `publishClip_handles_validation_error` | Throws validation error with fields |
| `publishClip_handles_unauthorized` | Throws classified unauthorized error |
| `publishClip_handles_throttle` | Throws classified throttle error |
| `publishClip_handles_conflict` | Throws classified conflict error (duplicate) |
| `getPublishes_returns_publishes_array` | Maps response to typed array |
| `getPublishes_handles_network_error` | Throws classified network error |
| `getPublishStatus_returns_publish_status` | Returns typed publish status |

### 4.2 Hook Tests
**File**: `apps/web/src/features/social-publish/hooks.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `usePublishClip_returns_pending_during_mutation` | isPending=true during call |
| `usePublishClip_returns_data_on_success` | Returns publish data |
| `usePublishClip_returns_error_on_failure` | Error set with classified message |
| `usePublishes_returns_loading_initially` | loading=true, publishes=[] |
| `usePublishes_returns_publishes_on_success` | loading=false, publishes populated |
| `usePublishes_returns_error_on_failure` | loading=false, error set |
| `usePublishes_invalidates_queries_on_success` | Query cache invalidated after publish |

### 4.3 PublishClipDialog Component Tests
**File**: `apps/web/src/features/social-publish/components/PublishClipDialog.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `renders_title_and_description_form` | Form fields present |
| `publish_button_disabled_during_upload` | Button disabled while isPending |
| `publish_button_calls_onPublish_on_confirm` | Callback invoked after confirm |
| `shows_success_state_on_publish_success` | Success message + video link |
| `shows_error_state_on_publish_failure` | Error message + retry option |
| `keyboard_navigation_works` | Tab to buttons, Enter/Space activates |
| `focus_indicators_visible` | 3px outline on focus |
| `aria_labels_on_buttons` | Publish/Cancel/Retry have aria-label |

### 4.4 PublishCard Component Tests
**File**: `apps/web/src/features/social-publish/components/PublishCard.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `renders_pending_status_badge` | Status badge: "Pending" |
| `renders_uploading_status_badge` | Status badge: "Uploading" |
| `renders_published_status_badge` | Status badge: "Published" + video link |
| `renders_failed_status_badge` | Status badge: "Failed" + error message |
| `status_badge_uses_text_not_color_only` | Badge text present |
| `video_link_opens_youtube_watch_url` | Link to youtu.be/external_video_id |

### 4.5 PublishList Component Tests
**File**: `apps/web/src/features/social-publish/components/PublishList.test.tsx`

| Test Case | Description |
|-----------|-------------|
| `renders_loading_skeletons` | 3 skeleton cards when loading |
| `renders_empty_state` | "No publishes yet" + CTA when empty |
| `renders_list_of_cards` | Maps publishes to PublishCard |
| `passes_correct_callbacks_to_cards` | Callbacks propagated |

---

## 5. E2E Tests (Playwright)

### 5.1 Test Configuration
**File**: `apps/web/tests/e2e/social-publish.spec.ts`

**Viewports**: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)

**Test User**: Pre-authenticated via test setup, with an approved clip and connected YouTube connection.

### 5.2 Test Scenarios

| Test Case | Description | Viewports |
|-----------|-------------|-----------|
| `YouTube Publishing - Publish Approved Clip` | Open publish dialog on approved clip → submit → verify publish appears in list | All 3 |
| `YouTube Publishing - Publish Status Reflection` | Publish → verify status transitions pending → published → video link shown | All 3 |
| `YouTube Publishing - Failed Publish` | Mock publisher failure → verify failed status + error message | All 3 |
| `YouTube Publishing - Duplicate Publish Blocked` | Publish same clip twice → verify second blocked | All 3 |
| `YouTube Publishing - No YouTube Connection` | Without connected YouTube → publish button not shown | All 3 |
| `YouTube Publishing - Non-Approved Clip` | Pending/rejected clip → publish button not shown | All 3 |
| `YouTube Publishing - Keyboard Navigation` | Tab through publish dialog and list | All 3 |
| `YouTube Publishing - Focus Indicators` | Verify 3px outline on all focused elements | All 3 |
| `YouTube Publishing - ARIA Labels` | Verify buttons have aria-label | All 3 |
| `YouTube Publishing - Live Region Announcements` | Verify status changes announced | All 3 |
| `YouTube Publishing - Console Cleanliness` | No unexpected console errors | All 3 |
| `YouTube Publishing - Network Cleanliness` | No 4xx/5xx except expected | All 3 |
| `YouTube Publishing - Responsive Layout` | Single column < 1024px, side-by-side ≥ 1024px | All 3 |
| `YouTube Publishing - Touch Targets` | All interactive elements ≥ 44px | All 3 |

### 5.3 YouTube Upload Mocking Strategy

Since real YouTube API isn't available in CI:

1. **Use `FakeYouTubePublisher`** bound in the testing environment — deterministic, no network calls.
2. **Backend publish job runs inline** (QUEUE_CONNECTION=sync) so publish status updates immediately.
3. **Frontend polls publish status** via `GET /api/v1/social/publishes/{publish}`.
4. **E2E verifies UI state transitions** based on publish status.
5. **Backend publisher tested separately** in unit/feature tests with FakeYouTubePublisher.

---

## 6. Governance & Quality Gates

### 6.1 Backend Lint (Pint)
```bash
vendor/bin/pint --dirty --format agent
```
- Must pass with 0 errors

### 6.2 Frontend Lint (ESLint)
```bash
npm run lint
```
- Must pass with 0 errors

### 6.3 TypeScript Build
```bash
npm run build
```
- Must compile without errors

### 6.4 Governance Tests
```bash
php artisan test --filter=Governance
```
- All governance tests must pass

### 6.5 Full Test Suite
```bash
# Backend
php artisan test --compact

# Frontend Unit
npm run test

# Frontend E2E
npm run test:e2e
```
- All tests must pass

---

## 7. Manual Verification Checklist

Perform after automated tests pass:

| Check | Verification Method |
|-------|---------------------|
| Publish approved clip to YouTube (fake) → appears in publish list | Browser: click Publish on approved clip → submit → verify list |
| Publish status reflects published with video link | Check status endpoint / list shows published badge + YouTube link |
| Failed publish shows error message | Mock publisher failure → verify failed badge + error |
| Duplicate publish blocked | Publish same clip twice → verify 409 / button disabled |
| No YouTube connection → publish not available | Remove YouTube connection → verify publish button hidden |
| Non-approved clip → publish not available | Pending/rejected clip → verify publish button hidden |
| Keyboard navigation works throughout | Tab through entire flow, all actions reachable |
| Focus indicators visible | Tab to each element, verify 3px outline |
| No tokens in browser dev tools / network | DevTools Network tab, inspect all responses |
| No tokens in Laravel logs | `tail -f storage/logs/laravel.log` during operations |
| Rate limiting enforced | Exceed limits, verify 429 responses |
| CSRF protection works | Submit without token, verify 419 |

---

## 8. Test Data Requirements

### 8.1 Fake Publisher Behaviors
- `FakeYouTubePublisher`: Simulates YouTube API upload flow
- Must be deterministic (no randomness)
- Must not make network calls
- Must implement full `SocialPublisher` interface
- Allow configuring success/failure scenarios for tests

### 8.2 Database Seeding
- `SocialPublishFactory` for creating test publishes in various states
- `SocialConnectionFactory` (M9) for connected YouTube connection
- `ClipReviewFactory` (M7) with `approved` status
- `DerivedAssetFactory` with `clip_rendered` type and `completed` render status
- Factories for User, Project (existing)

---

## 9. Evidence Collection

Upon completion, update `specs/119-social-connection-publishing/evidence.md` with:

1. **Test Results Summary**
   - Backend test count/pass/fail
   - Frontend test count/pass/fail
   - E2E test count/pass/fail (per viewport)
   - Governance test count

2. **Screenshots**
   - Publish dialog (3 viewports)
   - Published state (3 viewports)
   - Failed state (3 viewports)
   - Empty state (3 viewports)
   - Focus indicators visible

3. **Console/Network Logs**
   - Clean console output
   - No token leakage in network requests

4. **Manual Verification Sign-off**
   - Each checklist item confirmed

5. **Performance Notes**
   - API response times
   - Frontend render times

---

## 10. Test Execution Order (CI Pipeline)

```yaml
# .github/workflows/ci.yml (conceptual)
jobs:
  backend-tests:
    - php artisan test --compact --filter=Unit
    - php artisan test --compact --filter=Feature
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
    - scripts/merge_gate.py  # All above must pass
```

---

## 11. Risk Areas & Mitigation

| Risk | Mitigation |
|------|------------|
| Token leakage in logs | Unit test `publish_video_does_not_leak_token`, code review of all Log:: calls |
| Token leakage in API | Resource tests verify no token fields, integration test `tokens_never_in_api_response` |
| Cross-user access | Feature tests `*_returns_404_for_other_user` on all endpoints |
| CSRF bypass | Feature test `publish_requires_csrf_token` |
| Rate limit bypass | Feature test `publish_respects_rate_limit` |
| Duplicate publish | Integration test `duplicate_publish_blocked`, controller 409 |
| Publish without connection | Integration test `publish_without_youtube_connection_blocked` → 404 |
| Non-approved clip publish | Integration test `publish_requires_approved_clip_review` → 404 |
| Real YouTube network in CI | FakeYouTubePublisher bound in testing env, no network calls |
| UI accessibility gaps | E2E tests for keyboard, focus, ARIA, live regions |
| Mobile layout broken | E2E tests at 390x844 viewport |

---

## 12. Definition of Done (Testing)

- [ ] All unit tests pass (backend + frontend)
- [ ] All feature tests pass
- [ ] All integration tests pass
- [ ] All E2E tests pass at 3 viewports
- [ ] Lint passes (backend + frontend)
- [ ] TypeScript build passes
- [ ] Governance tests pass
- [ ] Manual verification checklist complete
- [ ] Evidence.md updated with results
- [ ] Independent Tester review approves

---
