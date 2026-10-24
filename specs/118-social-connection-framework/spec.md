# M9 — Social Connection Framework Specification

## Overview

This specification defines the application-level foundation for connecting AiClip projects and users to social platforms (YouTube, Instagram, TikTok). It establishes a provider-neutral social connection contract, encrypted credential storage, API endpoints for connection lifecycle management, and a frontend connection-management UI. Platform-specific publishing integrations (M10–M12) will build upon these contracts.

---

## 1. Provider-Neutral Social Connection Contract

### 1.1 SocialConnectionProvider Interface

```php
namespace App\Contracts;

interface SocialConnectionProvider
{
    /**
     * Unique platform identifier (e.g., 'youtube', 'instagram', 'tiktok').
     */
    public function getPlatform(): string;

    /**
     * Human-readable platform label for UI display.
     */
    public function getPlatformLabel(): string;

    /**
     * OAuth scopes required for publishing permissions.
     */
    public function getRequiredScopes(): array;

    /**
     * Generate the authorization URL for the OAuth flow.
     * Must include state parameter for CSRF protection.
     */
    public function getAuthorizationUrl(string $state): string;

    /**
     * Exchange authorization code for access/refresh tokens.
     * Returns token data without storing it.
     */
    public function exchangeCodeForTokens(string $code): TokenResponse;

    /**
     * Refresh an expired access token using the refresh token.
     * Returns new token data without storing it.
     */
    public function refreshAccessToken(string $refreshToken): TokenResponse;

    /**
     * Validate that tokens are still valid and have required scopes.
     * Called during status checks and before publishing.
     */
    public function validateToken(string $accessToken): bool;

    /**
     * Revoke tokens on the provider side (if supported).
     * Best effort — do not throw on failure.
     */
    public function revokeToken(string $accessToken): void;

    /**
     * Get the minimal user profile info for display (name, avatar).
     * Called after successful connection for UI confirmation.
     */
    public function getUserProfile(string $accessToken): ProviderUserProfile;
}
```

### 1.2 TokenResponse Value Object

```php
namespace App\Contracts;

class TokenResponse
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly int $expiresIn, // seconds until access token expires
        public readonly array $scopes,
    ) {}
}
```

### 1.3 ProviderUserProfile Value Object

```php
namespace App\Contracts;

class ProviderUserProfile
{
    public function __construct(
        public readonly string $providerUserId,
        public readonly string $displayName,
        public readonly ?string $avatarUrl,
        public readonly ?string $channelUrl,
    ) {}
}
```

### 1.4 Provider Registration

Providers are registered in the Laravel container via `AppServiceProvider`:

```php
$this->app->bind(SocialConnectionProvider::class . ':youtube', YoutubeConnectionProvider::class);
$this->app->bind(SocialConnectionProvider::class . ':instagram', InstagramConnectionProvider::class);
$this->app->bind(SocialConnectionProvider::class . ':tiktok', TikTokConnectionProvider::class);
```

The `SocialConnectionManager` resolves providers by platform string:
```php
$provider = app(SocialConnectionProvider::class . ':' . $platform);
```

---

## 2. SocialConnection Model

### 2.1 Database Schema

```php
// Migration: create_social_connections_table
Schema::create('social_connections', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('platform'); // 'youtube', 'instagram', 'tiktok'
    $table->string('provider_user_id'); // Platform-specific user ID
    $table->string('display_name'); // User-facing name
    $table->string('avatar_url')->nullable();
    $table->string('channel_url')->nullable();

    // Encrypted token storage (Laravel encrypted cast)
    $table->text('access_token')->nullable(); // encrypted
    $table->text('refresh_token')->nullable(); // encrypted
    $table->timestamp('access_token_expires_at')->nullable();

    // Connection status
    $table->string('status')->default('connected'); // connected, expired, revoked, error
    $table->text('last_error')->nullable();

    // OAuth state management (single-use, short TTL)
    $table->string('pending_oauth_state')->nullable();
    $table->timestamp('pending_oauth_state_expires_at')->nullable();

    $table->timestamps();

    $table->unique(['user_id', 'platform', 'provider_user_id']);
    $table->index(['user_id', 'status']);
});
```

