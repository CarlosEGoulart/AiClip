<?php

namespace Tests\Unit\Contracts;

use App\Contracts\ProviderUserProfile;
use App\Contracts\SocialConnectionProvider;
use App\Contracts\TokenResponse;
use Tests\TestCase;

uses(TestCase::class);

it('defines required interface methods', function () {
    $methods = [
        'getPlatform',
        'getPlatformLabel',
        'getRequiredScopes',
        'getAuthorizationUrl',
        'exchangeCodeForTokens',
        'refreshAccessToken',
        'validateToken',
        'revokeToken',
        'getUserProfile',
    ];

    foreach ($methods as $method) {
        expect(method_exists(SocialConnectionProvider::class, $method))->toBeTrue();
    }
});

it('token_response_has_required_properties', function () {
    $token = new TokenResponse(
        accessToken: 'test_access_token',
        refreshToken: 'test_refresh_token',
        expiresIn: 3600,
        scopes: ['scope1', 'scope2']
    );

    expect($token->accessToken)->toBe('test_access_token');
    expect($token->refreshToken)->toBe('test_refresh_token');
    expect($token->expiresIn)->toBe(3600);
    expect($token->scopes)->toBe(['scope1', 'scope2']);
});

it('provider_user_profile_has_required_properties', function () {
    $profile = new ProviderUserProfile(
        providerUserId: 'user_123',
        displayName: 'Test User',
        avatarUrl: 'https://example.com/avatar.jpg',
        channelUrl: 'https://example.com/channel'
    );

    expect($profile->providerUserId)->toBe('user_123');
    expect($profile->displayName)->toBe('Test User');
    expect($profile->avatarUrl)->toBe('https://example.com/avatar.jpg');
    expect($profile->channelUrl)->toBe('https://example.com/channel');
});
