<?php

namespace Tests\Unit\Enums;

use App\Enums\SocialPublishCampaignStatus;
use Tests\TestCase;

uses(TestCase::class);

it('defines pending status', function () {
    expect(SocialPublishCampaignStatus::Pending->value)->toBe('pending');
});

it('defines processing status', function () {
    expect(SocialPublishCampaignStatus::Processing->value)->toBe('processing');
});

it('defines published status', function () {
    expect(SocialPublishCampaignStatus::Published->value)->toBe('published');
});

it('defines partially_published status', function () {
    expect(SocialPublishCampaignStatus::PartiallyPublished->value)->toBe('partially_published');
});

it('defines failed status', function () {
    expect(SocialPublishCampaignStatus::Failed->value)->toBe('failed');
});

it('defines cancelled status', function () {
    expect(SocialPublishCampaignStatus::Cancelled->value)->toBe('cancelled');
});

it('has all six status cases', function () {
    $cases = SocialPublishCampaignStatus::cases();

    expect($cases)->toHaveCount(6);
    expect($cases)->toContain(SocialPublishCampaignStatus::Pending);
    expect($cases)->toContain(SocialPublishCampaignStatus::Processing);
    expect($cases)->toContain(SocialPublishCampaignStatus::Published);
    expect($cases)->toContain(SocialPublishCampaignStatus::PartiallyPublished);
    expect($cases)->toContain(SocialPublishCampaignStatus::Failed);
    expect($cases)->toContain(SocialPublishCampaignStatus::Cancelled);
});

describe('label()', function () {
    it('returns Pending label for pending', function () {
        expect(SocialPublishCampaignStatus::Pending->label())->toBe('Pending');
    });

    it('returns Processing label for processing', function () {
        expect(SocialPublishCampaignStatus::Processing->label())->toBe('Processing');
    });

    it('returns Published label for published', function () {
        expect(SocialPublishCampaignStatus::Published->label())->toBe('Published');
    });

    it('returns Partially Published label for partially_published', function () {
        expect(SocialPublishCampaignStatus::PartiallyPublished->label())->toBe('Partially Published');
    });

    it('returns Failed label for failed', function () {
        expect(SocialPublishCampaignStatus::Failed->label())->toBe('Failed');
    });

    it('returns Cancelled label for cancelled', function () {
        expect(SocialPublishCampaignStatus::Cancelled->label())->toBe('Cancelled');
    });
});

describe('color()', function () {
    it('returns gray for pending', function () {
        expect(SocialPublishCampaignStatus::Pending->color())->toBe('gray');
    });

    it('returns blue for processing', function () {
        expect(SocialPublishCampaignStatus::Processing->color())->toBe('blue');
    });

    it('returns green for published', function () {
        expect(SocialPublishCampaignStatus::Published->color())->toBe('green');
    });

    it('returns yellow for partially_published', function () {
        expect(SocialPublishCampaignStatus::PartiallyPublished->color())->toBe('yellow');
    });

    it('returns red for failed', function () {
        expect(SocialPublishCampaignStatus::Failed->color())->toBe('red');
    });

    it('returns gray for cancelled', function () {
        expect(SocialPublishCampaignStatus::Cancelled->color())->toBe('gray');
    });
});
