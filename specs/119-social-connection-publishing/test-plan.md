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

## 5.4 Auth Registration Flow — Functional E2E Tests

**Purpose**: Verify real user registration works end-to-end in a browser, as requested by the user who wants to "create account, login, identify problems." Covers the `RegisterForm` component and `POST /api/v1/auth/register` endpoint.

**File**: `apps/web/tests/e2e/auth-registration.spec.ts` (new)

**Viewports**: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)

**Test User**: Fresh test email not previously registered. No real email delivery required; registration success determined by authenticated redirect/session.

| Test Case | Description | Viewports |
|-----------|-------------|-----------|
| Auth Registration - Load Register Page | Navigate to `/register`, form loads without console errors | All 3 |
| Auth Registration - Successful Registration | Submit valid name/email/password → authenticated session, redirected to dashboard | All 3 |
| Auth Registration - Validation Errors | Missing/invalid fields → inline validation errors shown, form stays | All 3 |
| Auth Registration - Duplicate Email | Already-registered email → error message, no duplicate account | All 3 |
| Auth Registration - Password Mismatch | Password ≠ confirmation → validation error on confirmation field | All 3 |
| Auth Registration - Console Cleanliness | No unexpected JS console errors during registration flow | All 3 |
| Auth Registration - Network Cleanliness | No unexpected 4xx/5xx; expected 422/429 only | All 3 |
| Auth Registration - Visual Regression | Register form screenshot per viewport | All 3 |

## 5.5 Auth Login Flow — Functional E2E Tests

**Purpose**: Verify real user login works end-to-end in a browser. Directly addresses the user-reported login 500 error. Covers the `LoginForm` component and `POST /api/v1/auth/login` endpoint.

**File**: `apps/web/tests/e2e/auth-login.spec.ts` (new)

**Viewports**: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)

**Test User**: Pre-seeded test user with known password (test fixture).

| Test Case | Description | Viewports |
|-----------|-------------|-----------|
| Auth Login - Load Login Page | Navigate to `/login`, form loads without console errors | All 3 |
| Auth Login - Successful Login | Valid credentials → authenticated session, redirected to dashboard | All 3 |
| Auth Login - Invalid Credentials | Wrong password → unauthorized error, form stays, no leak | All 3 |
| Auth Login - 500 Error Detection | Login endpoint returns 500 → test FAILS with diagnostic (reports the reported defect) | All 3 |
| Auth Login - Empty Fields | Submit empty → inline validation errors | All 3 |
| Auth Login - Throttle | Excessive failed attempts → 429 throttle message | All 3 |
| Auth Login - CSRF Protection | Missing/invalid X-XSRF-TOKEN → 419 handled gracefully | All 3 |
| Auth Login - Console Cleanliness | No unexpected JS console errors during login flow | All 3 |
| Auth Login - Network Cleanliness | No unexpected 4xx/5xx; 500 specifically flagged and fails test | All 3 |
| Auth Login - Visual Regression | Login form screenshot per viewport | All 3 |

## 5.6 Visual QA & Layout Inspection — 3 Required Viewports

**Purpose**: Real-browser visual inspection of all application pages at the required viewports (AGENTS.md §14). Addresses user reports of "unusual left alignment" and "images inconsistent with project."

**File**: `apps/web/tests/e2e/visual-qa.spec.ts` (new)

**Required viewports**: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)

**Pages to inspect**:
- Landing page (`/`)
- Login page (`/login`)
- Register page (`/register`)
- Dashboard / projects page
- Project media page (`/projects/1/media`)
- Publish list page (`/publishes`)
- Clip review page
- Error states (500, 404)

