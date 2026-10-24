<?php

namespace Tests\Unit\Services;

use App\Contracts\ProviderUserProfile;
use App\Contracts\SocialConnectionProvider;
use App\Contracts\TokenResponse;
use App\Services\FakeInstagramProvider;
use App\Services\FakeTikTokProvider;
use App\Services\FakeYouTubeProvider;
use Tests\TestCase;

uses(TestCase::class);

it('fake_youtube_provider_implements_interface', function () {
    $provider = new FakeYouTubeProvider;

    expect($provider)->toBeInstanceOf(SocialConnectionProvider::class);
    expect($provider->getPlatform())->toBe('youtube');
    expect($provider->getPlatformLabel())->toBe('YouTube');
});

it('fake_instagram_provider_implements_interface', function () {
    $provider = new FakeInstagramProvider;

    expect($provider)->toBeInstanceOf(SocialConnectionProvider::class);
    expect($provider->getPlatform())->toBe('instagram');
    expect($provider->getPlatformLabel())->toBe('Instagram');
});

it('fake_tiktok_provider_implements_interface', function () {
    $provider = new FakeTikTokProvider;

    expect($provider)->toBeInstanceOf(SocialConnectionProvider::class);
    expect($provider->getPlatform())->toBe('tiktok');
    expect($provider->getPlatformLabel())->toBe('TikTok');
});

it('fake_provider_get_authorization_url_includes_state', function () {
    $provider = new FakeYouTubeProvider;
    $state = 'test_state_1234567890123456789012345678901234567890';
    $url = $provider->getAuthorizationUrl($state);

    expect($url)->toContain('state='.$state);
});

it('fake_provider_exchange_code_returns_token_response', function () {
    $provider = new FakeYouTubeProvider;
    $response = $provider->exchangeCodeForTokens('valid_code');

    expect($response)->toBeInstanceOf(TokenResponse::class);
    expect($response->accessToken)->not->toBeEmpty();
    // refreshToken is nullable in TokenResponse contract
    expect($response->refreshToken)->not->toBeNull();
    expect($response->expiresIn)->toBeGreaterThan(0);
    expect($response->scopes)->toBeArray();
});

it('fake_provider_refresh_access_token_returns_token_response', function () {
    $provider = new FakeYouTubeProvider;
    $response = $provider->refreshAccessToken('valid_refresh_token');

    expect($response)->toBeInstanceOf(TokenResponse::class);
    expect($response->accessToken)->not->toBeEmpty();
    expect($response->expiresIn)->toBeGreaterThan(0);
});

it('fake_provider_validate_token_returns_boolean', function () {
    $provider = new FakeYouTubeProvider;

    expect($provider->validateToken('fake_youtube_access_token_valid'))->toBeTrue();
    expect($provider->validateToken('invalid_token'))->toBeFalse();
});

it('fake_provider_revoke_token_does_not_throw', function () {
    $provider = new FakeYouTubeProvider;

    expect(fn () => $provider->revokeToken('any_token'))->not->toThrow(\Exception::class);
});

it('fake_provider_get_user_profile_returns_profile', function () {
    $provider = new FakeYouTubeProvider;
    $profile = $provider->getUserProfile('fake_youtube_access_token_valid');

    expect($profile)->toBeInstanceOf(ProviderUserProfile::class);
    expect($profile->providerUserId)->not->toBeEmpty();
    expect($profile->displayName)->not->toBeEmpty();
});
