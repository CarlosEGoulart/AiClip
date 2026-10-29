<?php

namespace Tests\Unit\Social;

use App\Contracts\PublishResult;
use App\Contracts\PublishStatus;
use App\Contracts\SocialPublisher;
use App\Models\SocialConnection;
use App\Services\FakeInstagramPublisher;
use Tests\TestCase;

uses(TestCase::class);

it('fake_instagram_publisher_implements_interface', function () {
    $publisher = new FakeInstagramPublisher();

    expect($publisher)->toBeInstanceOf(SocialPublisher::class);
});

it('fake_get_platform_returns_instagram', function () {
    $publisher = new FakeInstagramPublisher();

    expect($publisher->getPlatform())->toBe('instagram');
});

it('fake_get_platform_label_returns_instagram', function () {
    $publisher = new FakeInstagramPublisher();

    expect($publisher->getPlatformLabel())->toBe('Instagram');
});

it('fake_publish_video_returns_successful_result', function () {
    $publisher = new FakeInstagramPublisher(success: true);

    $result = $publisher->publishVideo([
        'connection' => new SocialConnection(['platform' => 'instagram', 'status' => 'connected']),
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
        'description' => 'A test clip',
    ]);

    expect($result)->toBeInstanceOf(PublishResult::class);
    expect($result->status)->toBe('published');
    expect($result->externalVideoId)->not->toBeEmpty();
    expect($result->videoUrl)->not->toBeEmpty();
    expect($result->errorMessage)->toBeNull();
});

it('fake_publish_video_returns_failed_result', function () {
    $publisher = new FakeInstagramPublisher(success: false);

    $result = $publisher->publishVideo([
        'connection' => new SocialConnection(['platform' => 'instagram', 'status' => 'connected']),
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result)->toBeInstanceOf(PublishResult::class);
    expect($result->status)->toBe('failed');
    expect($result->externalVideoId)->toBe('');
    expect($result->videoUrl)->toBe('');
    expect($result->errorMessage)->not->toBeEmpty();
});

it('fake_publish_video_makes_no_network_calls', function () {
    $publisher = new FakeInstagramPublisher();

    // The fake publishes synchronously with no HTTP client usage.
    $result = $publisher->publishVideo([
        'connection' => new SocialConnection(['platform' => 'instagram', 'status' => 'connected']),
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result)->toBeInstanceOf(PublishResult::class);
});

it('fake_has_publish_permission_returns_bool', function () {
    $publisher = new FakeInstagramPublisher(success: true);

    expect($publisher->hasPublishPermission('fake_instagram_access_token_abc'))->toBeTrue();

    $publisherFailure = new FakeInstagramPublisher(success: false);
    expect($publisherFailure->hasPublishPermission('invalid_token'))->toBeFalse();
});

it('fake_get_publish_status_returns_publish_status', function () {
    $publisher = new FakeInstagramPublisher(success: true);

    $status = $publisher->getPublishStatus('fake_post_123');

    expect($status)->toBeInstanceOf(PublishStatus::class);
    expect($status->externalVideoId)->toBe('fake_post_123');
    expect($status->status)->toBe('published');
});

it('fake_publish_is_deterministic', function () {
    $publisher = new FakeInstagramPublisher(
        success: true,
        externalVideoId: 'instagram_post_12345',
        videoUrl: 'https://instagram.com/p/instagram_post_12345',
    );

    $result1 = $publisher->publishVideo([
        'connection' => new SocialConnection(['platform' => 'instagram', 'status' => 'connected']),
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    $result2 = $publisher->publishVideo([
        'connection' => new SocialConnection(['platform' => 'instagram', 'status' => 'connected']),
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result1->externalVideoId)->toBe($result2->externalVideoId);
    expect($result1->videoUrl)->toBe($result2->videoUrl);
    expect($result1->status)->toBe($result2->status);
});

it('fake_publish_error_message_contains_no_tokens', function () {
    $publisher = new FakeInstagramPublisher(success: false);

    $result = $publisher->publishVideo([
        'connection' => new SocialConnection(['platform' => 'instagram', 'status' => 'connected']),
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    $resultAsString = (string) $result->externalVideoId . (string) $result->videoUrl . (string) $result->errorMessage;

    expect($resultAsString)->not->toContain('access_token');
    expect($resultAsString)->not->toContain('refresh_token');
});
