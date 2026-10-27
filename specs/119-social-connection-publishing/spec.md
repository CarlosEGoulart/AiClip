# M10 — YouTube Publishing Specification

## Overview

This specification defines the application-level foundation for publishing approved rendered clips from AiClip to connected social platforms, starting with YouTube (M10). It builds directly on M9's Social Connection Framework (Issue #118), which established provider-neutral OAuth connections for YouTube, Instagram, and TikTok with encrypted token storage.

M10 introduces:
- A provider-neutral `SocialPublisher` publishing contract (so M11 Instagram and M12 TikTok can plug in later).
- A `YouTubePublisher` implementation that uploads an approved rendered clip to YouTube using the connected user's encrypted OAuth access token.
- A `SocialPublish` model to track publish jobs (pending → uploading → published/failed) with external video ID and video URL.
- API endpoints to initiate publishing, list publishes, and check publish status.
- A `PublishToYouTube` queue job (inference/upload never inside an HTTP request process).
- Frontend UI to publish an approved clip and monitor publish status.

M10 is the first of the publishing milestones: M10 YouTube → M11 Instagram → M12 TikTok → M14 Unified One-Click Publishing.

---

## 1. Publishable Content

### 1.1 What Can Be Published

Only **approved rendered clips** may be published. A clip is publishable when all of the following hold:

- A `DerivedAsset` of `type = clip_rendered` exists with `render_status = completed`.
- A `ClipReview` exists for that `DerivedAsset` with `status = approved`.
- The `ClipReview` reviewer is the authenticated user.
- The authenticated user owns the project that owns the media asset.
- The user has a `connected` YouTube `SocialConnection` with a valid (refreshable) access token.

### 1.2 Not Publishable

- Clips with `pending`, `rejected`, or `needs_changes` review status.
- Rendered clips with `render_status ≠ completed`.
- Media assets that are not rendered clips (raw uploads, audio-normalized derivatives).
- Images from M8 Image Studio (M10 publishes video clips only).

---

## 2. Provider-Neutral Social Publisher Contract

### 2.1 SocialPublisher Interface

```php
namespace App\Contracts;

interface SocialPublisher
{
    /**
     * Unique platform identifier (e.g., 'youtube').
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
     * @param  array{local_path: string, title: string, description?: string, privacy_status?: string}  $input
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

### 2.2 PublishResult Value Object

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

### 2.3 PublishStatus Value Object

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

### 2.4 Publisher Registration

Publishers are registered in the Laravel container via `AppServiceProvider`:

```php
$this->app->bind(SocialPublisher::class . ':youtube', YouTubePublisher::class);
```

Resolved by platform string:
```php
$publisher = app(SocialPublisher::class . ':' . $platform);
```

---

## 3. YouTubePublisher Implementation

### 3.1 Behavior

- Uses the connected YouTube `SocialConnection`'s valid access token (via `SocialConnectionManager::getValidAccessToken`, which refreshes if needed).
- Uploads the rendered clip video file to YouTube using the access token.
- Returns `PublishResult` with YouTube's `external_video_id` and `video_url`.
- Never runs inside an HTTP request; invoked by the `PublishToYouTube` queue job.
- Fails gracefully (returns `failed` status + error message) on upload errors; does not leak tokens.

### 3.2 FakeYouTubePublisher (Testing)

- Deterministic fake implementing `SocialPublisher`.
- No network calls, no real YouTube API.
- Configurable success/failure scenarios for unit/feature tests.
- Bound in the `testing` environment only, per AGENTS.md §21 (CI-safe, no model download).

---

## 4. SocialPublish Model

### 4.1 Database Schema

```php
// Migration: create_social_publishes_table
Schema::create('social_publishes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('social_connection_id')->constrained('social_connections')->cascadeOnDelete();
    $table->foreignId('clip_review_id')->constrained('clip_reviews')->cascadeOnDelete();
    $table->string('platform'); // 'youtube'
    $table->string('status'); // pending, uploading, published, failed
    $table->string('external_video_id')->nullable(); // YouTube video ID
    $table->string('video_url')->nullable(); // Public YouTube watch URL
    $table->string('title');
    $table->text('description')->nullable();
    $table->text('error_message')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'status']);
    $table->index(['social_connection_id']);
    $table->index(['clip_review_id']);
});
```

### 4.2 Model Definition

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPublish extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'social_connection_id',
        'clip_review_id',
        'platform',
        'status',
        'external_video_id',
        'video_url',
        'title',
        'description',
        'error_message',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function socialConnection(): BelongsTo { return $this->belongsTo(SocialConnection::class); }
    public function clipReview(): BelongsTo { return $this->belongsTo(ClipReview::class); }

    // Scopes
    public function scopePending($query) { return $query->where('status', 'pending'); }
    public function scopeUploading($query) { return $query->where('status', 'uploading'); }
    public function scopePublished($query) { return $query->where('status', 'published'); }
    public function scopeFailed($query) { return $query->where('status', 'failed'); }
    public function scopePlatform($query, string $platform) { return $query->where('platform', $platform); }
}
```

