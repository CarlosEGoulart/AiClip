<?php

namespace Tests\Unit\Social;

use App\Contracts\PublishResult;
use App\Contracts\PublishStatus;
use App\Contracts\SocialPublisher;
use App\Models\SocialConnection;
use App\Services\InstagramPublisher;
use App\Services\SocialConnectionManager;
use Mockery;
use Tests\TestCase;

uses(TestCase::class);

it('get_platform_returns_instagram', function () {
    $publisher = new InstagramPublisher(app(SocialConnectionManager::class));

    expect($publisher->getPlatform())->toBe('instagram');
});

it('get_platform_label_returns_instagram', function () {
    $publisher = new InstagramPublisher(app(SocialConnectionManager::class));

    expect($publisher->getPlatformLabel())->toBe('Instagram');
});

it('implements_social_publisher_interface', function () {
    $publisher = new InstagramPublisher(app(SocialConnectionManager::class));

    expect($publisher)->toBeInstanceOf(SocialPublisher::class);
});

it('publish_video_returns_failed_when_no_connection', function () {
    $manager = Mockery::mock(SocialConnectionManager::class);
    $publisher = new InstagramPublisher($manager);

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
    $publisher = new InstagramPublisher($manager);

    $connection = new SocialConnection(['platform' => 'instagram', 'status' => 'connected']);

    $result = $publisher->publishVideo([
        'connection' => $connection,
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result->status)->toBe('failed');
    expect($result->errorMessage)->not->toBeEmpty();
});

it('publish_video_does_not_leak_token', function () {
    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturn('fake_instagram_token_123');
    $publisher = new InstagramPublisher($manager);

    $connection = new SocialConnection(['platform' => 'instagram', 'status' => 'connected']);

    $result = $publisher->publishVideo([
        'connection' => $connection,
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    $resultAsString = (string) $result->externalVideoId . (string) $result->videoUrl . (string) $result->errorMessage;

    expect($resultAsString)->not->toContain('token');
});

it('get_publish_status_returns_publish_status', function () {
    $publisher = new InstagramPublisher(app(SocialConnectionManager::class));

    $status = $publisher->getPublishStatus('instagram_post_123');

    expect($status)->toBeInstanceOf(PublishStatus::class);
    expect($status->externalVideoId)->toBe('instagram_post_123');
});

it('has_publish_permission_returns_boolean', function () {
    $publisher = new InstagramPublisher(app(SocialConnectionManager::class));

    $result = $publisher->hasPublishPermission('fake_instagram_access_token');

    expect(is_bool($result))->toBeTrue();
});
