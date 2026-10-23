<?php

namespace Tests\Feature\Social;

use App\Http\Resources\SocialConnectionCollection;
use App\Http\Resources\SocialConnectionResource;
use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->connection = SocialConnection::factory()->for($this->user)->connected()->create([
        'platform' => 'youtube',
        'display_name' => 'Test Channel',
        'avatar_url' => 'https://example.com/avatar.jpg',
        'channel_url' => 'https://youtube.com/@test',
    ]);
});

it('resource_includes_all_public_fields', function () {
    $resource = new SocialConnectionResource($this->connection);
    $response = $resource->toArray(Request::create('/api/v1/social/connections'));

    expect($response)->toHaveKeys([
        'id',
        'platform',
        'platform_label',
        'display_name',
        'avatar_url',
        'channel_url',
        'status',
        'last_error',
        'created_at',
        'updated_at',
    ]);
});

it('resource_excludes_tokens_and_state', function () {
    $resource = new SocialConnectionResource($this->connection);
    $response = $resource->toArray(Request::create('/api/v1/social/connections'));

    expect($response)->not->toHaveKey('access_token');
    expect($response)->not->toHaveKey('refresh_token');
    expect($response)->not->toHaveKey('pending_oauth_state');
    expect($response)->not->toHaveKey('pending_oauth_state_expires_at');
});

it('resource_maps_platform_labels', function () {
    $youtube = SocialConnection::factory()->for($this->user)->create(['platform' => 'youtube']);
    $instagram = SocialConnection::factory()->for($this->user)->create(['platform' => 'instagram']);
    $tiktok = SocialConnection::factory()->for($this->user)->create(['platform' => 'tiktok']);
    $unknown = SocialConnection::factory()->for($this->user)->create(['platform' => 'unknown']);

    expect((new SocialConnectionResource($youtube))->toArray(Request::create('/'))['platform_label'])->toBe('YouTube');
    expect((new SocialConnectionResource($instagram))->toArray(Request::create('/'))['platform_label'])->toBe('Instagram');
    expect((new SocialConnectionResource($tiktok))->toArray(Request::create('/'))['platform_label'])->toBe('TikTok');
    expect((new SocialConnectionResource($unknown))->toArray(Request::create('/'))['platform_label'])->toBe('Unknown');
});

it('collection_wraps_in_pagination_envelope', function () {
    $connections = SocialConnection::factory()->for($this->user)->count(5)->connected()->create();
    $paginator = new LengthAwarePaginator($connections, 5, 15);

    $collection = new SocialConnectionCollection($paginator);
    $response = $collection->toArray(Request::create('/api/v1/social/connections'));

    expect($response)->toHaveKeys(['data', 'links', 'meta']);
    expect($response['data'])->toHaveCount(5);
    expect($response['meta']['current_page'])->toBe(1);
    expect($response['meta']['per_page'])->toBe(15);
    expect($response['meta']['total'])->toBe(5);
});
