<?php

namespace Tests\Feature\SocialPublish;

use App\Models\SocialPublish;
use App\Enums\SocialPublishStatus;
use Tests\TestCase;

uses(TestCase::class);

it('resource_includes_all_public_fields', function () {
    $publish = new SocialPublish;
    $publish->id = 1;
    $publish->user_id = 1;
    $publish->social_connection_id = 1;
    $publish->clip_review_id = 1;
    $publish->platform = 'youtube';
    $publish->status = SocialPublishStatus::Published->value;
    $publish->external_video_id = 'dQw4w9WgXcQ';
    $publish->video_url = 'https://youtu.be/dQw4w9WgXcQ';
    $publish->title = 'My Clip';
    $publish->description = 'A test clip';
    $publish->error_message = null;
    $publish->exists = true;

    $resource = new \App\Http\Resources\SocialPublishResource($publish);
    $response = $resource->response(null);
    $content = json_decode($response->getContent(), true);

    expect($content['data']['id'])->toBe(1);
    expect($content['data']['platform'])->toBe('youtube');
    expect($content['data']['platform_label'])->toBe('YouTube');
    expect($content['data']['status'])->toBe('published');
    expect($content['data']['external_video_id'])->toBe('dQw4w9WgXcQ');
    expect($content['data']['video_url'])->toBe('https://youtu.be/dQw4w9WgXcQ');
    expect($content['data']['title'])->toBe('My Clip');
    expect($content['data']['description'])->toBe('A test clip');
    expect($content['data']['error_message'])->toBeNull();
});

it('resource_tiktok_platform_label', function () {
    $publish = new SocialPublish;
    $publish->id = 2;
    $publish->user_id = 1;
    $publish->social_connection_id = 1;
    $publish->clip_review_id = 1;
    $publish->platform = 'tiktok';
    $publish->status = SocialPublishStatus::Published->value;
    $publish->external_video_id = 'tiktok_video_123';
    $publish->video_url = 'https://www.tiktok.com/@user/video/tiktok_video_123';
    $publish->title = 'TikTok Clip';
    $publish->description = null;
    $publish->error_message = null;
    $publish->exists = true;

    $resource = new \App\Http\Resources\SocialPublishResource($publish);
    $response = $resource->response(null);
    $content = json_decode($response->getContent(), true);

    expect($content['data']['platform'])->toBe('tiktok');
    expect($content['data']['platform_label'])->toBe('TikTok');
});

it('resource_does_not_include_tokens', function () {
    $publish = new SocialPublish;
    $publish->id = 1;
    $publish->platform = 'tiktok';
    $publish->status = SocialPublishStatus::Published->value;
    $publish->exists = true;

    $resource = new \App\Http\Resources\SocialPublishResource($publish);
    $response = $resource->response(null);
    $content = json_decode($response->getContent(), true);

    expect(array_key_exists('access_token', $content['data']))->toBeFalse();
    expect(array_key_exists('refresh_token', $content['data']))->toBeFalse();
});

it('collection_wraps_in_pagination_envelope', function () {
    $collection = new \App\Http\Resources\SocialPublishCollection(
        new \Illuminate\Pagination\LengthAwarePaginator(
            collect([new SocialPublish(['id' => 1, 'platform' => 'youtube', 'status' => 'pending'])]),
            1,
            15,
            1
        )
    );

    $array = $collection->resolve();

    expect(array_key_exists('data', $array))->toBeTrue();
    expect(array_key_exists('links', $array))->toBeTrue();
    expect(array_key_exists('meta', $array))->toBeTrue();
});