### 2.2 Model Definition

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['access_token', 'refresh_token', 'pending_oauth_state'])]
class SocialConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'platform',
        'provider_user_id',
        'display_name',
        'avatar_url',
        'channel_url',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'status',
        'last_error',
        'pending_oauth_state',
        'pending_oauth_state_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'pending_oauth_state_expires_at' => 'datetime',
        ];
    }

    // Scopes
    public function scopeConnected($query) { return $query->where('status', 'connected'); }
    public function scopeExpired($query) { return $query->where('status', 'expired'); }
    public function scopeRevoked($query) { return $query->where('status', 'revoked'); }
    public function scopeActive($query) { return $query->whereIn('status', ['connected', 'expired']); }

    // Relationships
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    // Accessors
    protected function isTokenExpired(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->access_token_expires_at
                ? $this->access_token_expires_at->isPast()
                : true,
        );
    }

    protected function needsRefresh(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === 'connected'
                && $this->access_token_expires_at
                && $this->access_token_expires_at->diffInMinutes(now()) < 10,
        );
    }
}
```

### 2.3 Status Enum

```php
namespace App\Enums;

enum SocialConnectionStatus: string
{
    case Connected = 'connected';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Error = 'error';
}
```

---

## 3. SocialConnectionManager Service

```php
namespace App\Services;

