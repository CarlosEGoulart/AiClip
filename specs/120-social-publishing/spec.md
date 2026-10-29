# M11 — Instagram Publishing Specification

## Overview

This specification defines the Instagram Publishing integration (Issue #135 on GitHub, tracked as Issue #120 in the SDD specs directory). It builds directly on M9's Social Connection Framework (Issue #118) and M10 YouTube Publishing (Issue #119).

M11 reuses the **exact** M10 `SocialPublisher` interface, `PublishResult` value object, `SocialPublish` model, and API routes — adding an `InstagramPublisher` implementation bound as `SocialPublisher::class . ':instagram'`, a `PublishToInstagram` queue job following the `PublishToYouTube` pattern, extending `POST /api/v1/social/publish` to support `platform="instagram"`, and extending the `PublishClipDialog` frontend component to support Instagram publishing.

This issue also fixes the **HTTP 500 on `GET /sanctum/csrf-cookie`** that blocks all authenticated API flows, and modernizes the **publishing-related frontend components** (PublishClipDialog, PublishCard, PublishList, SocialConnections) per the user's design dissatisfaction.

---

## 1. Auth Infrastructure Fix — `laravel_session` cookie encryption

### 1.1 Problem

`laravel_session` is encrypted by Laravel's `EncryptCookies` middleware but read manually via `Cookie::fromString()` (which does not decrypt) by the `SpaRequests` test helper and the frontend. The session cannot be restored, causing CSRF mismatch (419) on every authenticated state-changing request (POST/PATCH/DELETE).

### 1.2 Root Cause Analysis

Root causes #1–#3 (sessions table, `APP_KEY`, `SANCTUM_STATEFUL_DOMAINS`) were addressed in M10 and are present and correct. The duplicate `EncryptCookies` registration (double-encryption) was removed in a prior round. `apps/api/bootstrap/app.php` currently registers `EncryptCookies` exactly once:

```php
$middleware->statefulApi();
$middleware->encryptCookies(except: ['XSRF-TOKEN']);
$middleware->validateCsrfTokens(except: ['api/v1/projects/*/media/upload']);
```

`XSRF-TOKEN` is correctly excepted from encryption, but `laravel_session` is **not**. Laravel encrypts `laravel_session` on the response; when the session cookie is read back manually (e.g. `Cookie::fromString()` in tests, or by frontend cookie handling that does not run Laravel's decrypter), the value cannot be decrypted and the session is lost. The result is a CSRF token mismatch (419) on every authenticated state-changing request.

### 1.3 Fix

Add `laravel_session` to the `except` list so it is not encrypted and can be read/restored without decryption:

```php
// Before:
$middleware->encryptCookies(except: ['XSRF-TOKEN']);

// After:
$middleware->encryptCookies(except: ['XSRF-TOKEN', 'laravel_session']);
```

### 1.4 Verification

- `GET /sanctum/csrf-cookie` → 204 No Content
- `POST /api/v1/auth/register` → 201 Created
- `POST /api/v1/auth/login` → 200 OK
- Session persists across requests (authenticated `GET /api/v1/auth/me` → 200)
- No 419 CSRF mismatches on authenticated POST/PATCH/DELETE
- No console errors, no 500 responses

---

## 2. Publishable Content

### 2.1 Eligibility

Only **approved rendered clips** are publishable:

- A `DerivedAsset` with `type === 'clip_rendered'` and `render_status === 'completed'`.
- The owning `ClipReview` must have `status === 'approved'`.
- The clip must belong to the authenticated user's project (ownership enforced via 404 for non-owners).

### 2.2 Publish Metadata

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | uuid/int | yes | PK of `social_publishes` row |
| `user_id` | uuid/int | yes | FK to users |
| `social_connection_id` | uuid/int | yes | FK to social_connections |
| `clip_review_id` | uuid/int | yes | FK to clip_reviews |
| `platform` | string | yes | `'youtube'` or `'instagram'` |
| `status` | string | yes | `pending`, `uploading`, `published`, `failed` |
| `external_video_id` | string | nullable | Platform-assigned post/video ID |
| `video_url` | string | nullable | Public URL to the published post |
| `title` | string | yes | Publish title |
| `description` | text | nullable | Publish description/caption |
| `error_message` | text | nullable | Failure reason (no secrets) |
| `created_at` | timestamp | yes | |
| `updated_at` | timestamp | yes | |

### 2.3 Out of Scope

- Publishing unapproved clips (403/422).
- Publishing to TikTok (M12).
- One-click multi-platform publishing (M14).
- Real Instagram credentials in CI or production.
- Instagram Stories, Reels-specific features beyond the Graph API publish path.
- Scheduling posts for future dates.

---

## 3. Provider-Neutral Social Publisher Contract (M10, reused)

### 3.1 SocialPublisher Interface (M10)

```php
namespace App\Contracts;

interface SocialPublisher
{
    public function getPlatform(): string;
    public function getPlatformLabel(): string;
    public function publishVideo(array $input): PublishResult;
    public function getPublishStatus(string $externalVideoId): PublishStatus;
    public function hasPublishPermission(string $accessToken): bool;
}
```

### 3.2 PublishResult Value Object (M10)

```php
namespace App\Contracts;

class PublishResult
{
    public function __construct(
        public readonly string $externalVideoId,
        public readonly string $videoUrl,
        public readonly string $status,
        public readonly ?string $errorMessage = null,
    ) {}
}
```

### 3.3 PublishStatus Value Object (M10)

```php
namespace App\Contracts;

class PublishStatus
{
    public function __construct(
        public readonly string $externalVideoId,
        public readonly string $status,
        public readonly ?string $videoUrl = null,
        public readonly ?string $errorMessage = null,
    ) {}
}
```

### 3.4 Provider Registration (M10 + M11)

In `AppServiceProvider`:

```php
$this->app->bind(SocialPublisher::class . ':youtube', YouTubePublisher::class);
$this->app->bind(SocialPublisher::class . ':instagram', InstagramPublisher::class);
```

Testing override:

```php
if ($this->app->environment('testing')) {
    $this->app->bind(SocialPublisher::class . ':youtube', FakeYouTubePublisher::class);
    $this->app->bind(SocialPublisher::class . ':instagram', FakeInstagramPublisher::class);
}
```

Resolution:

```php
$publisher = app(SocialPublisher::class . ':' . $platform);
```

---

## 4. InstagramPublisher Implementation

### 4.1 Class

`app/Services/InstagramPublisher.php` (namespace `App\Services`).

Implements `App\Contracts\SocialPublisher`. Placed in `App\Services` alongside `YouTubePublisher` for consistency.

### 4.2 Behavior

- `getPlatform()` returns `'instagram'`.
- `getPlatformLabel()` returns `'Instagram'`.
- `publishVideo(array $input)`:
  1. Resolves the user's connected Instagram `SocialConnection` (status `connected`) from `$input['connection']`.
  2. Obtains a valid access token via `SocialConnectionManager::getValidAccessToken()`.
  3. Calls Instagram Graph API `POST /{ig-user-id}/media` (container) then `POST /{ig-user-id}/media_publish` (publish), depending on API version.
  4. On success returns `PublishResult(externalVideoId, videoUrl, status: 'published')`.
  5. On failure returns `PublishResult(externalVideoId: '', videoUrl: '', status: 'failed', errorMessage: <sanitized>)`.
  6. Never logs tokens.
- `hasPublishPermission(string $accessToken)`: verifies token is valid and required scopes present (`instagram_content_publish`, `instagram_basic`).
- `getPublishStatus(string $externalVideoId)`: checks status of a previously published post.

### 4.3 Security

- Tokens encrypted at rest (Laravel `encrypted` cast on `SocialConnection`).
- Tokens never returned in API responses, never logged.
- Provider errors mapped to sanitized `errorMessage` (no token leakage).
- CSRF on state-changing endpoints (Sanctum).
- Rate limiting 20/min on publish endpoints.
- Ownership: 404 for non-owner publishes/connections (hides existence).

### 4.4 Testing

- `FakeInstagramPublisher` (container-bound in testing env) implements `SocialPublisher`, returns deterministic results, makes no network calls.
- Config in `config/social.php` for `instagram` provider (client_id, client_secret, redirect_uri, scopes: `instagram_content_publish`, `instagram_basic`) — already present from M9/M10.

---

## 5. PublishToInstagram Job

### 5.1 Class

`app/Jobs/PublishToInstagram.php` (namespace `App\Jobs`).

### 5.2 Pattern

Follows the `PublishToYouTube` topology exactly:

- Receives `int $publishId`.
- Re-validates ownership and clip approval status inside the job (defense in depth).
- Resolves `SocialPublisher::class . ':instagram'` via container.
- Calls `publishVideo()`.
- On success: mark `social_publishes.status = 'published'`, set `external_video_id`/`video_url`, clear `error_message`.
- On failure: mark `status = 'failed'`, set sanitized `error_message`, retryable with exponential backoff (`tries = 3`, `backoff = 5`).
- Idempotency: skip if status is not `pending`; unique job ID `social-publish:{publishId}`.
- Inference/ML never inside HTTP request processes; publishing is async via queue.

### 5.3 Dispatch

`SocialPublishController@store` enqueues `PublishToInstagram` (or `PublishToYouTube`) based on `platform` field and returns 202.

---

## 6. SocialPublish Model (M10, reused)

Already exists at `app/Models/SocialPublish.php`. Reused as-is:

- `social_publishes` table with columns per §2.2.
- `SocialPublish` model with `SocialPublishStatus` cast, fillable, relationships to `User`, `SocialConnection`, `ClipReview`.
- `SocialPublishResource` / `SocialPublishCollection` with strict whitelist (no storage internals, no tokens).

No schema change required for M11 — the `platform` column already accepts `'youtube'` or `'instagram'`.

---

## 7. API Endpoints

All endpoints require `auth:sanctum`. CSRF on state-changing routes. Rate limit 20/min on publish.

### 7.1 POST /api/v1/social/publish

Already exists from M10. Extended for M11:

**Request:**
```json
{
  "clip_review_id": "<integer>",
  "platform": "instagram",
  "title": "Optional title",
  "description": "Optional caption"
}
```

**Validation (PublishClipRequest):** `platform` must be `in:youtube,instagram` (was `in:youtube` in M10).

**Response (202):**
```json
{
  "data": {
    "publish_id": "<id>",
    "status": "pending",
    "platform": "instagram"
  }
}
```

**Errors:** 401, 404 (clip not found/not approved/not owned), 404 (no connected platform account — hides existence), 409 (duplicate publish), 422 (validation), 429 (rate limit), 419 (CSRF).

### 7.2 GET /api/v1/social/publishes

Paginated list of the authenticated user's publishes. Whitelist resource.

### 7.3 GET /api/v1/social/publishes/{publish}

Single publish show. 404 for non-owner.

---

## 8. Frontend — Publishing Component Modernization

### 8.1 Scope Decision (AGENTS.md §25)

**In scope for Issue #135 (cohesive with Instagram Publishing):**

- `PublishClipDialog` — platform selector (YouTube/Instagram), modern design, only show platforms with connected connections, `platform` field sent to API.
- `PublishCard` — Instagram icon/label, status badge (pending/uploading/published/failed), modern card design.
- `PublishList` — paginated list with loading/empty states, filter by platform optional.
- `SocialConnections` — connection management needed for Instagram publishing (connect/disconnect/retry), modern design.

**Out of scope for Issue #135 (future issue — complete redesign of ALL screens):**

- Auth forms (`LoginForm`, `RegisterForm`) — auth redesign is a separate concern.
- Project management (`ProjectList`, `ProjectCard`, `CreateProjectForm`).
- Clip Review (`ReviewPage`, `ReviewActions`, `FeedbackForm`, `VideoPlayer`, `ReviewList`).
- Image Studio (`ImageStudio`, `GenerateImageForm`, `ImageGallery`).
- Media (`ProjectMediaSection`, `MediaList`, `MediaUploadForm`, `MediaListItem`).

Rationale: Issue #135 is "Instagram Publishing (M11)". A complete redesign of every frontend screen is not cohesive with publishing work and would expand scope beyond one issue. The publishing-related components are the ones users touch when publishing to Instagram and are therefore in scope. All other screens remain as-is and can be redesigned in a dedicated future issue.

### 8.2 Design Process

All in-scope frontend components MUST be redesigned using the `design-taste-frontend` skill process:

1. **Art direction** — select a researched direction (idea, composition, type, palette, photographs, section blueprints) from the skill library.
2. **No-slop house style** — enforce strict house style: no generic/templated/AI-made appearance, square corners, Switzer type, mono-accent palette, flat buttons, visible focus rings, 44px touch targets, color contrast ≥ 4.5:1.
3. **Implementation** — rebuild components with the selected design direction.
4. **Visual QA** — run `playwright-visual-qa` and `web-design-guidelines` review on screenshots at 390x844, 768x1024, 1440x900.
5. **Look-and-fix loop** — iterate on screenshots until the interface looks intentionally designed, premium, and coherent.

### 8.3 Existing Components (M10, reused as base)

- `PublishClipDialog` — extend to support `platform="instagram"` + modern design.
- `PublishCard` — per-publish status card + modern design.
- `PublishList` — paginated list with loading/empty states + modern design.
- `SocialConnections` — connection management page + modern design.

### 8.4 States

| State | Visual Treatment |
|-------|------------------|
| Loading | Skeleton cards / spinner |
| Empty | "No publishes yet" + CTA to connect |
| Pending | Yellow/badge "Processing..." |
| Published | Green badge "Published" + link to post |
| Failed | Red badge "Failed" + retry option |

### 8.5 Accessibility

- Keyboard navigation, visible focus indicators (3px outline).
- ARIA labels on icon buttons.
- Color + text for status badges.
- 44px touch targets.
- Color contrast ≥ 4.5:1.

---

## 9. Security Considerations

- Tokens encrypted at rest (`encrypted` cast).
- Tokens never in browser, logs, or API responses.
- CSRF protection on all state-changing endpoints (Sanctum).
- Rate limiting: 20/min on publish endpoints.
- Ownership enforced: 404 for non-owner clips, connections, publishes.
- No secrets in `error_message`.
- Instagram `instagram_content_publish`, `instagram_basic` scopes only.
- `EncryptCookies` middleware registered exactly once (no double-encryption).
- XSRF-TOKEN excepted from cookie encryption.

---

## 10. UX Considerations

- Publish dialog title reflects selected platform ("Publish to Instagram" / "Publish to YouTube").
- Platform selector shows only connected platforms; disabled platforms show "(not connected)" tooltip.
- Publish button disabled during submission, shows "Publishing…" state.
- Success state: toast + dialog close + publish appears in PublishList.
- Error state: clear message, retry option for failed publishes.
- Keyboard navigation throughout dialog; Escape closes; Tab cycles focusable elements.
- Focus indicators visible (3px outline).
- Responsive: dialog usable at 390px width; touch targets ≥ 44px.
- Empty state guides user to connect a social account first.

---

## 11. CI Requirements

- FakeInstagramPublisher in testing env (no network, deterministic).
- Backend: Pest unit + feature tests.
- Frontend: Vitest component tests.
- E2E: Playwright at 390x844, 768x1024, 1440x900.
- Auth infrastructure verification: csrf-cookie 204, register 201, login 200, session persistence.
- All four CI workflows green (backend, frontend, e2e, governance).
- Lint (Pint, ESLint), TypeScript build, `composer audit`, `npm audit` clean.
- Visual QA: Playwright visual review at 3 viewports; `web-design-guidelines` compliance.

---

## 12. Dependencies

- M9 Social Connection Framework (#118): `SocialConnection` model, `SocialConnectionProvider` contract, `SocialConnectionManager`, `FakeInstagramProvider`, encrypted token storage, `config/social.php`.
- M10 YouTube Publishing (#119): `SocialPublisher` contract, `PublishToYouTube` job pattern, `SocialPublish` model, API endpoints, `PublishClipDialog`/`PublishCard`/`PublishList` frontend components, `PublishClipRequest`, `SocialPublishController`.
- Auth infrastructure (sessions table, APP_KEY, SANCTUM_STATEFUL_DOMAINS): verified functional (prerequisite, §1).
- Auth infrastructure fix (double-`EncryptCookies` removal): `apps/api/bootstrap/app.php` (this issue).
- Laravel Sanctum authentication (existing).
- Queue system (existing).
- PostgreSQL (existing).
- `design-taste-frontend` skill: frontend redesign art direction and house style.
- `playwright-visual-qa` skill: visual QA on running application.
- `web-design-guidelines` skill: UI compliance review.

---

## 13. Acceptance Criteria Mapping

| AC | Coverage |
|----|----------|
| Auth: `GET /sanctum/csrf-cookie` → 204, register → 201, login → 200, session persists | §1 |
| Auth fix: `laravel_session` added to `encryptCookies` except list in `bootstrap/app.php` | §1.3 |
| `SocialPublisher::class . ':instagram'` binding → `InstagramPublisher` | §3.4, §4 |
| `InstagramPublisher` implements `SocialPublisher` (M10 interface) | §4 |
| `PublishToInstagram` job follows `PublishToYouTube` pattern | §5 |
| `SocialPublish` model reused (M10, no schema change) | §6 |
| POST /api/v1/social/publish supports platform="instagram" | §7.1 |
| GET /api/v1/social/publishes and GET /api/v1/social/publishes/{publish} | §7.2, §7.3 |
| `PublishClipRequest` accepts `platform in:youtube,instagram` | §7.1 |
| `PublishClipDialog` supports platform="instagram" + modern design | §8 |
| `PublishCard` modernized with Instagram icon/label + status badges | §8 |
| `PublishList` modernized with loading/empty states | §8 |
| `SocialConnections` modernized (connect/disconnect/retry) | §8 |
| Tokens encrypted at rest, never leaked | §9 |
| CSRF, rate limiting 20/min, ownership 404s | §9 |
| CI: FakeInstagramPublisher, no network | §11 |
| E2E Playwright at 3 viewports | §11 |
| Independent Tester approval | Orchestrator workflow |

---

## 14. Out of Scope

- TikTok publishing (M12).
- Multi-platform one-click publishing (M14).
- Real Instagram API credentials in CI.
- Post scheduling.
- Instagram Stories/Reels-specific features beyond Graph API publish.
- Re-implementing the auth 500 fix root causes #1–#3 (already present in M10; verified here).
- Complete redesign of ALL frontend screens (auth forms, projects, clip review, image studio, media) — future issue.
- Auth form redesign (LoginForm, RegisterForm) — future issue.