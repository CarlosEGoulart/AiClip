<?php

namespace App\Services;

use App\Contracts\SocialConnectionProvider;
use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            'status' => 'connected',
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
     * Finds the pending connection by state (and platform) without requiring authentication.
     */
    public function handleCallback(
        string $platform,
        string $state,
        string $code
    ): HandleCallbackResult {
        // Find pending connection by state and platform (state is unique)
        $connection = SocialConnection::where('platform', $platform)
            ->where('pending_oauth_state', $state)
            ->where('pending_oauth_state_expires_at', '>', now())
            ->firstOrFail();

        $user = $connection->user; // Get user from connection

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
        $connection->status = 'connected';
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
            $connection->status = 'connected';
            $connection->last_error = null;
            $connection->save();

            Log::info('Social connection token refreshed', [
                'user_id' => $connection->user_id,
                'platform' => $connection->platform,
                'connection_id' => $connection->id,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->markRevoked($connection, 'Token refresh failed: '.$e->getMessage());

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

        $connection->status = 'revoked';
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
        if ($connection->status !== 'connected') {
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
        if ($connection->status !== 'connected') {
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
        $connection->status = 'expired';
        $connection->last_error = $reason;
        $connection->save();

        Log::warning('Social connection expired', [
            'connection_id' => $connection->id,
            'reason' => $reason,
        ]);
    }

    private function markRevoked(SocialConnection $connection, string $reason): void
    {
        $connection->status = 'revoked';
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
        return app(SocialConnectionProvider::class.':'.$platform);
    }
}
