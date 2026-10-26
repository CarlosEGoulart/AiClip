<?php

namespace Tests\Unit\Contracts;

use App\Contracts\PublishResult;
use App\Contracts\PublishStatus;
use App\Contracts\SocialPublisher;
use Tests\TestCase;

uses(TestCase::class);

it('defines required interface methods', function () {
    $methods = [
        'getPlatform',
        'getPlatformLabel',
        'publishVideo',
        'getPublishStatus',
        'hasPublishPermission',
    ];

    foreach ($methods as $method) {
        expect(method_exists(SocialPublisher::class, $method))->toBeTrue();
    }
});

it('publish_result_has_required_properties', function () {
    $result = new PublishResult(
        externalVideoId: 'dQw4w9WgXcQ',
        videoUrl: 'https://youtu.be/dQw4w9WgXcQ',
        status: 'published',
        errorMessage: null,
    );

    expect($result->externalVideoId)->toBe('dQw4w9WgXcQ');
    expect($result->videoUrl)->toBe('https://youtu.be/dQw4w9WgXcQ');
    expect($result->status)->toBe('published');
    expect($result->errorMessage)->toBeNull();
});

it('publish_result_error_message_is_optional', function () {
    $result = new PublishResult(
        externalVideoId: 'abc',
        videoUrl: 'https://youtu.be/abc',
        status: 'processing',
    );

    expect($result->errorMessage)->toBeNull();
});

it('publish_status_has_required_properties', function () {
    $status = new PublishStatus(
        externalVideoId: 'dQw4w9WgXcQ',
        status: 'published',
        videoUrl: 'https://youtu.be/dQw4w9WgXcQ',
        errorMessage: null,
    );

    expect($status->externalVideoId)->toBe('dQw4w9WgXcQ');
    expect($status->status)->toBe('published');
    expect($status->videoUrl)->toBe('https://youtu.be/dQw4w9WgXcQ');
    expect($status->errorMessage)->toBeNull();
});

it('publish_status_error_message_is_optional', function () {
    $status = new PublishStatus(
        externalVideoId: 'abc',
        status: 'failed',
        errorMessage: 'Upload failed',
    );

    expect($status->videoUrl)->toBeNull();
    expect($status->errorMessage)->toBe('Upload failed');
});
