<?php

namespace App\Services;

use App\Contracts\ProviderUserProfile;
use App\Contracts\SocialConnectionProvider;
use App\Contracts\TokenResponse;

class FakeInstagramProvider implements SocialConnectionProvider
{
    public function getPlatform(): string
    {
        return 'instagram';
    }

    public function getPlatformLabel(): string
    {
        return 'Instagram';
    }

    public function getRequiredScopes(): array
    {
        return ['instagram_content_publish', 'instagram_basic'];
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => config('social.providers.instagram.client_id', 'fake_client_id'),
            'redirect_uri' => config('social.providers.instagram.redirect_uri', 'http://localhost/api/v1/social/instagram/callback'),
            'scope' => implode(',', $this->getRequiredScopes()),
            'response_type' => 'code',
            'state' => $state,
        ]);

        return 'https://api.instagram.com/oauth/authorize?'.$params;
    }

    public function exchangeCodeForTokens(string $code): TokenResponse
    {
        if ($code === 'invalid_code') {
            throw new \Exception('Invalid authorization code');
        }

        return new TokenResponse(
            accessToken: 'fake_instagram_access_token_'.$code,
            refreshToken: 'fake_instagram_refresh_token_'.$code,
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
            accessToken: 'fake_instagram_new_access_token',
            refreshToken: $refreshToken,
            expiresIn: 3600,
            scopes: $this->getRequiredScopes(),
        );
    }

    public function validateToken(string $accessToken): bool
    {
        return str_starts_with($accessToken, 'fake_instagram_access_token_');
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
            providerUserId: 'instagram_user_123',
            displayName: 'Test Instagram Account',
            avatarUrl: 'https://example.com/instagram_avatar.jpg',
            channelUrl: 'https://instagram.com/testaccount',
        );
    }
}
