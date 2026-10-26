<?php

namespace Tests\Unit\Models;

use App\Enums\SocialPublishStatus;
use App\Models\SocialPublish;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tests\TestCase;

uses(TestCase::class);

it('has correct fillable attributes', function () {
    $model = new SocialPublish;

    $fillable = $model->getFillable();

    expect($fillable)->toContain('user_id');
    expect($fillable)->toContain('social_connection_id');
    expect($fillable)->toContain('clip_review_id');
    expect($fillable)->toContain('platform');
    expect($fillable)->toContain('status');
    expect($fillable)->toContain('external_video_id');
    expect($fillable)->toContain('video_url');
    expect($fillable)->toContain('title');
    expect($fillable)->toContain('description');
    expect($fillable)->toContain('error_message');
});

it('has pending scope', function () {
    $scopedQuery = SocialPublish::query()->pending();
    $sql = $scopedQuery->toSql();

    expect($sql)->toMatch("/where [`\"]status[`\"] = \?/");
});

it('has uploading scope', function () {
    $scopedQuery = SocialPublish::query()->uploading();
    $sql = $scopedQuery->toSql();

    expect($sql)->toMatch("/where [`\"]status[`\"] = \?/");
});

it('has published scope', function () {
    $scopedQuery = SocialPublish::query()->published();
    $sql = $scopedQuery->toSql();

    expect($sql)->toMatch("/where [`\"]status[`\"] = \?/");
});

it('has failed scope', function () {
    $scopedQuery = SocialPublish::query()->failed();
    $sql = $scopedQuery->toSql();

    expect($sql)->toMatch("/where [`\"]status[`\"] = \?/");
});

it('has platform scope', function () {
    $scopedQuery = SocialPublish::query()->platform('youtube');
    $sql = $scopedQuery->toSql();

    expect($sql)->toMatch("/where [`\"]platform[`\"] = \?/");
});

it('belongs to user', function () {
    $model = new SocialPublish;
    $relation = $model->user();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
});

it('belongs to social connection', function () {
    $model = new SocialPublish;
    $relation = $model->socialConnection();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
});

it('belongs to clip review', function () {
    $model = new SocialPublish;
    $relation = $model->clipReview();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
});
