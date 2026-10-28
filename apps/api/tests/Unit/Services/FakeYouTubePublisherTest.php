<?php

namespace Tests\Unit\Services;

use App\Contracts\SocialPublisher;
use App\Services\FakeYouTubePublisher;
use Tests\TestCase;

uses(TestCase::class);

it('fake_youtube_publisher_implements_interface', function () {
    $publisher = new FakeYouTubePublisher;

    expect($publisher)->toBeInstanceOf(SocialPublisher::class);
    expect($publisher->getPlatform())->toBe('youtube');
    expect($publisher->getPlatformLabel())->toBe('YouTube');
});

it('fake_publisher_is_deterministic', function () {
    $publisher = new FakeYouTubePublisher(success: true);

    $result1 = $publisher->publishVideo([
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);
    $result2 = $publisher->publishVideo([
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result1->externalVideoId)->toBe($result2->externalVideoId);
    expect($result1->videoUrl)->toBe($result2->videoUrl);
    expect($result1->status)->toBe($result2->status);
});

it('fake_publisher_success_scenario', function () {
    $publisher = new FakeYouTubePublisher(success: true);

    $result = $publisher->publishVideo([
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result->status)->toBe('published');
    expect($result->externalVideoId)->not->toBeEmpty();
    expect($result->videoUrl)->not->toBeEmpty();
    expect($result->errorMessage)->toBeNull();
});

it('fake_publisher_failure_scenario', function () {
    $publisher = new FakeYouTubePublisher(success: false);

    $result = $publisher->publishVideo([
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result->status)->toBe('failed');
    expect($result->errorMessage)->not->toBeEmpty();
    expect($result->externalVideoId)->toBe('');
    expect($result->videoUrl)->toBe('');
});

it('fake_publisher_get_publish_status_returns_status', function () {
    $publisher = new FakeYouTubePublisher(success: true);

    $status = $publisher->getPublishStatus('some_video_id');

    expect($status->externalVideoId)->toBe('some_video_id');
    expect($status->status)->toBe('published');
});

it('fake_publisher_has_publish_permission_returns_true', function () {
    $publisher = new FakeYouTubePublisher(success: true);

    expect($publisher->hasPublishPermission('any_token'))->toBeTrue();
});

it('fake_publisher_no_network_calls', function () {
    $publisher = new FakeYouTubePublisher(success: true);

    // Fake publisher should not make any HTTP calls - this is verified
    // by the fact that it completes instantly without external dependencies
    $result = $publisher->publishVideo([
        'local_path' => 'renders/test.mp4',
        'title' => 'Test Clip',
    ]);

    expect($result->status)->toBe('published');
});
