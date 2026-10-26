<?php

namespace Tests\Feature\SocialPublish;

use App\Models\SocialPublish;
use App\Models\User;
use App\Enums\SocialPublishStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesTestUsers;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class, CreatesTestUsers::class);

beforeEach(function () {
    $userData = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    $cookies = $this->csrfCookies();

    $this->spaRequest('POST', '/api/v1/auth/register', $cookies, $userData)
        ->assertStatus(201);

    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, [
        'email' => $userData['email'],
        'password' => $userData['password'],
    ])->assertOk();

    $this->cookies = $cookies;
    $this->user = User::where('email', $userData['email'])->first();
});

describe('Authentication & Authorization', function () {
    it('publish_returns_401_for_guest', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('POST', '/api/v1/social/publish', $cookies, [
            'clip_review_id' => 1,
            'platform' => 'youtube',
            'title' => 'Test',
        ])->assertUnauthorized();
    });

    it('list_returns_401_for_guest', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('GET', '/api/v1/social/publishes', $cookies)
            ->assertUnauthorized();
    });

    it('status_returns_401_for_guest', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('GET', '/api/v1/social/publishes/1', $cookies)
            ->assertUnauthorized();
    });
});

describe('POST /api/v1/social/publish (Initiate)', function () {
    it('publish_returns_202_on_success', function () {
        $connection = \App\Models\SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
        $clipReview = \App\Models\ClipReview::factory()->for(\App\Models\DerivedAsset::factory()->renderedClip())->create([
            'reviewer_id' => $this->user->id,
            'status' => 'approved',
        ]);

        $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
            'clip_review_id' => $clipReview->id,
            'platform' => 'youtube',
            'title' => 'My Clip',
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'data' => ['publish_id', 'status', 'platform'],
        ]);
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.platform', 'youtube');
    });

    it('publish_requires_approved_clip_review', function () {
        $connection = \App\Models\SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
        $clipReview = \App\Models\ClipReview::factory()->for(\App\Models\DerivedAsset::factory()->renderedClip())->create([
            'reviewer_id' => $this->user->id,
            'status' => 'pending',
        ]);

        $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
            'clip_review_id' => $clipReview->id,
            'platform' => 'youtube',
            'title' => 'My Clip',
        ])->assertNotFound();
    });

    it('publish_requires_connected_youtube', function () {
        $clipReview = \App\Models\ClipReview::factory()->for(\App\Models\DerivedAsset::factory()->renderedClip())->create([
            'reviewer_id' => $this->user->id,
            'status' => 'approved',
        ]);

        $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
            'clip_review_id' => $clipReview->id,
            'platform' => 'youtube',
            'title' => 'My Clip',
        ])->assertNotFound();
    });

    it('publish_blocks_duplicate', function () {
        $connection = \App\Models\SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
        $clipReview = \App\Models\ClipReview::factory()->for(\App\Models\DerivedAsset::factory()->renderedClip())->create([
            'reviewer_id' => $this->user->id,
            'status' => 'approved',
        ]);

        SocialPublish::factory()->for($this->user)->create([
            'social_connection_id' => $connection->id,
            'clip_review_id' => $clipReview->id,
            'platform' => 'youtube',
            'status' => 'pending',
            'title' => 'Existing',
        ]);

        $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
            'clip_review_id' => $clipReview->id,
            'platform' => 'youtube',
            'title' => 'Duplicate',
        ])->assertStatus(409);
    });

    it('publish_requires_csrf_token', function () {
        $cookies = array_diff_key($this->cookies, ['XSRF-TOKEN' => null]);

        $this->spaRequest('POST', '/api/v1/social/publish', $cookies, [
            'clip_review_id' => 1,
            'platform' => 'youtube',
            'title' => 'Test',
        ])->assertStatus(419);
    });
});

describe('GET /api/v1/social/publishes (List)', function () {
    it('list_returns_empty_array_when_no_publishes', function () {
        $response = $this->spaRequest('GET', '/api/v1/social/publishes', $this->cookies);

        $response->assertOk();
        $response->assertJson(['data' => []]);
    });

    it('list_returns_user_publishes', function () {
        SocialPublish::factory()->for($this->user)->published()->create(['platform' => 'youtube']);

        $response = $this->spaRequest('GET', '/api/v1/social/publishes', $this->cookies);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    });

    it('list_returns_only_own_publishes', function () {
        $other = $this->createSecondUser();
        SocialPublish::factory()->for($this->user)->published()->create(['platform' => 'youtube']);
        SocialPublish::factory()->for($other)->published()->create(['platform' => 'youtube']);

        $response = $this->spaRequest('GET', '/api/v1/social/publishes', $this->cookies);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    });
});

describe('GET /api/v1/social/publishes/{publish} (Status)', function () {
    it('status_returns_publish_details', function () {
        $publish = SocialPublish::factory()->for($this->user)->published()->create(['platform' => 'youtube']);

        $response = $this->spaRequest('GET', "/api/v1/social/publishes/{$publish->id}", $this->cookies);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['id', 'platform', 'platform_label', 'status', 'external_video_id', 'video_url', 'title'],
        ]);
    });

    it('status_returns_404_for_other_user', function () {
        $other = $this->createSecondUser();
        $publish = SocialPublish::factory()->for($other)->published()->create(['platform' => 'youtube']);

        $this->spaRequest('GET', "/api/v1/social/publishes/{$publish->id}", $this->cookies)
            ->assertNotFound();
    });
});
