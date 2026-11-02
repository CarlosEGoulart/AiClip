<?php

namespace Tests\Feature\SocialPublish;

use App\Enums\SocialPublishStatus;
use App\Models\SocialPublish;
use App\Models\SocialPublishCampaign;
use Tests\TestCase;

uses(TestCase::class);

it('resource includes campaign status and per-target publishes', function () {
    $campaign = new SocialPublishCampaign;
    $campaign->id = 1;
    $campaign->user_id = 1;
    $campaign->clip_review_id = 1;
    $campaign->status = 'partially_published';
    $campaign->exists = true;

    $publish = new SocialPublish;
    $publish->id = 1;
    $publish->platform = 'youtube';
    $publish->status = SocialPublishStatus::Published->value;
    $publish->exists = true;

    $campaign->setRelation('publishes', collect([$publish]));

    $resource = new \App\Http\Resources\SocialPublishCampaignResource($campaign);
    $response = $resource->response(null);
    $content = json_decode($response->getContent(), true);

    expect($content['data']['id'])->toBe(1);
    expect($content['data']['status'])->toBe('partially_published');
    expect($content['data']['publishes'])->toHaveCount(1);
    expect($content['data']['publishes'][0]['platform'])->toBe('youtube');
    expect($content['data']['publishes'][0]['status'])->toBe('published');
});

it('resource does not include tokens', function () {
    $campaign = new SocialPublishCampaign;
    $campaign->id = 1;
    $campaign->user_id = 1;
    $campaign->clip_review_id = 1;
    $campaign->status = 'pending';
    $campaign->exists = true;

    $resource = new \App\Http\Resources\SocialPublishCampaignResource($campaign);
    $response = $resource->response(null);
    $content = json_decode($response->getContent(), true);

    expect(array_key_exists('access_token', $content['data']))->toBeFalse();
    expect(array_key_exists('refresh_token', $content['data']))->toBeFalse();
});

it('SocialPublish resource includes campaign_id when set', function () {
    $publish = new SocialPublish;
    $publish->id = 1;
    $publish->platform = 'youtube';
    $publish->status = SocialPublishStatus::Pending->value;
    $publish->campaign_id = 5;
    $publish->exists = true;

    $resource = new \App\Http\Resources\SocialPublishResource($publish);
    $response = $resource->response(null);
    $content = json_decode($response->getContent(), true);

    expect($content['data']['campaign_id'])->toBe(5);
});

it('collection wraps campaigns in data envelope', function () {
    $campaign = new SocialPublishCampaign;
    $campaign->id = 1;
    $campaign->user_id = 1;
    $campaign->clip_review_id = 1;
    $campaign->status = 'pending';
    $campaign->exists = true;

    $collection = new \App\Http\Resources\SocialPublishCampaignCollection(
        collect([$campaign])
    );

    $array = $collection->resolve();

    expect(array_key_exists('data', $array))->toBeTrue();
    expect($array['data'])->toHaveCount(1);
});
