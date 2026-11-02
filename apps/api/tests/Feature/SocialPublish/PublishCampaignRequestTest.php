<?php

namespace Tests\Feature\SocialPublish;

use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\SocialConnection;
use App\Models\User;
use App\Enums\ClipReviewStatus;
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

function validPayload(): array
{
    return [
        'clip_review_id' => 1,
        'targets' => [
            ['platform' => 'youtube', 'title' => 'YouTube Title'],
            ['platform' => 'instagram', 'title' => 'Instagram Title', 'description' => 'Desc'],
        ],
    ];
}

describe('POST /api/v1/social/publish/campaign validation', function () {
    it('valid targets array passes validation', function () {
        $clipReview = $this->createPublishableClipReview();
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'instagram']);

        $payload = validPayload();
        $payload['clip_review_id'] = $clipReview->id;

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertStatus(202);
    });

    it('single valid target passes validation', function () {
        $clipReview = $this->createPublishableClipReview();
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);

        $payload = validPayload();
        $payload['clip_review_id'] = $clipReview->id;
        $payload['targets'] = [['platform' => 'tiktok', 'title' => 'TikTok Title']];

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertStatus(202);
    });

    it('invalid platform in targets returns 422', function () {
        $clipReview = $this->createPublishableClipReview();

        $payload = validPayload();
        $payload['clip_review_id'] = $clipReview->id;
        $payload['targets'] = [['platform' => 'twitter', 'title' => 'Title']];

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertUnprocessable();
    });

    it('missing clip_review_id returns 422', function () {
        $payload = validPayload();
        unset($payload['clip_review_id']);

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertUnprocessable();
    });

    it('empty targets array returns 422', function () {
        $clipReview = $this->createPublishableClipReview();

        $payload = validPayload();
        $payload['clip_review_id'] = $clipReview->id;
        $payload['targets'] = [];

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertUnprocessable();
    });

    it('missing title in a target returns 422', function () {
        $clipReview = $this->createPublishableClipReview();

        $payload = validPayload();
        $payload['clip_review_id'] = $clipReview->id;
        $payload['targets'] = [['platform' => 'youtube']];

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertUnprocessable();
    });

    it('title longer than 255 chars returns 422', function () {
        $clipReview = $this->createPublishableClipReview();

        $payload = validPayload();
        $payload['clip_review_id'] = $clipReview->id;
        $payload['targets'] = [['platform' => 'youtube', 'title' => str_repeat('a', 256)]];

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertUnprocessable();
    });

    it('description present passes validation', function () {
        $clipReview = $this->createPublishableClipReview();
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);

        $payload = validPayload();
        $payload['clip_review_id'] = $clipReview->id;
        $payload['targets'] = [['platform' => 'youtube', 'title' => 'Title', 'description' => 'A description']];

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertStatus(202);
    });

    it('description omitted passes validation', function () {
        $clipReview = $this->createPublishableClipReview();
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);

        $payload = validPayload();
        $payload['clip_review_id'] = $clipReview->id;
        $payload['targets'] = [['platform' => 'youtube', 'title' => 'Title']];

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertStatus(202);
    });
});
