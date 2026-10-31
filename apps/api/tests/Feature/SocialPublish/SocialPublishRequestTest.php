<?php

namespace Tests\Feature\SocialPublish;

use App\Http\Requests\PublishClipRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

it('publish_request_validates_clip_review_id', function () {
    $request = new PublishClipRequest();
    $rules = $request->rules();

    expect($rules['clip_review_id'])->toBeArray();
    expect($rules['clip_review_id'])->toContain('required');
    expect($rules['clip_review_id'])->toContain('integer');
});

it('publish_request_validates_platform', function () {
    $request = new PublishClipRequest();
    $rules = $request->rules();

    expect($rules['platform'])->toBeArray();
    expect($rules['platform'])->toContain('required');
    expect($rules['platform'])->toContain('in:youtube,instagram,tiktok');
});

it('publish_request_accepts_youtube_platform', function () {
    $request = new PublishClipRequest();
    $rules = $request->rules();

    expect($rules['platform'])->toContain('in:youtube,instagram,tiktok');
});

it('publish_request_accepts_instagram_platform', function () {
    $request = new PublishClipRequest();
    $rules = $request->rules();

    expect($rules['platform'])->toContain('in:youtube,instagram,tiktok');
});

it('publish_request_accepts_tiktok_platform', function () {
    $request = new PublishClipRequest();
    $rules = $request->rules();

    expect($rules['platform'])->toContain('in:youtube,instagram,tiktok');
});

it('publish_request_validates_title', function () {
    $request = new PublishClipRequest();
    $rules = $request->rules();

    expect($rules['title'])->toBeArray();
    expect($rules['title'])->toContain('required');
    expect($rules['title'])->toContain('string');
});

it('publish_request_accepts_optional_description', function () {
    $request = new PublishClipRequest();
    $rules = $request->rules();

    expect($rules['description'])->toBeArray();
    expect($rules['description'])->toContain('nullable');
    expect($rules['description'])->toContain('string');
});

it('publish_request_rejects_invalid_platform', function () {
    $request = new PublishClipRequest();
    $rules = $request->rules();

    $validator = Validator::make([
        'clip_review_id' => 1,
        'platform' => 'invalid',
        'title' => 'Test Clip',
    ], $rules);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('platform'))->toBeTrue();
});

it('publish_request_accepts_tiktok_platform_value', function () {
    $request = new PublishClipRequest();
    $rules = $request->rules();

    $validator = Validator::make([
        'clip_review_id' => 1,
        'platform' => 'tiktok',
        'title' => 'Test Clip',
    ], $rules);

    expect($validator->fails())->toBeFalse();
    expect($validator->errors()->has('platform'))->toBeFalse();
});

