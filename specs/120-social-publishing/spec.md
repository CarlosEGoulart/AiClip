# M11 — Instagram Publishing Specification

## Overview

This specification defines the Instagram Publishing integration (Issue #135 on GitHub, tracked as Issue #120 in the SDD specs directory). It builds directly on M9's Social Connection Framework (Issue #118) and M10 YouTube Publishing (Issue #119).

M11 reuses the **exact** M10 `SocialPublisher` interface, `PublishResult` value object, `SocialPublish` model, and API routes — adding an `InstagramPublisher` implementation bound as `SocialPublisher::class . ':instagram'`, a `PublishToInstagram` queue job following the `PublishToYouTube` pattern, extending `POST /api/v1/social/publish` to support `platform="instagram"`, and extending the `PublishClipDialog` frontend component to support Instagram publishing.

## Auth Infrastructure Prerequisite (Register/Login 500 Fix)

M11 publishing requires authenticated sessions (`auth:sanctum`). Before any publish flow can work end-to-end, the auth infrastructure that caused HTTP 500 on `GET /sanctum/csrf-cookie` (and on register/login) must be verified functional. The three root causes were addressed in M10 (spec 119 §10.1.5) and are present in the repository:

1. **Sessions table**: `SESSION_DRIVER=database` with the `sessions` table created by `0001_01_01_000000_create_users_table.php` (migration exists; `2026_10_27_000000_create_sessions_table.php` is an intentional no-op to avoid "Duplicate table").
2. **APP_KEY**: `APP_KEY=base64:8jR9mK2pL5nQ7vW3xYzAbCdEfGhIjKlMnOpQrStUvWx=` set in `apps/api/.env.example` — required for encryption/signing of session cookies and CSRF tokens.
3. **SANCTUM_STATEFUL_DOMAINS**: includes `5173-cs-370822403794-default.cs-us-east1-dogs.cloudshell.dev` — required for stateful Sanctum session cookies on the Cloud Shell dev domain.

**Verification task for this issue** (not re-implementation): confirm `GET /sanctum/csrf-cookie` returns 204, `POST /api/v1/auth/register` returns 201, `POST /api/v1/auth/login` returns 200, and the session persists across requests. This is a prerequisite gate for M11 E2E flows.

## 1. Publishable Content

### 1.1 Eligibility

Only **approved rendered clips** are publishable:

- A `DerivedAsset` with `type === 'clip_rendered'` and `render_status === 'completed'`.
- The owning `ClipReview` must have `status === 'approved'`.
- The clip must belong to the authenticated user's project (ownership enforced via 404 for non-owners).

### 1.2 Publish Metadata

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

### 1.3 Out of Scope

- Publishing unapproved clips (403/422).
- Publishing to TikTok (M12).
- One-click multi-platform publishing (M14).
- Real Instagram credentials in CI or production.
- Instagram Stories, Reels-specific features beyond the Graph API publish path.
- Scheduling posts for future dates.

---

## 2. Provider-Neutral Social Publisher Contract (M10, reused exactly)

### 2.1 SocialPublisher Interface (M10)

```php
namespace App\Contracts;

interface SocialPublisher
{
    /**
     * Unique platform identifier (e.g., 'youtube', 'instagram').
     */
    public function getPlatform(): string;

    /**
     * Human-readable platform label for UI display.
     */
    public function getPlatformLabel(): string;

    /**
     * Publish a video to the platform.
     * Returns publishing result without persisting it.
     *
     * @param  array{local_path: string, title: string, description?: string, privacy_status?: string, connection?: \App\Models\SocialConnection}  $input
     * @return PublishResult
     */
    public function publishVideo(array $input): PublishResult;

    /**
     * Check the status of a previously published video on the platform.
     *
     * @return PublishStatus
     */
    public function getPublishStatus(string $externalVideoId): PublishStatus;

    /**
     * Validate that the connection has publishing permission for this platform.
     */
    public function hasPublishPermission(string $accessToken): bool;
}
```

### 2.2 PublishResult Value Object (M10)

```php
namespace App\Contracts;

class PublishResult
{
    public function __construct(
        public readonly string $externalVideoId,
        public readonly string $videoUrl,
        public readonly string $status, // 'published', 'processing', 'failed'
        public readonly ?string $errorMessage = null,
    ) {}
}
```

### 2.3 PublishStatus Value Object (M10)

```php
namespace App\Contracts;

class PublishStatus
{
    public function __construct(
        public readonly string $externalVideoId,
        public readonly string $status, // 'published', 'processing', 'failed', 'not_found'
        public readonly ?string $videoUrl = null,
        public readonly ?string $errorMessage = null,
    ) {}
}
```

### 2.4 Provider Registration (M10 + M11)

In `AppServiceProvider`:

```php
$this->app->bind(SocialPublisher::class . ':youtube', YouTubePublisher::class);
$this->app->bind(SocialPublisher::class . ':instagram', InstagramPublisher::class);
```

Testing override (M10 pattern, extended for Instagram):

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

## 3. InstagramPublisher Implementation

### 3.1 Class

`app/Services/InstagramPublisher.php` (namespace `App\Services`).

Implements `App\Contracts\SocialPublisher`. Placed in `App\Services` alongside `YouTubePublisher` for consistency.

### 3.2 Behavior

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

### 3.3 Security

- Tokens encrypted at rest (Laravel `encrypted` cast on `SocialConnection`).
- Tokens never returned in API responses, never logged.
- Provider errors mapped to sanitized `errorMessage` (no token leakage).
- CSRF on state-changing endpoints (Sanctum).
- Rate limiting 20/min on publish endpoints.
- Ownership: 404 for non-owner publishes/connections (hides existence).

### 3.4 Testing

- `FakeInstagramPublisher` (container-bound in testing env) implements `SocialPublisher`, returns deterministic results, makes no network calls.
- Config in `config/social.php` for `instagram` provider (client_id, client_secret, redirect_uri, scopes: `instagram_content_publish`, `instagram_basic`) — already present from M9/M10.

---

## 4. PublishToInstagram Job

### 4.1 Class

`app/Jobs/PublishToInstagram.php` (namespace `App\Jobs`).

### 4.2 Pattern

Follows the `PublishToYouTube` topology exactly:

- Receives `int $publishId`.
- Re-validates ownership and clip approval status inside the job (defense in depth).
- Resolves `SocialPublisher::class . ':instagram'` via container.
- Calls `publishVideo()`.
- On success: mark `social_publishes.status = 'published'`, set `external_video_id`/`video_url`, clear `error_message`.
- On failure: mark `status = 'failed'`, set sanitized `error_message`, retryable with exponential backoff (`tries = 3`, `backoff = 5`).
- Idempotency: skip if status is not `pending`; unique job ID `social-publish:{publishId}`.
- Inference/ML never inside HTTP request processes; publishing is async via queue.

### 4.3 Dispatch

`SocialPublishController@store` enqueues `PublishToInstagram` (or `PublishToYouTube`) based on `platform` field and returns 202.

---

## 5. SocialPublish Model (M10, reused)

Already exists at `app/Models/SocialPublish.php`. Reused as-is:

- `social_publishes` table with columns per §1.2.
- `SocialPublish` model with `SocialPublishStatus` cast, fillable, relationships to `User`, `SocialConnection`, `ClipReview`.
- `SocialPublishResource` / `SocialPublishCollection` with strict whitelist (no storage internals, no tokens).

No schema change required for M11 — the `platform` column already accepts `'youtube'` or `'instagram'`.

---

## 6. API Endpoints

All endpoints require `auth:sanctum`. CSRF on state-changing routes. Rate limit 20/min on publish.

### 6.1 POST /api/v1/social/publish

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

### 6.2 GET /api/v1/social/publishes

Paginated list of the authenticated user's publishes. Whitelist resource.

### 6.3 GET /api/v1/social/publishes/{publish}

Single publish show. 404 for non-owner.

---

## 7. Frontend

### 7.1 Existing Components (M10, reused)

- `PublishClipDialog` — extend to support `platform="instagram"`.
- `PublishCard` — per-publish status card.
- `PublishList` — paginated list with loading/empty states.

### 7.2 Changes

- `PublishClipDialog`: platform selector (YouTube/Instagram), only show platforms with connected connections, `platform` field sent to API.
- `PublishCard`: Instagram icon/label, status badge (pending/uploading/published/failed).
- `PublishList`: filter by platform optional.

### 7.3 States

| State | Visual Treatment |
|-------|------------------|
| Loading | Skeleton cards / spinner |
| Empty | "No publishes yet" + CTA to connect |
| Pending | Yellow badge "Processing..." |
| Published | Green badge "Published" + link to post |
| Failed | Red badge "Failed" + retry option |

### 7.4 Accessibility

- Keyboard navigation, visible focus indicators (3px outline).
- ARIA labels on icon buttons.
- Color + text for status badges.
- 44px touch targets.
- Color contrast ≥ 4.5:1.

---

## 8. Security Considerations

- Tokens encrypted at rest (`encrypted` cast).
- Tokens never in browser, logs, or API responses.
- CSRF protection on all state-changing endpoints.
- Rate limiting: 20/min on publish endpoints.
- Ownership enforced: 404 for non-owner clips, connections, publishes.
- No secrets in `error_message`.
- Instagram `instagram_content_publish`, `instagram_basic` scopes only.

---

## 9. CI Requirements

- FakeInstagramPublisher in testing env (no network, deterministic).
- Backend: Pest unit + feature tests.
- Frontend: Vitest component tests.
- E2E: Playwright at 390x844, 768x1024, 1440x900.
- Auth infrastructure verification: csrf-cookie 204, register 201, login 200, session persistence.
- All four CI workflows green (backend, frontend, e2e, governance).
- Lint (Pint, ESLint), TypeScript build, `composer audit`, `npm audit` clean.

---

## 10. Dependencies

- M9 Social Connection Framework (#118): `SocialConnection` model, `SocialConnectionProvider` contract, `SocialConnectionManager`, `FakeInstagramProvider`, encrypted token storage, `config/social.php`.
- M10 YouTube Publishing (#119): `SocialPublisher` contract, `PublishToYouTube` job pattern, `SocialPublish` model, API endpoints, `PublishClipDialog`/`PublishCard`/`PublishList` frontend components, `PublishClipRequest`, `SocialPublishController`.
- Auth infrastructure (sessions table, APP_KEY, SANCTUM_STATEFUL_DOMAINS): verified functional (prerequisite, §Auth Infrastructure Prerequisite).
- Laravel Sanctum authentication (existing).
- Queue system (existing).
- PostgreSQL (existing).

---

## 11. Acceptance Criteria Mapping

| AC | Coverage |
|----|----------|
| Auth: `GET /sanctum/csrf-cookie` → 204, register → 201, login → 200, session persists | §Auth Infrastructure Prerequisite |
| `SocialPublisher::class . ':instagram'` binding → `InstagramPublisher` | §2.4, §3 |
| `InstagramPublisher` implements `SocialPublisher` (M10 interface) | §3 |
| `PublishToInstagram` job follows `PublishToYouTube` pattern | §4 |
| `SocialPublish` model reused (M10, no schema change) | §5 |
| POST /api/v1/social/publish supports platform="instagram" | §6.1 |
| GET /api/v1/social/publishes and GET /api/v1/social/publishes/{publish} | §6.2, §6.3 |
| `PublishClipRequest` accepts `platform in:youtube,instagram` | §6.1 |
| `PublishClipDialog` supports platform="instagram" | §7.2 |
| Tokens encrypted at rest, never leaked | §8 |
| CSRF, rate limiting 20/min, ownership 404s | §8 |
| CI: FakeInstagramPublisher, no network | §9 |
| E2E Playwright at 3 viewports | §9 |
| Independent Tester approval | Orchestrator workflow |

---

## 12. Out of Scope

- TikTok publishing (M12).
- Multi-platform one-click publishing (M14).
- Real Instagram API credentials in CI.
- Post scheduling.
- Instagram Stories/Reels-specific features beyond Graph API publish.
- Re-implementing the auth 500 fix (already present in M10; verified as prerequisite here).
