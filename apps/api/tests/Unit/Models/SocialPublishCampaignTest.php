<?php

namespace Tests\Unit\Models;

use App\Enums\SocialPublishCampaignStatus;
use App\Models\ClipReview;
use App\Models\SocialPublish;
use App\Models\SocialPublishCampaign;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

uses(TestCase::class);

it('has correct fillable attributes', function () {
    $model = new SocialPublishCampaign;

    $fillable = $model->getFillable();

    expect($fillable)->toContain('user_id');
    expect($fillable)->toContain('clip_review_id');
    expect($fillable)->toContain('status');
});

it('casts status to SocialPublishCampaignStatus', function () {
    $model = new SocialPublishCampaign;
    $model->status = 'pending';

    expect($model->status)->toBeInstanceOf(SocialPublishCampaignStatus::class);
    expect($model->status->value)->toBe('pending');
});

it('belongs to user', function () {
    $model = new SocialPublishCampaign;
    $relation = $model->user();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
});

it('belongs to clip review', function () {
    $model = new SocialPublishCampaign;
    $relation = $model->clipReview();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
});

it('has many publishes', function () {
    $model = new SocialPublishCampaign;
    $relation = $model->publishes();

    expect($relation)->toBeInstanceOf(HasMany::class);
});

describe('status scopes', function () {
    it('scopePending', function () {
        $sql = SocialPublishCampaign::query()->pending()->toSql();
        expect($sql)->toMatch("/where [`\"]status[`\"] = \?/");
    });

    it('scopeProcessing', function () {
        $sql = SocialPublishCampaign::query()->processing()->toSql();
        expect($sql)->toMatch("/where [`\"]status[`\"] = \?/");
    });

    it('scopePublished', function () {
        $sql = SocialPublishCampaign::query()->published()->toSql();
        expect($sql)->toMatch("/where [`\"]status[`\"] = \?/");
    });

    it('scopePartiallyPublished', function () {
        $sql = SocialPublishCampaign::query()->partiallyPublished()->toSql();
        expect($sql)->toMatch("/where [`\"]status[`\"] = \?/");
    });

    it('scopeFailed', function () {
        $sql = SocialPublishCampaign::query()->failed()->toSql();
        expect($sql)->toMatch("/where [`\"]status[`\"] = \?/");
    });

    it('scopeCancelled', function () {
        $sql = SocialPublishCampaign::query()->cancelled()->toSql();
        expect($sql)->toMatch("/where [`\"]status[`\"] = \?/");
    });
});

describe('SocialPublish campaign relationship', function () {
    it('SocialPublish belongs to campaign', function () {
        $model = new SocialPublish;
        $relation = $model->campaign();

        expect($relation)->toBeInstanceOf(BelongsTo::class);
    });

    it('SocialPublish scopeByCampaign', function () {
        $sql = SocialPublish::query()->byCampaign(1)->toSql();
        expect($sql)->toMatch("/where [`\"]campaign_id[`\"] = \?/");
    });
});

it('clipReview relationship returns ClipReview instance', function () {
    $model = new SocialPublishCampaign;
    $relation = $model->clipReview();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(ClipReview::class);
});
