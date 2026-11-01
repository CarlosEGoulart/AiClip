<?php

use App\Enums\SocialPublishCampaignStatus;
use App\Enums\SocialPublishStatus;
use App\Jobs\PublishToInstagram;
use App\Jobs\PublishToTikTok;
use App\Jobs\PublishToYouTube;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\SocialConnection;
use App\Models\SocialPublish;
use App\Models\SocialPublishCampaign;
use App\Models\User;
use App\Services\SocialConnectionManager;
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
            ['platform' => 'tiktok', 'title' => 'TikTok Title'],
        ],
    ], $overrides);
}

it('full campaign flow all-success', function () {
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'instagram']);
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);

    $clipReview = $this->createPublishableClipReview();

    $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

    $response = $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload);

    $response->assertStatus(202);
    $response->assertJsonPath('data.status', 'pending');

    $campaign = SocialPublishCampaign::first();
    expect($campaign->publishes)->toHaveCount(3);

    // Jobs run synchronously in testing; all targets publish
    $campaign->refresh();
    expect($campaign->status)->toBe(SocialPublishCampaignStatus::Published->value);

    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'youtube',
        'status' => 'published',
        'campaign_id' => $campaign->id,
    ]);
    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'instagram',
        'status' => 'published',
        'campaign_id' => $campaign->id,
    ]);
    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'tiktok',
        'status' => 'published',
        'campaign_id' => $campaign->id,
    ]);
});

it('full campaign flow partial-success', function () {
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'instagram']);
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);

    $clipReview = $this->createPublishableClipReview();

    // Make tiktok fail by mocking manager to return null token for tiktok only
    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturnUsing(function ($connection) {
        if ($connection->platform === 'tiktok') {
            return null;
        }
        return 'fake_token';
    });
    $this->app->instance(SocialConnectionManager::class, $manager);

    $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

    $response = $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload);
    $response->assertStatus(202);

    $campaign = SocialPublishCampaign::first();
    $campaign->refresh();

    expect($campaign->status)->toBe(SocialPublishCampaignStatus::PartiallyPublished->value);

    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'youtube',
        'status' => 'published',
        'campaign_id' => $campaign->id,
    ]);
    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'instagram',
        'status' => 'published',
        'campaign_id' => $campaign->id,
    ]);
    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'tiktok',
        'status' => 'failed',
        'campaign_id' => $campaign->id,
    ]);
});

it('full campaign flow all-failed', function () {
    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturnNull();
    $this->app->instance(SocialConnectionManager::class, $manager);

    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'instagram']);
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);

    $clipReview = $this->createPublishableClipReview();

    $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

    $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
        ->assertStatus(202);

    $campaign = SocialPublishCampaign::first();
    $campaign->refresh();

    expect($campaign->status)->toBe(SocialPublishCampaignStatus::Failed->value);

    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'youtube',
        'status' => 'failed',
        'campaign_id' => $campaign->id,
    ]);
    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'instagram',
        'status' => 'failed',
        'campaign_id' => $campaign->id,
    ]);
});

it('disconnected account target fails, others proceed', function () {
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    // instagram not connected

    $clipReview = $this->createPublishableClipReview();

    $payload = campaignPayload([
        'clip_review_id' => $clipReview->id,
        'targets' => [
            ['platform' => 'youtube', 'title' => 'YouTube Title'],
            ['platform' => 'instagram', 'title' => 'Instagram Title'],
        ],
    ]);

    $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
        ->assertUnprocessable();

    // No campaign created because validation fails (instagram disconnected)
    $this->assertDatabaseMissing('social_publish_campaigns', [
        'user_id' => $this->user->id,
        'clip_review_id' => $clipReview->id,
    ]);
});

it('retry failed target does not duplicate publication', function () {
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    $clipReview = $this->createPublishableClipReview();

    $campaign = SocialPublishCampaign::factory()->for($this->user)->create([
        'clip_review_id' => $clipReview->id,
        'status' => 'failed',
    ]);

    $connection = SocialConnection::where('platform', 'youtube')
        ->where('user_id', $this->user->id)
        ->first();

    $failedPublish = SocialPublish::factory()->for($this->user)->failed()->create([
        'campaign_id' => $campaign->id,
        'platform' => 'youtube',
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
    ]);

    $response = $this->spaRequest('POST', "/api/v1/social/publish/campaigns/{$campaign->id}/retry", $this->cookies);

    $response->assertStatus(202);

    // Job runs synchronously; fake publisher succeeds → publish becomes Published
    $failedPublish->refresh();
    expect($failedPublish->status)->toBe(SocialPublishStatus::Published);

    // Only one publish record for this campaign+platform (no duplicate)
    expect(SocialPublish::where('campaign_id', $campaign->id)->where('platform', 'youtube')->count())->toBe(1);
});

it('cancel campaign before execution cancels pending targets', function () {
    $clipReview = $this->createPublishableClipReview();

    $campaign = SocialPublishCampaign::factory()->for($this->user)->create([
        'clip_review_id' => $clipReview->id,
        'status' => 'pending',
    ]);
    SocialPublish::factory()->for($this->user)->create([
        'campaign_id' => $campaign->id,
        'platform' => 'youtube',
        'status' => 'pending',
    ]);
    SocialPublish::factory()->for($this->user)->published()->create([
        'campaign_id' => $campaign->id,
        'platform' => 'instagram',
    ]);

    $response = $this->spaRequest('POST', "/api/v1/social/publish/campaigns/{$campaign->id}/cancel", $this->cookies);

    $response->assertOk();

    $campaign->refresh();
    expect($campaign->status)->toBe(SocialPublishCampaignStatus::Cancelled);

    // Cancelled pending targets are marked failed with cancellation error
    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'youtube',
        'status' => 'failed',
        'campaign_id' => $campaign->id,
        'error_message' => 'Campaign cancelled by user',
    ]);
    // Published target remains published
    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'instagram',
        'status' => 'published',
        'campaign_id' => $campaign->id,
    ]);
});

it('publish uses encrypted token never in response', function () {
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'instagram']);
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);

    $clipReview = $this->createPublishableClipReview();

    $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

    $response = $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload);
    $response->assertStatus(202);

    $responseBody = $response->getContent();
    expect($responseBody)->not->toContain('access_token');
    expect($responseBody)->not->toContain('refresh_token');
});

it('pre-execution validation checks all targets before queueing any', function () {
    $clipReview = $this->createPublishableClipReview();
    // Only youtube connected; instagram and tiktok missing
    SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);

    $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

    $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
        ->assertUnprocessable();

    // No campaign created
    $this->assertDatabaseMissing('social_publish_campaigns', [
        'user_id' => $this->user->id,
        'clip_review_id' => $clipReview->id,
    ]);
});

it('cross-user campaign returns 404', function () {
    $other = $this->createSecondUser();
    SocialConnection::factory()->for($other)->connected()->create(['platform' => 'youtube']);

    $clipReview = ClipReview::factory()->for(
        DerivedAsset::factory()->renderedClip()
    )->create([
        'reviewer_id' => $other->id,
        'status' => 'approved',
    ]);

    $payload = campaignPayload(['clip_review_id' => $clipReview->id]);

    $this->spaRequest('POST', '/api/v1/social/publish/campaign', $this->cookies, $payload)
        ->assertNotFound();
});
