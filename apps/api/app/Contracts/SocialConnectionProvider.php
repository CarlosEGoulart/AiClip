<?php

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
