<?php

namespace App\Services;

use App\Contracts\ProviderUserProfile;
use App\Contracts\SocialConnectionProvider;
use App\Contracts\TokenResponse;

class FakeYouTubeProvider implements SocialConnectionProvider
{
    public function getPlatform(): string
    {
        return 'youtube';
    }

    public function getPlatformLabel(): string
    {
        return 'YouTube';
    }

    public function getRequiredScopes(): array
    {
        return ['youtube.upload', 'youtube.readonly'];
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => config('social.providers.youtube.client_id', 'fake_client_id'),
            'redirect_uri' => config('social.providers.youtube.redirect_uri', 'http://localhost/api/v1/social/youtube/callback'),
            'scope' => implode(' ', $this->getRequiredScopes()),
            'response_type' => 'code',
            'access_type' => 'offline',
            'state' => $state,
            'prompt' => 'consent',
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$params;
    }

    public function exchangeCodeForTokens(string $code): TokenResponse
    {
        if ($code === 'invalid_code') {
            throw new \Exception('Invalid authorization code');
        }

        return new TokenResponse(
            accessToken: 'fake_youtube_access_token_'.$code,
            refreshToken: 'fake_youtube_refresh_token_'.$code,
            expiresIn: 3600,
            scopes: $this->getRequiredScopes(),
        );
    }

    public function refreshAccessToken(string $refreshToken): TokenResponse
    {
        if ($refreshToken === 'invalid_refresh_token') {
            throw new \Exception('Invalid refresh token');
        }

        return new TokenResponse(
            accessToken: 'fake_youtube_new_access_token',
            refreshToken: $refreshToken,
            expiresIn: 3600,
            scopes: $this->getRequiredScopes(),
        );
    }

    public function validateToken(string $accessToken): bool
    {
        return str_starts_with($accessToken, 'fake_youtube_access_token_');
    }

    public function revokeToken(string $accessToken): void
    {
        // Best effort - no-op for fake provider
    }

    public function getUserProfile(string $accessToken): ProviderUserProfile
    {
        if (! $this->validateToken($accessToken)) {
            throw new \Exception('Invalid access token');
        }

        return new ProviderUserProfile(
            providerUserId: 'youtube_user_123',
            displayName: 'Test YouTube Channel',
            avatarUrl: 'https://example.com/youtube_avatar.jpg',
            channelUrl: 'https://youtube.com/@testchannel',
        );
    }
}