| Test Case | Description | Viewports |
|-----------|-------------|-----------|
| Visual QA - Landing Page | Screenshot + layout inspection at all 3 viewports | All 3 |
| Visual QA - Login Page | Screenshot + alignment check at all 3 viewports | All 3 |
| Visual QA - Register Page | Screenshot + alignment check at all 3 viewports | All 3 |
| Visual QA - Dashboard | Screenshot at all 3 viewports | All 3 |
| Visual QA - Project Media | Screenshot at all 3 viewports | All 3 |
| Visual QA - Publish List | Screenshot at all 3 viewports | All 3 |
| Visual QA - 500 Error Page | Error page renders gracefully, no blank/broken layout | All 3 |
| Visual QA - 404 Page | Not-found page renders gracefully | All 3 |
| Visual QA - Mobile Overflow | No horizontal overflow at 390px width | Mobile |
| Visual QA - Tablet Layout | Intentional tablet layout at 768px width | Tablet |
| Visual QA - Desktop Layout | Intentional desktop layout at 1440px width | Desktop |

## 5.7 Image Consistency & Layout Alignment

**Purpose**: Verify images are consistent with project design and layout alignment is intentional (not unusual left alignment). Addresses user reports of inconsistent images and unusual left alignment.

**File**: `apps/web/tests/e2e/visual-qa.spec.ts` (same file as 5.6)

| Test Case | Description | Viewports |
|-----------|-------------|-----------|
| Image Absence - No Meaningful Images on Auth Pages | No picsum.photos URLs, no `auth-atmosphere-image`, `auth-atmosphere-gallery`, `auth-image-credits`, `auth-image-caption`, or `auth-atmosphere-item` elements on `/login` or `/register` | All 3 |
| Image Absence - No Archival Film Imagery | No film editing room, Steenbeck editor, film strip, or videotape recorder imagery on auth pages | All 3 |
| Image Consistency - Hero/Brand Images | Images match project design tokens, none stretched or distorted | All 3 |
| Image Consistency - Clip Thumbnails | Rendered clip thumbnails display correctly, consistent aspect ratio | All 3 |
| Layout Alignment - Container Centering | Page content centered per design tokens, not flush-left | All 3 |
| Layout Alignment - Form Alignment | Auth forms centered, consistent side margins | All 3 |
| Layout Alignment - Publish Dialog | Dialog centered horizontally, not left-aligned | All 3 |
| Layout Alignment - Publish List Cards | Cards aligned in grid, consistent spacing/gutters | All 3 |
| Layout Alignment - Navigation | Nav items aligned per design system | All 3 |

## 5.8 Console, Network & 500 Error Monitoring

**Purpose**: Detect console errors, network failures, and 500 errors during real navigation. The user reported a login 500 error; this section gates on detecting and reporting it.

**File**: Integrated into auth-registration.spec.ts, auth-login.spec.ts, visual-qa.spec.ts

| Test Case | Description | Viewports |
|-----------|-------------|-----------|
| Console Errors - No Unexpected JS Errors | `page.on('console', error)` captures no unexpected errors | All 3 |
| Network Failures - No Failed Requests | No `net::ERR_FAILED`, `ERR_CONNECTION_REFUSED` | All 3 |
| 500 Error Detection - Login | Login endpoint never returns 500; if 500 occurs, test fails with URL/body diagnostic | All 3 |
| 500 Error Detection - Registration | Register endpoint never returns 500 | All 3 |
| 500 Error Detection - Page Navigation | No page load/navigation triggers a 500 response | All 3 |
| API Response Shapes | Auth API responses match expected structure (`user`, `errors`, `message`) | All 3 |
| Uncaught Exceptions | `page.on('pageerror')` captures no uncaught exceptions | All 3 |
| Failed Resources | No failed image/script/CSS resource loads | All 3 |

---

## 5.9 Design Review — design-taste-frontend Art Direction & web-design-guidelines Compliance

**Purpose**: Apply the design-taste-frontend skill art direction and the web-design-guidelines compliance review to all auth + publishing UI. Directly addresses user-reported defects: "imagens não condizentes com o projeto" (images inconsistent with project), "alinhamento incomum a esquerda" (unusual left alignment), "falhas grave de design" (serious design failures). Requires a modern/sophisticated design per design-taste-frontend criteria. No frontend files have been changed yet for design fixes — Builder must implement design fixes, then this section is re-validated.

