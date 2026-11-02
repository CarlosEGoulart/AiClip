<?php

namespace Tests\Feature\SocialPublish;

use App\Enums\ClipReviewStatus;
use App\Enums\SocialPublishCampaignStatus;
use App\Enums\SocialPublishStatus;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\SocialConnection;
use App\Models\SocialPublish;
use App\Models\SocialPublishCampaign;
use App\Models\User;
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

function campaignPayload(array $overrides = []): array
{
    return array_merge([
        'clip_review_id' => 1,
        'targets' => [
            ['platform' => 'youtube', 'title' => 'YouTube Title'],
            ['platform' => 'instagram', 'title' => 'Instagram Title'],
        ],
    ], $overrides);
}

describe('POST /api/v1/social/publish/campaign (store)', function () {
    it('returns 401 for guest', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $cookies, campaignPayload())
            ->assertUnauthorized();
    });

    it('creates campaign with per-target pending publishes and queues jobs', function () {
        $clipReview = $this->createPublishableClipReview();
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'instagram']);

        $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

        $response = $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'data' => ['id', 'user_id', 'clip_review_id', 'status', 'publishes'],
        ]);
        $response->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('social_publish_campaigns', [
            'user_id' => $this->user->id,
            'clip_review_id' => $clipReview->id,
            'status' => 'published',
        ]);

        $campaign = SocialPublishCampaign::first();
        expect($campaign->publishes)->toHaveCount(2);
        expect($campaign->publishes->first()->status)->toBe(SocialPublishStatus::Pending);
        expect($campaign->publishes->first()->campaign_id)->toBe($campaign->id);
    });

    it('returns 422 when one target has no connected platform and queues nothing', function () {
        $clipReview = $this->createPublishableClipReview();
        // Only youtube connected, instagram missing
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);

        $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

        $response = $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload);

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('social_publish_campaigns', [
            'user_id' => $this->user->id,
            'clip_review_id' => $clipReview->id,
        ]);
    });

    it('returns 422 for invalid platform value', function () {
        $clipReview = $this->createPublishableClipReview();

        $payload = campaignPayload([
            'clip_review_id' => $clipReview->id,
            'targets' => [['platform' => 'twitter', 'title' => 'Title']],
        ]);

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertUnprocessable();
    });

    it('returns 404 for non-owned clip review', function () {
        $other = $this->createSecondUser();
        $clipReview = ClipReview::factory()->for(
            DerivedAsset::factory()->renderedClip()
        )->create([
            'reviewer_id' => $other->id,
            'status' => ClipReviewStatus::APPROVED,
        ]);

        $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertNotFound();
    });

    it('returns 404 for not-approved clip review', function () {
        $clipReview = ClipReview::factory()->for(
            DerivedAsset::factory()->renderedClip()
        )->create([
            'reviewer_id' => $this->user->id,
            'status' => ClipReviewStatus::PENDING,
        ]);

        $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertNotFound();
    });

    it('returns 404 when no completed rendered clip', function () {
        $clipReview = ClipReview::factory()->create([
            'reviewer_id' => $this->user->id,
            'status' => ClipReviewStatus::APPROVED,
        ]);

        $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
            ->assertNotFound();
    });

    it('requires CSRF token', function () {
        $clipReview = $this->createPublishableClipReview();
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);

        $cookies = array_diff_key($this->cookies, ['XSRF-TOKEN' => null]);

        $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

        $this->spaRequest('POST', '/api/v1/social/publish/campaign', $cookies, $payload)
            ->assertUnprocessable();
    });
});

describe('GET /api/v1/social/publish/campaigns (index)', function () {
    it('returns user-scoped campaign list', function () {
        $campaign = SocialPublishCampaign::factory()->for($this->user)->create([
            'clip_review_id' => $this->createPublishableClipReview()->id,
            'status' => 'pending',
        ]);

        $other = $this->createSecondUser();
        SocialPublishCampaign::factory()->for($other)->create([
            'clip_review_id' => $this->createPublishableClipReview()->id,
            'status' => 'pending',
        ]);

        $response = $this->spaRequest('GET', '/api/v1/social/publish/campaigns', $this->cookies);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    });
});

