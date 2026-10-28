<?php

namespace Tests\Unit\Enums;

use App\Enums\SocialPublishStatus;
use Tests\TestCase;

uses(TestCase::class);

it('defines pending status', function () {
    expect(SocialPublishStatus::Pending->value)->toBe('pending');
});

it('defines uploading status', function () {
    expect(SocialPublishStatus::Uploading->value)->toBe('uploading');
});

it('defines published status', function () {
    expect(SocialPublishStatus::Published->value)->toBe('published');
});

it('defines failed status', function () {
    expect(SocialPublishStatus::Failed->value)->toBe('failed');
});

it('has all four status cases', function () {
    $cases = SocialPublishStatus::cases();
    expect($cases)->toHaveCount(4);
    expect($cases)->toContain(SocialPublishStatus::Pending);
    expect($cases)->toContain(SocialPublishStatus::Uploading);
    expect($cases)->toContain(SocialPublishStatus::Published);
    expect($cases)->toContain(SocialPublishStatus::Failed);
});