### 4.3 Status Enum

```php
namespace App\Enums;

enum SocialPublishStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Published = 'published';
    case Failed = 'failed';
}
```

---

## 5. API Endpoints

All endpoints require `auth:sanctum` and enforce user ownership. State-changing endpoints are CSRF-protected.

### 5.1 Publish a Clip
```
POST /api/v1/social/publish
```

**Request:**
```json
{
  "clip_review_id": 1,
  "platform": "youtube",
  "title": "My Clip",
  "description": "Optional description"
}
```

**Response (202 Accepted):** Publish job enqueued.
```json
{
  "data": {
    "publish_id": 7,
    "status": "pending",
    "platform": "youtube"
  }
}
```

**Errors:**
- 401: Unauthenticated
- 422: Validation failure (invalid clip_review_id, platform, missing title)
- 404: Clip review not found, not owned, not approved, or no completed render
- 404: No connected YouTube connection (hides existence)
- 409: A publish already exists for this clip review
- 429: Rate limited (20/min)
- 419: CSRF token missing/invalid

### 5.2 List Publishes
```
GET /api/v1/social/publishes
```

**Response (200):**
```json
{
  "data": [
    {
      "id": 7,
      "platform": "youtube",
      "platform_label": "YouTube",
      "status": "published",
      "external_video_id": "dQw4w9WgXcQ",
      "video_url": "https://youtu.be/dQw4w9WgXcQ",
      "title": "My Clip",
      "error_message": null,
      "created_at": "2026-10-26T10:00:00Z",
      "updated_at": "2026-10-26T10:05:00Z"
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

### 5.3 Publish Status
```
GET /api/v1/social/publishes/{publish}
```

**Response (200):**
```json
{
  "data": {
    "id": 7,
    "platform": "youtube",
    "status": "published",
    "external_video_id": "dQw4w9WgXcQ",
    "video_url": "https://youtu.be/dQw4w9WgXcQ",
    "title": "My Clip",
    "error_message": null,
    "created_at": "...",
    "updated_at": "..."
  }
}
```

**Errors:**
- 404: Publish not found or not owned by user

---

## 6. PublishToYouTube Queue Job

### 6.1 Topology

Follows the `RenderMediaClip` / `GenerateImage` job topology:
- Implements `ShouldQueue`.
- Ownership re-validation at job start (user owns clip review + connection).
- Non-pending idempotency (skip if not `pending`).
- Strict result validation.
- Transactional status marking (pending → uploading → published/failed).
- Upload/inference never inside an HTTP request process.

### 6.2 Behavior

1. Load the `SocialPublish` and verify status is `pending`.
2. Transition to `uploading`.
3. Resolve the `SocialConnection` (must be `connected`, YouTube).
4. Get a valid access token via `SocialConnectionManager::getValidAccessToken` (refreshes if needed).
5. Resolve `YouTubePublisher` via container.
6. Call `publishVideo()` with the rendered clip's local storage path.
7. On success: mark `published`, store `external_video_id` and `video_url`.
8. On failure: mark `failed`, store sanitized `error_message` (no tokens).
9. Dispatch events for frontend status reflection (optional, follows `ProcessClipReview` pattern).

### 6.3 Concurrency

- `uniqueId()` based on `social-publish:{publishId}` to prevent duplicate dispatches.
- `tries = 3`, `backoff = 5`.

---

## 7. Frontend Publishing UI

### 7.1 Component Hierarchy

```
PublishClipDialog (modal, opened from approved clip)
├── PlatformSelector (YouTube only in M10)
├── PublishForm (title, description, privacy)
├── PublishButton (disabled during upload)
└── PublishStatus (pending/uploading/published/failed)
PublishList (page/tab)
├── PublishCard (per publish)
│   ├── StatusBadge
│   ├── VideoLink (opens YouTube watch URL when published)
│   └── ErrorMessage (when failed)
├── EmptyState
└── LoadingState
```

### 7.2 Entry Point

- A "Publish to YouTube" action button appears on approved `ClipReview` cards (inside the M7 Clip Review experience / ProjectMediaSection).
- Clicking opens `PublishClipDialog`.
- After successful publish, a `PublishList` page/tab shows all publishes with status.

### 7.3 API Client & Hooks

- `apps/web/src/features/social-publish/api/index.ts`: `publishClip()`, `getPublishes()`, `getPublishStatus()`.
- `apps/web/src/features/social-publish/hooks.ts`: `usePublishClip()`, `usePublishes()`.
- Follows existing M9 social API client patterns (CSRF handling, error classification).

### 7.4 Accessibility

- All interactive elements keyboard accessible (Tab, Enter, Space, Escape).
- Visible focus indicators (3px solid outline, offset 2px).
- ARIA labels on icon-only buttons.
- Live region for status announcements.
- Color contrast ≥ 4.5:1 for text, 3:1 for UI components.
- Status badges use both color AND text.
- 44px touch targets.

---

## 8. Security Considerations

### 8.1 Token Protection

- OAuth `access_token`/`refresh_token` remain encrypted at rest (M9 `encrypted` cast), never exposed.
- Publishing uses the valid access token obtained via `SocialConnectionManager::getValidAccessToken()` — token is never returned to the frontend.
- Tokens never logged (explicitly excluded from log context).
- No tokens in API responses, browser dev tools, or network.

### 8.2 Authorization

- All endpoints require authenticated user (`auth:sanctum`).
- Publish queries scoped to `auth()->id()`.
- 404 for non-owned clip reviews, connections, and publishes (hides existence).
- A user can only publish clips from projects they own.
- A user can only publish via their own connected YouTube connection.

### 8.3 CSRF & Rate Limiting

- CSRF token required for `POST /api/v1/social/publish` (Sanctum).
- Rate limit: 20/min for publish (matches M9 connect rate limit).

### 8.4 Audit Logging

- Publish initiated (user_id, platform, clip_review_id, no tokens).
- Publish completed (external_video_id, no tokens).
- Publish failed (reason only, no tokens).
- NEVER log: access_token, refresh_token, upload responses.

### 8.5 CI Safety

- No real YouTube API credentials in CI; `FakeYouTubePublisher` used in tests.
- CI uses deterministic fakes; no network calls to YouTube.

---

## 9. Out of Scope

- Instagram publishing (M11) and TikTok publishing (M12) — separate provider implementations.
- Unified one-click multi-platform publishing (M14).
- Publishing images from M8 Image Studio (M10 publishes video clips only).
- Scheduling or recurring publishes.
- Deleting or editing already-published content on social platforms.
- Real YouTube API credentials or production secrets setup.
- Webhook handling for platform-initiated video removal (future).
- Token auto-refresh background job (refresh happens on-demand during publish).
- Publishing clips with non-approved review status.

---

## 10. UX Considerations

- The "Publish to YouTube" button is only shown on approved clips with a connected YouTube account.
- Disabled/loading states during upload with clear feedback.
- Error states are understandable and actionable (e.g., "Reconnect YouTube" when the connection expired).
- Published videos show a direct link to YouTube.
- Publish list shows status badges using color + text.
- Responsive layout: single column below 1024px, side-by-side above.
- Reduced-motion support respected.
- Styling follows `design-taste-frontend` art direction and project design tokens (Switzer font, mono-accent palette, square corners).

### 10.1 Visual Design Requirements (Design Fix Scope)

All auth and publishing UI (login, register, publish dialog, publish list, error pages) must satisfy the following. These requirements drive the design-fix work for Issue #119; no frontend files have been changed for design fixes yet.

#### 10.1.1 Remove Meaningless Images (Required)

- **Remove all picsum.photos archival images** from login and register screens (`apps/web/src/App.tsx`, `AuthEntry` component, lines 29-178):
  - Remove the `authImages` array (4 images: film editing room, Steenbeck flatbed editor, 16mm film strip, early videotape recorder).
  - Remove the `authBgStyle` CSS variable that sets `--auth-bg-image` to a picsum.photos URL.
  - Remove the `auth-image-credits` div (screen-reader image credits).
  - Remove the `auth-atmosphere-image` figure with the picsum.photos `` (800x600).
  - Remove the `auth-atmosphere-gallery` div with two picsum.photos `` elements (600x400 each).
  - Remove the `auth-hint` timeline fragment div (simulated clip timeline — decorative, adds no value).
  - Remove the same image blocks from `BootstrapErrorView` (lines 185-278): `authImages` array, `authBgStyle`, `auth-image-credits`, `auth-atmosphere-image`, `auth-atmosphere-gallery`.
- **Image absence verification**: No `picsum.photos` URLs, no `auth-atmosphere-image`, `auth-atmosphere-gallery`, `auth-image-credits`, `auth-image-caption`, or `auth-atmosphere-item` classes/elements may appear on `/login` or `/register` pages at any viewport. Verified via Playwright at 390x844, 768x1024, 1440x900.
- Replace imagery with clean typography-based design: wordmark, headline, supporting copy, form fields, primary button, and link buttons only. No decorative figures, captions, or galleries.

#### 10.1.2 Design Tokens & Aesthetic (design-taste-frontend)

- **Font**: Switzer (grotesk-light) loaded from `https://api.fontshare.com/v2/css?f[]=switzer@400,500&display=swap`; hierarchy by position and ink, single roman weight.
- **Palette**: mono-accent (stone/blueprint); `--bg`, `--surface`, `--ink`, `--muted`, `--accent` (`#2456d6`), `--accent-ink` (`#ffffff`).
- **Corners**: square only — `--radius: 0`, `--radius-sm: 0`, `--radius-lg: 0`.
- **Spacing**: `--space-xs: 4px`, `--space-sm: 8px`, `--space-md: 16px`, `--space-lg: 24px`, `--space-xl: 32px`, `--space-2xl: 48px`, `--space-3xl: 96px`, `--space-4xl: 140px`.
- **Type scale**: five clamp-based steps (`--step-small` through `--step-display`).
- **Focus ring**: `--focus-ring: 3px solid var(--accent)`, `--focus-offset: 2px`.
- **Modern/sophisticated aesthetic**: Interface looks intentionally designed and coherent, not automatically generated (AGENTS.md §15 Q11). No generic/templated/AI-made appearance.
- **web-design-guidelines compliance**: Accessibility fundamentals respected; keyboard navigation; focus indicators (3px outline); responsive layouts at 390x844 / 768x1024 / 1440x900.