use App\Contracts\SocialConnectionProvider;
use App\Contracts\TokenResponse;
use App\Contracts\ProviderUserProfile;
use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SocialConnectionManager
{
    private const OAUTH_STATE_TTL = 600; // 10 minutes

    /**
     * Initiate a new connection flow.
     * Creates a pending connection row with a single-use OAuth state.
     */
    public function initiateConnection(User $user, string $platform): InitiateConnectionResult
    {
        $provider = $this->resolveProvider($platform);

        $state = Str::random(40);
        $expiresAt = now()->addSeconds(self::OAUTH_STATE_TTL);

        $connection = SocialConnection::create([
            'user_id' => $user->id,
            'platform' => $platform,
            'provider_user_id' => 'pending',
            'display_name' => 'Connecting…',
            'status' => SocialConnectionStatus::Connected->value,
            'pending_oauth_state' => $state,
            'pending_oauth_state_expires_at' => $expiresAt,
        ]);

        return new InitiateConnectionResult(
            authorizationUrl: $provider->getAuthorizationUrl($state),
            state: $state,
            connectionId: $connection->id,
        );
    }

    /**
     * Handle the OAuth callback.
     * Validates state, exchanges code, persists tokens, fetches profile.
     */
    public function handleCallback(
        User $user,
        string $platform,
        string $state,
        string $code
    ): HandleCallbackResult {
        // Find pending connection by state
        $connection = SocialConnection::where('user_id', $user->id)
            ->where('platform', $platform)
            ->where('pending_oauth_state', $state)
            ->where('pending_oauth_state_expires_at', '>', now())
            ->firstOrFail();

        // Consume state — invalidate immediately to prevent replay
        $connection->pending_oauth_state = null;
        $connection->pending_oauth_state_expires_at = null;
        $connection->save();

        $provider = $this->resolveProvider($platform);

        // Exchange code for tokens
        $tokenResponse = $provider->exchangeCodeForTokens($code);

        // Fetch provider user profile
        $profile = $provider->getUserProfile($tokenResponse->accessToken);

        // Update connection with real data
        $connection->provider_user_id = $profile->providerUserId;
        $connection->display_name = $profile->displayName;
        $connection->avatar_url = $profile->avatarUrl;
        $connection->channel_url = $profile->channelUrl;
        $connection->access_token = $tokenResponse->accessToken;
        $connection->refresh_token = $tokenResponse->refreshToken;
        $connection->access_token_expires_at = now()->addSeconds($tokenResponse->expiresIn);
        $connection->status = SocialConnectionStatus::Connected->value;
        $connection->last_error = null;
        $connection->save();

        // Audit log (no tokens)
        Log::info('Social connection established', [
            'user_id' => $user->id,
            'platform' => $platform,
            'provider_user_id' => $profile->providerUserId,
            'connection_id' => $connection->id,
        ]);

        return new HandleCallbackResult($connection);
    }

    /**
     * Refresh an expired access token.
     */
    public function refreshConnection(SocialConnection $connection): bool
    {
        if (! $connection->refresh_token) {
            $this->markRevoked($connection, 'No refresh token available');
            return false;
        }

        $provider = $this->resolveProvider($connection->platform);

        try {
            $tokenResponse = $provider->refreshAccessToken($connection->refresh_token);

            $connection->access_token = $tokenResponse->accessToken;
            $connection->refresh_token = $tokenResponse->refreshToken ?? $connection->refresh_token;
            $connection->access_token_expires_at = now()->addSeconds($tokenResponse->expiresIn);
            $connection->status = SocialConnectionStatus::Connected->value;
            $connection->last_error = null;
            $connection->save();

            Log::info('Social connection token refreshed', [
                'user_id' => $connection->user_id,
                'platform' => $connection->platform,
                'connection_id' => $connection->id,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->markRevoked($connection, 'Token refresh failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Disconnect (revoke locally and optionally at provider).
     */
    public function disconnect(SocialConnection $connection): void
    {
        $provider = $this->resolveProvider($connection->platform);

        if ($connection->access_token) {
            try {
                $provider->revokeToken($connection->access_token);
            } catch (\Throwable) {
                // Best effort — log but continue with local revocation
                Log::warning('Provider token revocation failed', [
                    'connection_id' => $connection->id,
                ]);
            }
        }

        $connection->status = SocialConnectionStatus::Revoked->value;
        $connection->access_token = null;
        $connection->refresh_token = null;
        $connection->access_token_expires_at = null;
        $connection->save();

        Log::info('Social connection disconnected', [
            'user_id' => $connection->user_id,
            'platform' => $connection->platform,
            'connection_id' => $connection->id,
        ]);
    }

    /**
     * Get a valid access token, refreshing if needed.
     * Returns null if token cannot be obtained.
     */
    public function getValidAccessToken(SocialConnection $connection): ?string
    {
        if ($connection->status !== SocialConnectionStatus::Connected->value) {
            return null;
        }

        if ($connection->needs_refresh) {
            $this->refreshConnection($connection);
            $connection->refresh();
        }

        if ($connection->is_token_expired) {
            return null;
        }

        return $connection->access_token;
    }

    /**
     * Validate connection and update status if needed.
     */
    public function validateConnection(SocialConnection $connection): bool
    {
        if ($connection->status !== SocialConnectionStatus::Connected->value) {
            return false;
        }

        $provider = $this->resolveProvider($connection->platform);
        $token = $this->getValidAccessToken($connection);

        if (! $token) {
            return false;
        }

        if (! $provider->validateToken($token)) {
            $this->markExpired($connection, 'Token validation failed');
            return false;
        }

        return true;
    }

    private function markExpired(SocialConnection $connection, string $reason): void
    {
        $connection->status = SocialConnectionStatus::Expired->value;
        $connection->last_error = $reason;
        $connection->save();

        Log::warning('Social connection expired', [
            'connection_id' => $connection->id,
            'reason' => $reason,
        ]);
    }

    private function markRevoked(SocialConnection $connection, string $reason): void
    {
        $connection->status = SocialConnectionStatus::Revoked->value;
        $connection->last_error = $reason;
        $connection->access_token = null;
        $connection->refresh_token = null;
        $connection->save();

        Log::warning('Social connection revoked', [
            'connection_id' => $connection->id,
            'reason' => $reason,
        ]);
    }

    private function resolveProvider(string $platform): SocialConnectionProvider
    {
        return app(SocialConnectionProvider::class . ':' . $platform);
    }
}
```

### 3.1 Result Value Objects

```php
namespace App\Services;

class InitiateConnectionResult
{
    public function __construct(
        public readonly string $authorizationUrl,
        public readonly string $state,
        public readonly int $connectionId,
    ) {}
}

class HandleCallbackResult
{
    public function __construct(
        public readonly SocialConnection $connection,
    ) {}
}
```

---

## 4. API Endpoints

All endpoints require `auth:sanctum` middleware and enforce user ownership.

### 4.1 List Connections
```
GET /api/v1/social/connections
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "platform": "youtube",
      "platform_label": "YouTube",
      "display_name": "My Channel",
      "avatar_url": "https://...",
      "channel_url": "https://youtube.com/@mychannel",
      "status": "connected",
      "last_error": null,
      "created_at": "2026-10-22T10:00:00Z",
      "updated_at": "2026-10-22T10:00:00Z"
    }
  ]
}
```

**Status values:** `connected`, `expired`, `revoked`, `error`

### 4.2 Initiate Connection
```
POST /api/v1/social/{platform}/connect
```

**Request:** `{}` (empty body, CSRF protected)

**Response (200):**
```json
{
  "data": {
    "authorization_url": "https://accounts.google.com/o/oauth2/v2/auth?...",
    "state": "abc123...",
    "connection_id": 5
  }
}
```

**Errors:**
- 422: Invalid platform
- 409: Connection already exists for this platform/user
- 429: Rate limited

### 4.3 Handle OAuth Callback
```
GET /api/v1/social/{platform}/callback?code=...&state=...
```

**Note:** This is a GET endpoint (OAuth redirect). State is validated against the pending connection.

**Success Response (200):** Redirect to frontend connection management page with success indicator.

**Error Response (302):** Redirect to frontend with error indicator.

### 4.4 Disconnect
```
DELETE /api/v1/social/connections/{connection}
```

**Response (204):** No content

**Errors:**
- 404: Connection not found or not owned by user
- 403: Forbidden (should return 404 per ownership hiding convention)

### 4.5 Connection Status (Optional - for polling)
```
GET /api/v1/social/connections/{connection}/status
```

**Response:**
```json
{
  "data": {
    "id": 1,
    "status": "connected",
    "last_error": null,
    "access_token_expires_at": "2026-10-23T10:00:00Z"
  }
}
```

---

## 5. API Resources

### 5.1 SocialConnectionResource

```php
namespace App\Http\Resources;

use App\Models\SocialConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $connection = $this->resource;

        return [
            'id' => $connection->id,
            'platform' => $connection->platform,
            'platform_label' => $this->getPlatformLabel($connection->platform),
            'display_name' => $connection->display_name,
            'avatar_url' => $connection->avatar_url,
            'channel_url' => $connection->channel_url,
            'status' => $connection->status,
            'last_error' => $connection->last_error,
            'created_at' => $connection->created_at?->toIso8601String(),
            'updated_at' => $connection->updated_at?->toIso8601String(),
        ];
    }

    private function getPlatformLabel(string $platform): string
    {
        return match ($platform) {
            'youtube' => 'YouTube',
            'instagram' => 'Instagram',
            'tiktok' => 'TikTok',
            default => ucfirst($platform),
        };
    }
}
```

### 5.2 SocialConnectionCollection

Standard Laravel pagination envelope.

---

## 6. Frontend Connection Management UI

### 6.1 Component Hierarchy

```
SocialConnections (page/tab)
├── SocialConnectionList
│   ├── SocialConnectionCard (per connection)
│   │   ├── ConnectionHeader (platform, display_name, avatar)
│   │   ├── StatusBadge (connected/expired/revoked/error)
│   │   ├── ActionButtons (disconnect, reconnect for expired)
│   │   └── ErrorMessage (when status === error)
│   ├── EmptyState (no connections)
│   └── LoadingState (skeleton cards)
└── ConnectPlatformModal
    ├── PlatformSelector (grid of platform cards)
    ├── LoadingState (during initiation)
    └── ErrorState (initiation failed)