**File**: `apps/web/tests/e2e/design-review.spec.ts` (new)

**Required viewports**: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)

**Design baseline** (spec §10.1): Switzer font, mono-accent palette, square corners, intentional spacing, centered layouts, clear visual hierarchy, coherent (not auto-generated) appearance.

| Test Case | Description | Viewports |
|-----------|-------------|-----------|
| Design Review - Image Absence on Auth Pages | No picsum.photos URLs or archival film imagery on `/login` or `/register`; no `auth-atmosphere-image`, `auth-atmosphere-gallery`, `auth-image-credits`, `auth-image-caption`, `auth-atmosphere-item` elements | All 3 |
| Design Review - Art Direction Baseline | Switzer font, mono-accent palette, square corners applied across login, register, publish dialog, and publish list UI | All 3 |
| Design Review - Visual Hierarchy | Primary action obvious; status badges use color + text; content density appropriate; spacing consistent | All 3 |
| Design Review - Intentional Centering | Page content and forms centered per design tokens; no unusual flush-left alignment; auth forms centered with consistent side margins; publish dialog centered horizontally | All 3 |
| Design Review - Image Consistency | Hero/brand images and clip thumbnails match project design tokens; none stretched, distorted, or mismatched | All 3 |
| Design Review - Layout Spacing | Consistent margins, gutters, and alignment across mobile/tablet/desktop | All 3 |
| Design Review - Interactive States | Hover/active/focus/disabled states clear and visually recognizable | All 3 |
| Design Review - Typography | Readable type scale; consistent heading/body hierarchy; contrast ≥ 4.5:1 text, ≥ 3:1 UI components | All 3 |
| Design Review - Coherence | Interface looks intentionally designed, not automatically generated (AGENTS.md §15 Q11) | All 3 |
| Design Review - web-design-guidelines Compliance | Accessibility fundamentals respected; keyboard navigation; focus indicators; responsive layouts | All 3 |
| Design Review - Error States | 500/404 pages render gracefully with understandable messaging, never blank or broken | All 3 |
| Design Review - Console/Network Cleanliness | No unexpected console errors; no failed resources; no 500 on auth endpoints | All 3 |

#### 5.9.1 Design-Taste-Frontend Compliance Checklist

Applied to `/login` and `/register` at all 3 viewports (390x844, 768x1024, 1440x900):

| Checklist Item | Requirement | Pass/Fail |
|----------------|-------------|-----------|
| No meaningless images | All picsum.photos archival images removed from AuthEntry and BootstrapErrorView | |
| Clean typography-based design | Wordmark, headline, supporting copy, form fields, primary button, link buttons only; no decorative figures/galleries/captions | |
| Switzer font applied | Font loaded from fontshare; heading/body use `--font-sans` / `--font-heading` | |
| Mono-accent palette | `--bg`, `--surface`, `--ink`, `--muted`, `--accent` (`#2456d6`), `--accent-ink` (`#ffffff`) used consistently | |
| Square corners | `--radius: 0` everywhere on auth UI; no rounded buttons, inputs, or containers | |
| Centered layout | Auth column centered (`align-items: center`, `margin: 0`); no flush-left content | |
| Consistent side margins | `--page-margin: 24px`; column `max-width: 380px` on desktop, full-width minus margins on mobile | |
| Visual hierarchy | Headline > supporting copy > form fields > primary button; primary action obvious | |
| Focus indicators | 3px solid outline, 2px offset on all interactive elements | |
| Touch targets | All buttons/inputs ≥ 44px min-height | |
| Coherence | Interface looks intentionally designed, not automatically generated (AGENTS.md §15 Q11) | |
| web-design-guidelines compliance | Accessibility fundamentals, keyboard navigation, focus indicators, responsive layouts | |
| Console cleanliness | No unexpected JS console errors on auth pages | |
| Network cleanliness | No failed resource loads; no 500 on `/api/v1/auth/login` | |
| Login returns 200 | Valid credentials → 200, never 500; invalid credentials → 401 with generic message | |