#### 10.1.3 Layout Alignment

- **Centered layout**: Page content and forms centered per design tokens; no unusual flush-left alignment.
- **Auth forms**: Centered with consistent side margins (`auth-column`: `max-width: 380px`, centered via `align-items: center`, `margin: 0`).
- **Wordmark**: Top-left, modest size (`align-self: flex-start` within centered column).
- **Publish dialog**: Centered horizontally.
- **No left alignment**: No flush-left page content or forms on auth pages.

#### 10.1.4 Error States & Login Reliability

- **500/404 pages**: Render gracefully with understandable messaging, never blank or broken.
- **Login reliability**: `POST /api/v1/auth/login` returns 200 for valid credentials; never 500. Investigated and resolved — no server errors on login endpoint.
- **Error messages**: Generic, understandable, actionable; no credential enumeration or stack traces in response body.

#### 10.1.5 Auth Infrastructure Fixes (Login 500 Error)

The following infrastructure fixes resolve the reported `GET /sanctum/csrf-cookie 500` and login 500 errors on the cloudshell.dev domain:

- **Sessions table migration**: Create `database/migrations/XXXX_create_sessions_table.php` using the default Laravel sessions table schema (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`). Required because `SESSION_DRIVER=database` but no `sessions` table migration exists — Laravel cannot write sessions, causing 500 on `/sanctum/csrf-cookie` and on login.
- **APP_KEY**: Set a non-empty `APP_KEY` in `.env.example` (run `php artisan key:generate`). Required for encryption and signing of session cookies and CSRF tokens.
- **SANCTUM_STATEFUL_DOMAINS**: Add `5173-cs-370822403794-default.cs-us-east1-dogs.cloudshell.dev` to `SANCTUM_STATEFUL_DOMAINS` so stateful Sanctum session cookies are set for the cloudshell.dev domain.
- **Login endpoint**: `POST /api/v1/auth/login` returns 200 for valid credentials; never 500.
- **CSRF cookie endpoint**: `GET /sanctum/csrf-cookie` returns 204 and sets the `XSRF-TOKEN` cookie; never 500.

---

## 11. Dependencies

- M9 Social Connection Framework (Issue #118): `SocialConnection` model, `SocialConnectionProvider` contract, `SocialConnectionManager`, encrypted token storage, 5 connection endpoints, fakes.
- M7 Clip Review Experience (Issue #106): `ClipReview` model with `approved` status, `DerivedAsset` rendered clips.
- M2 Media Storage (Issue #35): S3-compatible storage for rendered clip video files.
- Laravel Sanctum authentication (existing).
- Queue system for async jobs (existing).
- Laravel encrypted casts (existing).
- Rate limiting infrastructure (existing).
- `config/social.php` provider configuration (M9).

---

## 12. Acceptance Criteria Mapping

| AC | Coverage |
|----|----------|
| Publish approved rendered clip to YouTube | §5.1, §6 |
| Publish job tracked via SocialPublish model | §4, §5.2, §5.3 |
| Tokens encrypted at rest, never exposed | §8.1 |
| User ownership enforced (clip, connection, publish) | §8.2 |
| CSRF + rate limiting on publish endpoint | §8.3 |
| FakeYouTubePublisher in CI (no real network) | §3.2, §8.5 |
| Frontend publish dialog + publish list UI | §7 |
| Accessibility (keyboard, focus, ARIA, touch targets) | §7.4 |
| Unit, feature, integration, E2E tests pass | test-plan.md |
| Lint, build, governance, E2E green | test-plan.md §6 |
| All picsum.photos images removed from login/register screens | §10.1.1, test-plan.md §5.9 |
| Image absence verification passes at 3 viewports (no auth-page images) | §10.1.1, test-plan.md §5.9 |
| Centered layout; no unusual left alignment on auth pages | §10.1.3, test-plan.md §5.9 |
| Visual design requirements satisfied (tokens, aesthetic, web-design-guidelines) | §10.1, test-plan.md §5.9 |
| design-taste-frontend art direction review passes at 3 viewports | test-plan.md §5.9 |
| web-design-guidelines compliance review passes | test-plan.md §5.9 |
| Login endpoint returns 200 for valid credentials; never 500 | §10.1.4, §10.1.5, test-plan.md §5.5, §5.8 |
| Sessions table migration exists; `SESSION_DRIVER=database` works | §10.1.5 |
| `APP_KEY` set in `.env.example` (non-empty) | §10.1.5 |
| `SANCTUM_STATEFUL_DOMAINS` includes cloudshell.dev domain | §10.1.5 |
| `GET /sanctum/csrf-cookie` returns 204 and sets XSRF-TOKEN cookie; never 500 | §10.1.5, test-plan.md §5.10 |
| Auth session persistence: register → login → session survives reload | §10.1.5, test-plan.md §5.11 |
| 500/404 error pages render gracefully, never blank or broken | §10.1.4, test-plan.md §5.6 |
| Playwright Visual QA passes at 3 viewports for login/register | §10.1.1, §10.1.2, §10.1.3, test-plan.md §5.12 |
| Auth flow functional: register → login → session persistence | §10.1.4, §10.1.5, test-plan.md §5.13 |
| Design review passes per design-taste-frontend criteria at 3 viewports | §10.1.2, test-plan.md §5.14 |
| Backend CI failure investigation scope defined for SocialPublishIntegrationTest | test-plan.md §5.15 |

---

## 13. CI Requirements

All four GitHub Actions CI workflows must pass on the issue branch before the issue is considered done:

| Workflow | File | Status target |
|----------|------|---------------|
| Backend CI | `.github/workflows/backend.yml` | green |
| E2E CI | `.github/workflows/e2e.yml` | green |
| Frontend CI | `.github/workflows/frontend.yml` | green |
| Governance | `.github/workflows/governance.yml` | green |

Failure of any single workflow blocks merge via `scripts/merge_gate.py` and blocks issue closure.

---