```

### 6.2 UI States

| State | Description | Visual Treatment |
|-------|-------------|------------------|
| **Loading** | Fetching connections list | Skeleton cards (3 placeholders) |
| **Empty** | No connections exist | Illustration + "Connect your first account" CTA |
| **Connected** | Valid, active connection | Green badge, platform icon, display name, disconnect button |
| **Expired** | Token expired, refresh possible | Yellow badge, "Reconnect" button, tooltip explaining expiry |
| **Revoked** | Access revoked by user/provider | Gray badge, "Connect again" button |
| **Error** | Last operation failed | Red badge, error message, "Retry" button |

### 6.3 Connect Flow

1. User clicks "Connect [Platform]" → Opens `ConnectPlatformModal`
2. User selects platform → Calls `POST /api/v1/social/{platform}/connect`
3. Backend returns `authorization_url` and `state`
4. Frontend redirects to `authorization_url` (full page navigation)
5. Provider redirects to `/api/v1/social/{platform}/callback?code=...&state=...`
6. Backend validates state, exchanges code, stores connection
7. Backend redirects to frontend connections page with `?connected=platform` or `?error=...`
8. Frontend shows success/error toast, refreshes list

### 6.4 Disconnect Flow

1. User clicks disconnect on a connection card
2. Confirmation dialog: "Disconnect [Platform]? You'll need to reconnect to publish."
3. Calls `DELETE /api/v1/social/connections/{connection}`
4. On success: remove from list, show success toast

### 6.5 Reconnect Flow (for expired/revoked)

1. User clicks "Reconnect" on expired/revoked card
2. Same as connect flow but updates existing connection row
3. On success: status changes to `connected`

### 6.6 Accessibility Requirements

- All interactive elements keyboard accessible (Tab, Enter, Space, Escape)
- Visible focus indicators (3px solid outline, offset 2px)
- ARIA labels on icon-only buttons
- Live region for status announcements
- Color contrast ≥ 4.5:1 for text, 3:1 for UI components
- Status badges use both color AND text (not color alone)

---

## 7. Security Considerations

### 7.1 CSRF/State Validation

- OAuth `state` parameter: 40-character random string, single-use, 10-minute TTL
- State stored in `social_connections.pending_oauth_state` (encrypted)
- State consumed and nulled immediately on callback validation
- Replay attack prevention: state must match exactly and not be expired
- CSRF token required for `POST /connect` and `DELETE /disconnect` (Sanctum)

### 7.2 Token Encryption

- `access_token` and `refresh_token` use Laravel's `encrypted` cast
- Encryption key: `APP_KEY` (32-character base64)
- Tokens never returned in API responses (hidden in model)
- Tokens never logged (explicitly excluded from log context)

### 7.3 Audit Logging

Log entries for:
- Connection initiated (no tokens)
- Connection established (provider_user_id, no tokens)
- Token refreshed (no tokens)
- Token refresh failed (reason only)
- Connection disconnected (no tokens)
- Provider revocation attempted (best effort)

Log fields: `user_id`, `platform`, `provider_user_id`, `connection_id`, `reason` (on failure)

NEVER log: `access_token`, `refresh_token`, `code`, `state`, full provider responses

### 7.4 Authorization

- All endpoints require authenticated user (`auth:sanctum`)
- Connection queries scoped to `auth()->id()`
- 404 for non-owned connections (hides existence)
- Rate limiting: 20/min for connect, 60/min for list, 30/min for disconnect

### 7.5 Token Scope Minimization

Providers should request only publishing scopes:
- YouTube: `youtube.upload`, `youtube.readonly`
- Instagram: `instagram_content_publish`, `instagram_basic`
- TikTok: `video.publish`, `user.info.basic`

---

## 8. Provider Onboarding Requirements (for M10–M12)

Each platform adapter MUST implement `SocialConnectionProvider` and provide:

1. **Configuration** (in `config/social.php`):
   ```php
   'providers' => [
       'youtube' => [
           'client_id' => env('YOUTUBE_CLIENT_ID'),
           'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
           'redirect_uri' => env('APP_URL') . '/api/v1/social/youtube/callback',
           'scopes' => ['youtube.upload', 'youtube.readonly'],
       ],
       // ...
   ],
   ```

2. **Provider Class** implementing the full interface

3. **Tests** with deterministic fake provider:
   - `FakeYouTubeProvider`, `FakeInstagramProvider`, `FakeTikTokProvider`
   - Used in CI; no real network calls

4. **Documentation** in `docs/social-providers/`:
   - OAuth flow specifics (PKCE, offline access, etc.)
   - Scope requirements
   - Token refresh behavior
   - Revocation support
   - Rate limits and quotas

5. **Error Mapping**:
   - Map provider-specific errors to `SocialConnectionStatus::Error` with actionable `last_error`
   - Distinguish user-revoked (→ revoked) from expired (→ expired) from transient errors

---

## 9. Out of Scope

- Publishing content to any platform (M10–M12)
- One-click multi-platform publishing (M14)
- Real publishing credentials in CI or production secrets setup
- Token auto-refresh background job (manual refresh on demand)
- Webhook handling for provider-initiated revocation (future)

---

## 10. Dependencies

- Laravel Sanctum authentication (existing)
- User/Project ownership model (existing)
- Queue system for async operations (existing)
- Laravel encrypted casts (existing)
- Rate limiting infrastructure (existing)

---

## 12. Security Hardening (Part of M9)

Security maintenance executed within M9 scope to guarantee supply chain integrity of the runtime and CI pipeline.

### 12.1 Dependency Updates

| Layer | Change | Rationale |
|-------|--------|-----------|
| PHP | `^8.3` → `^8.3.14` | Patch-level security fixes (PHP 8.3.0–8.3.13 contain CVEs) |
| Laravel | v13.31.0 → v13.38.0 | Latest security patches |
| Node.js | unpinned → `>=22.14.0 <23` (engines + `/.nvmrc`) | LTS pinning against known CVEs |
| Docker postgres | `16-alpine` → `16.4-alpine` | Latest security patches |
| Docker minio | `RELEASE.2025-04-22` → `RELEASE.2025-10-02T04-12-34Z` | Latest security patches |

### 12.2 CI Supply Chain Pinning

All GitHub Actions workflows pin action versions and runtime versions:

| Workflow | Pinned Actions | Runtime Versions |
|----------|----------------|------------------|
| `backend.yml` | checkout v4.2.2, setup-php v2.30.0, setup-python v5.3.0, upload-artifact v4.6.0 | PHP 8.3.14, Python 3.12.7 |
| `frontend.yml` | checkout v4.2.2, setup-node v4.1.0 | Node 22 |
| `e2e.yml` | checkout v4.2.2, setup-php v2.30.0, setup-node v4.1.0, upload-artifact v4.6.0 | PHP 8.3.14, Node 22 |
| `test-e2e-caption.yml` | checkout v4.2.2, setup-php v2.30.0 | PHP 8.3.14 |
| `governance.yml` | checkout `11bd71901bbe5b1630ceea73d27597364c9af683` (v4.2.2), setup-python `0b93645e9fea7318ecaed2b359559ac225c90a2b` (v5.3.0) | Python 3.12.7 |

### 12.3 Security Acceptance Criteria

- [ ] `composer audit` → 0 high/critical vulnerabilities
- [ ] `npm audit` → 0 high/critical vulnerabilities (low/moderate may be accepted with justification)
- [ ] All GitHub Actions workflows use pinned action versions
- [ ] `/.nvmrc` at repository root with `22.14.0`; `apps/web/.nvmrc` removed
- [ ] Governance tests pass under pinned Python 3.12.7

---

## 13. Acceptance Criteria Mapping

| AC | Coverage |
|----|----------|
| Platform-neutral contract documented | §1, §8 |
| OAuth state validated, no replay | §3, §4.3, §7.1 |
| Tokens encrypted at rest, never in browser/logs | §2.1, §2.2, §5.1, §7.2, §7.3 |
| User ownership enforced | §4, §7.4 |
| Connect, callback failure, disconnect, expired, unauthorized tested | §11 (test-plan.md) |
| UI states keyboard accessible, visible focus | §6.6 |
| Backend/frontend tests, lint, build, governance, E2E pass | §11 |
| Security hardening verified (audits clean, CI pinned) | §12 |
| Independent Tester approval | Orchestrator workflow |
| CI green before merge | §14 CI Requirements |
| All four CI workflows green on issue branch | §14 CI Requirements |

---

## 14. CI Requirements

### 14.1 Requirement

All four GitHub Actions CI workflows must pass on the issue branch before the issue is considered done:

| Workflow | File | Status target |
|----------|------|---------------|
| Backend CI | `.github/workflows/backend.yml` | green |
| E2E CI | `.github/workflows/e2e.yml` | green |
| Frontend CI | `.github/workflows/frontend.yml` | green |
| Governance | `.github/workflows/governance.yml` | green |

Failure of any single workflow blocks merge via `scripts/merge_gate.py` and blocks issue closure.

### 14.2 Known CI Defects to Fix

The following defects were observed in CI run `737a26a` (Backend CI = failure, E2E CI = failure, Frontend CI = success, Governance = success). Each must be resolved before the issue can close.

**(a) Invalid MinIO image tags**

`backend.yml` uses `minio/minio:RELEASE.2024-01-20T00-00-00Z` and `test-e2e-caption.yml` uses `minio/minio:RELEASE.2025-10-02T04-12-34Z`. Neither is a valid public MinIO release tag; MinIO release tags use real timestamped release identifiers (e.g. `RELEASE.2023-01-01T00-00-00Z` patterns are not real tags either — real tags are like `RELEASE.2023-10-26T21-48-09Z`). The invalid tag prevents the service container from starting, causing backend/E2E composer failures.

Success behavior: both workflows pull a valid public MinIO image and the MinIO service container becomes healthy.
Failure behavior: container fails to start; database/object-storage-dependent tests error out; workflow fails.

**(b) PHP version conflict with `composer.lock` / `symfony/clock`**

`apps/api/composer.json` requires `php: ^8.3.14`, but `composer.lock` contains `symfony/clock v8.1.9` which requires `PHP >= 8.4.1`. `test-e2e-caption.yml` still runs PHP 8.3, which fails the composer platform check and aborts installation before tests run.

Success behavior: the PHP runtime used in CI satisfies `composer.lock` platform requirements (PHP >= 8.4.1 when `symfony/clock v8.1.9` is locked); `composer install` succeeds; tests run.
Failure behavior: `composer install` errors with platform mismatch; workflow aborts; no tests execute.

**(c) Verify `backend.yml` / `e2e.yml` PHP 8.4.1 resolves composer platform checks**

`backend.yml` and `e2e.yml` currently run PHP 8.4.1. This must be verified against `composer.json` (`^8.3.14`) and `composer.lock` (`symfony/clock v8.1.9` needs >= 8.4.1) to confirm `composer install` passes with no platform mismatch.

Success behavior: `composer install` completes with PHP 8.4.1 in backend.yml/e2e.yml; lint/test/build jobs run green.
Failure behavior: platform check failure or missing extensions cause workflow failure.

**(d) `test-e2e-caption.yml` MinIO + PHP + health check**

`test-e2e-caption.yml` combines the invalid MinIO image tag, PHP 8.3 (conflicting with `symfony/clock v8.1.9`), and health check `curl -f http://localhost:9000/minio/health/live`. All three must be corrected together so the caption E2E workflow can provision MinIO and run.

Success behavior: MinIO service starts, health check passes, PHP satisfies composer platform, caption E2E tests run green.
Failure behavior: any one of the three defects leaves the workflow red.

### 14.3 Observable Success / Failure Behavior

Success (all gates):
- `backend.yml`: composer install passes (PHP >= 8.4.1 satisfied), MinIO service healthy, lint + unit + feature + governance backend tests pass, `composer audit` clean.
- `e2e.yml`: composer install passes (PHP >= 8.4.1 satisfied), MinIO service healthy, E2E caption tests pass at required viewports.
- `frontend.yml`: Vitest unit tests pass, ESLint clean, TypeScript build succeeds.
- `governance.yml`: governance tests pass under pinned Python 3.12.7, all actions pinned, YAML valid.

Failure (any gate):
- Invalid MinIO tag → service container does not start → dependent tests error → workflow red.
- PHP 8.3 with `symfony/clock v8.1.9` locked → `composer install` platform mismatch → workflow aborts red.
- Any lint/test/build/audit failure → workflow red → blocks merge and issue closure.

Secrets: none introduced. Workflow files contain no OAuth tokens, refresh tokens, API secrets, or credentials. Encrypted token storage in the implementation is not weakened.