describe('GET /api/v1/social/publish/campaigns/{campaign} (show)', function () {
    it('returns campaign with per-target publishes for owner', function () {
        $campaign = SocialPublishCampaign::factory()->for($this->user)->create([
            'clip_review_id' => $this->createPublishableClipReview()->id,
            'status' => 'pending',
        ]);

        $response = $this->spaRequest('GET', "/api/v1/social/publish/campaigns/{$campaign->id}", $this->cookies);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'status',
                'publishes' => [
                    '*' => [
                        'id',
                        'status',
                    ],
                ],
            ],
        ]);
        $response->assertJsonPath('data.id', $campaign->id);
    });

    it('returns 404 for non-owned campaign', function () {
        $other = $this->createSecondUser();
        $campaign = SocialPublishCampaign::factory()->for($other)->create([
            'clip_review_id' => $this->createPublishableClipReview()->id,
            'status' => 'pending',
        ]);

        $this->spaRequest('GET', "/api/v1/social/publish/campaigns/{$campaign->id}", $this->cookies)
            ->assertNotFound();
    });
});

describe('POST /api/v1/social/publish/campaigns/{campaign}/cancel (cancel)', function () {
    it('cancels pending campaign and pending targets', function () {
        $campaign = SocialPublishCampaign::factory()->for($this->user)->create([
            'clip_review_id' => $this->createPublishableClipReview()->id,
            'status' => 'pending',
        ]);
        SocialPublish::factory()->for($this->user)->create([
            'campaign_id' => $campaign->id,
            'platform' => 'youtube',
            'status' => 'pending',
        ]);

        $response = $this->spaRequest('POST', "/api/v1/social/publish/campaigns/{$campaign->id}/cancel", $this->cookies);

        $response->assertOk();
        $campaign->refresh();
        expect($campaign->status)->toBe(SocialPublishCampaignStatus::Cancelled);
    });

    it('returns 404 for non-owned campaign', function () {
        $other = $this->createSecondUser();
        $campaign = SocialPublishCampaign::factory()->for($other)->create([
            'clip_review_id' => $this->createPublishableClipReview()->id,
            'status' => 'pending',
        ]);

        $this->spaRequest('POST', "/api/v1/social/publish/campaigns/{$campaign->id}/cancel", $this->cookies)
            ->assertNotFound();
    });
});

describe('POST /api/v1/social/publish/campaigns/{campaign}/retry (retry)', function () {
    it('retries failed targets with no duplicates', function () {
        $campaign = SocialPublishCampaign::factory()->for($this->user)->create([
            'clip_review_id' => $this->createPublishableClipReview()->id,
            'status' => 'failed',
        ]);
        $failedPublish = SocialPublish::factory()->for($this->user)->failed()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'youtube',
        ]);
        SocialPublish::factory()->for($this->user)->published()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'instagram',
        ]);

        $response = $this->spaRequest('POST', "/api/v1/social/publish/campaigns/{$campaign->id}/retry", $this->cookies);

        $response->assertStatus(202);
        $failedPublish->refresh();
        expect($failedPublish->status)->toBe(SocialPublishStatus::Failed);
    });

    it('returns 409 when no failed targets to retry', function () {
        $campaign = SocialPublishCampaign::factory()->for($this->user)->create([
            'clip_review_id' => $this->createPublishableClipReview()->id,
            'status' => 'published',
        ]);
        SocialPublish::factory()->for($this->user)->published()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'youtube',
        ]);

        $this->spaRequest('POST', "/api/v1/social/publish/campaigns/{$campaign->id}/retry", $this->cookies)
            ->assertStatus(409);
    });

    it('returns 404 for non-owned campaign', function () {
        $other = $this->createSecondUser();
        $campaign = SocialPublishCampaign::factory()->for($other)->create([
            'clip_review_id' => $this->createPublishableClipReview()->id,
            'status' => 'failed',
        ]);

        $this->spaRequest('POST', "/api/v1/social/publish/campaigns/{$campaign->id}/retry", $this->cookies)
            ->assertNotFound();
    });
});
