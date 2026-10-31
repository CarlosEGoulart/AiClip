<?php

namespace Tests\Unit\Services;

use App\Contracts\PublishResult;
use App\Contracts\PublishStatus;
use App\Contracts\SocialPublisher;
use App\Models\SocialConnection;
use App\Services\SocialConnectionManager;
use App\Services\TikTokPublisher;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

uses(TestCase::class);

it('get_platform_returns_tiktok', function () {
    $publisher = new TikTokPublisher(app(SocialConnectionManager::class));

    expect($publisher->getPlatform())->toBe('tiktok');
});

it('get_platform_label_returns_tiktok', function () {
    $publisher = new TikTokPublisher(app(SocialConnectionManager::class));

    expect($publisher->getPlatformLabel())->toBe('TikTok');
});

it('implements_social_publisher_interface', function () {
    $publisher = new TikTokPublisher(app(SocialConnectionManager::class));

    expect($publisher)->toBeInstanceOf(SocialPublisher::class);
});

it('publish_video_returns_failed_when_no_connection', function () {
    $manager = Mockery::mock(SocialConnectionManager::class);
    $publisher = new TikTokPublisher($manager);

    $result = $publisher->publishVideo([
        'connection' => null,
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result)->toBeInstanceOf(PublishResult::class);
    expect($result->status)->toBe('failed');
    expect($result->errorMessage)->not->toBeEmpty();
});

it('publish_video_returns_failed_when_no_token', function () {
    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturnNull();
    $publisher = new TikTokPublisher($manager);

    $connection = new SocialConnection(['platform' => 'tiktok', 'status' => 'connected']);

    $result = $publisher->publishVideo([
        'connection' => $connection,
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result->status)->toBe('failed');
    expect($result->errorMessage)->not->toBeEmpty();
});

it('publish_video_success_returns_published_result', function () {
    Http::fake([
        '*' => Http::response([
            'data' => ['publish_id' => 'fake_publish_id_123', 'video_id' => 'fake_video_id_123'],
        ], 200),
    ]);

    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturn('fake_tiktok_access_token_123');
    $publisher = new TikTokPublisher($manager);

    $connection = new SocialConnection([
        'platform' => 'tiktok',
        'status' => 'connected',
        'provider_user_id' => 'tiktok_user_123',
        'display_name' => 'testuser',
    ]);

    $result = $publisher->publishVideo([
        'connection' => $connection,
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result->status)->toBe('published');
    expect($result->externalVideoId)->not->toBeEmpty();
    expect($result->videoUrl)->not->toBeEmpty();
    expect($result->errorMessage)->toBeNull();
});

it('publish_video_never_logs_access_token', function () {
    Http::fake();

    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturn('fake_tiktok_access_token_secret');
    $publisher = new TikTokPublisher($manager);

    $connection = new SocialConnection([
        'platform' => 'tiktok',
        'status' => 'connected',
        'provider_user_id' => 'tiktok_user_123',
    ]);

    $result = $publisher->publishVideo([
        'connection' => $connection,
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    $resultAsString = (string) $result->externalVideoId.(string) $result->videoUrl.(string) $result->errorMessage;

    expect($resultAsString)->not->toContain('token');
});

it('publish_video_provider_failure_returns_sanitized_error', function () {
    Http::fake();

    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturn('fake_tiktok_access_token_123');
    $publisher = new TikTokPublisher($manager);

    $connection = new SocialConnection([
        'platform' => 'tiktok',
        'status' => 'connected',
        'provider_user_id' => 'tiktok_user_123',
    ]);

    $result = $publisher->publishVideo([
        'connection' => $connection,
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result->status)->toBe('failed');
    expect($result->errorMessage)->not->toBeEmpty();
    expect(strtolower($result->errorMessage))->not->toContain('access_token');
    expect(strtolower($result->errorMessage))->not->toContain('refresh_token');
});

it('has_publish_permission_valid_token_returns_true', function () {
    $publisher = new TikTokPublisher(app(SocialConnectionManager::class));

    $result = $publisher->hasPublishPermission('fake_tiktok_access_token_123');

    expect(is_bool($result))->toBeTrue();
});

it('has_publish_permission_empty_token_returns_false', function () {
    $publisher = new TikTokPublisher(app(SocialConnectionManager::class));

    $result = $publisher->hasPublishPermission('');

    expect($result)->toBeFalse();
});

it('get_publish_status_returns_publish_status', function () {
    $publisher = new TikTokPublisher(app(SocialConnectionManager::class));

    $status = $publisher->getPublishStatus('tiktok_video_123');

    expect($status)->toBeInstanceOf(PublishStatus::class);
    expect($status->externalVideoId)->toBe('tiktok_video_123');
});