---

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

# Frontend E2E (Playwright at 3 viewports)
npm run test:e2e
# Includes: social-publish.spec.ts, auth-registration.spec.ts,
#           auth-login.spec.ts, visual-qa.spec.ts, design-review.spec.ts
```
- All tests must pass
- E2E must pass at all 3 viewports (390x844, 768x1024, 1440x900)

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

### 7.1 Auth Registration & Login Manual Verification

| Check | Verification Method |
|-------|---------------------|
| Create account works in real browser | Navigate `/register`, submit valid credentials, verify authenticated redirect |
| Login works in real browser | Navigate `/login`, submit valid credentials, verify dashboard access |
| Login 500 error investigated | Browser devtools Network tab: confirm `/api/v1/auth/login` never returns 500 |
| Invalid login handled | Wrong password → error message, no credential leakage |
| Registration validation | Missing fields → inline errors, form stays |
| No tokens exposed in browser | DevTools: no access_token/refresh_token in responses or localStorage |

### 7.2 Visual QA Manual Verification (3 Viewports)

| Check | Verification Method |
|-------|---------------------|
| Layout alignment intentional | Inspect pages at 390x844, 768x1024, 1440x900; content centered, not unusual left alignment |
| Image consistency | Hero/brand images and clip thumbnails consistent with project design tokens, none distorted |
| Console cleanliness | DevTools Console: no unexpected errors on any page |
| Network cleanliness | DevTools Network: no failed requests, no 500 responses |
| Visual defects | Screenshots reviewed for spacing, alignment, typography, overflow at all viewports |

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

### 8.3 Auth Test Data Requirements
- Pre-seeded test user with known password (for login E2E)
- Fresh, never-registered email (for registration E2E)
- Duplicate email fixture (already registered, for registration validation E2E)
- Rate-limit test: credentials to exceed login throttle (429)
- No production credentials in test fixtures

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
   - **Auth: Login form (3 viewports)**
   - **Auth: Register form (3 viewports)**
   - **Auth: Dashboard after login (3 viewports)**
   - **Visual QA: Landing page (3 viewports)**
   - **Visual QA: Project media page (3 viewports)**
   - **Visual QA: Publish list page (3 viewports)**
   - **Visual QA: 500/404 error pages (3 viewports)**

3. **Console/Network Logs**
   - Clean console output
   - No token leakage in network requests
   - **Auth login/register console logs (no unexpected errors)**
   - **Auth network logs: confirm `/api/v1/auth/login` returns 200, never 500**
   - **500 error diagnostics if any 500 occurs (URL, status, response body)**

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

## 12. Security Implications (Auth & Visual QA)

### 12.1 Login & Registration Security

| Concern | Requirement | Test Coverage |
|---------|-------------|---------------|
| Credentials never leaked | Login/register responses never return access_token, refresh_token, or password | Auth login/register E2E; network cleanliness |
| Password not exposed in error messages | Error messages generic; no "email exists / password wrong" specificity that enables enumeration | Auth login/register E2E |
| CSRF protection | State-changing auth endpoints require valid X-XSRF-TOKEN | Auth login/register E2E (419 handling) |
| Rate limiting | Login/register rate-limited to prevent brute force | Auth login/register E2E (throttle) |
| Session handling | Successful auth sets secure, HttpOnly session cookie; not accessible via JS | Auth login/register E2E |
| No tokens in console/logs | Auth errors and responses never logged to browser console or server logs | Console cleanliness E2E |
| 500 error on login does not expose internals | If login 500 occurs, error response is generic; no stack traces in body | Auth login 500 detection E2E |

### 12.2 Token Protection (carried from M9/M10)

- OAuth `access_token`/`refresh_token` remain encrypted at rest (M9 `encrypted` cast), never exposed to frontend.
- Publishing uses the valid access token obtained server-side via `SocialConnectionManager::getValidAccessToken()`; token is never returned to the frontend.
- Tokens never logged (explicitly excluded from log context).
- No tokens in API responses, browser dev tools, or network.
- Frontend applications must not receive social provider refresh tokens (AGENTS.md §23).

### 12.3 CI Safety

- No real social platform credentials in CI; fakes used for OAuth/publish.
- Auth E2E uses pre-seeded test users; no production credentials.
- No network calls to YouTube/social APIs during visual QA.

## 13. UX Considerations (Auth & Visual QA)

### 13.1 Auth UX

- Login and register forms have clear primary actions ("Sign In", "Create Account").
- Validation errors are inline, understandable, and actionable (field-level messages).
- Loading states during submit (spinner + disabled button) prevent double-submission.
- Error states distinguish network errors from validation errors from unauthorized errors.
- Focus indicators visible (3px outline) for keyboard-only users.
- Forms are keyboard-navigable (Tab order logical, Enter submits).
- ARIA labels on inputs and buttons; `aria-invalid` and `aria-describedby` on fields.
- Reduced-motion respected.

### 13.2 Visual QA UX (addressing user-reported defects)

- **Left alignment**: Page content and forms centered per design tokens; no unusual flush-left alignment. Verified at 390x844, 768x1024, 1440x900.
- **Image consistency**: Hero/brand images and clip thumbnails consistent with project design tokens; none stretched, distorted, or mismatched.
- **Layout spacing**: Consistent margins, gutters, and alignment across mobile/tablet/desktop.
- **Error states**: 500/404 pages render gracefully with understandable messaging, never blank/broken.
- **Touch targets**: All interactive elements ≥ 44px.
- **Visual hierarchy**: Primary actions obvious; status badges use color + text.
- Interface looks coherent and intentionally designed, not automatically generated (AGENTS.md §15).

## 14. Dependencies (Auth & Visual QA)

- Laravel Sanctum authentication (existing).
- Auth API endpoints: `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me` (existing).
- CSRF cookie endpoint `/sanctum/csrf-cookie` (existing).
- Auth UI components: `LoginForm`, `RegisterForm`, `AuthProvider` (existing).
- Playwright test infrastructure (existing, used by social-publish.spec.ts).
- Test fixture users pre-seeded in the test database.
- Design tokens / `design-taste-frontend` art direction for visual baseline.

## 15. Definition of Done (Testing)

- [ ] All unit tests pass (backend + frontend)
- [ ] All feature tests pass
- [ ] All integration tests pass
- [ ] All E2E tests pass at 3 viewports (390x844, 768x1024, 1440x900)
  - [ ] YouTube publishing E2E (social-publish.spec.ts)
  - [ ] Auth registration E2E (auth-registration.spec.ts)
  - [ ] Auth login E2E (auth-login.spec.ts)
  - [ ] Visual QA E2E (visual-qa.spec.ts)
- [ ] Login 500 error investigated and resolved (no 500 on `/api/v1/auth/login`)
- [ ] Lint passes (backend + frontend)
- [ ] TypeScript build passes
- [ ] Governance tests pass
- [ ] Manual verification checklist complete (incl. auth + visual QA + design review)
- [ ] Evidence.md updated with results (incl. screenshots at 3 viewports)
- [ ] Independent Tester review approves
- [ ] Design review (design-taste-frontend art direction) passes at 3 viewports — Switzer font, mono-accent palette, square corners, centered layout, image consistency, coherence (not auto-generated)
- [ ] web-design-guidelines compliance review passes — accessibility fundamentals, keyboard navigation, focus indicators, responsive layouts
- [ ] No unusual left alignment — all page content and forms centered per design tokens
- [ ] Images consistent with project design tokens — none stretched, distorted, or mismatched
- [ ] Login endpoint returns 200 for valid credentials; never 500 (login 500 error resolved)

---
