<?php

use App\Enums\SocialPublishStatus;
use App\Jobs\PublishToTikTok;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\SocialConnection;
use App\Models\SocialPublish;
use App\Models\User;
use App\Services\SocialConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
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

it('full_publish_flow_approved_clip_to_tiktok', function () {
    // Create connected TikTok connection
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);

    // Create approved clip review with completed render owned by test user
    $clipReview = $this->createPublishableClipReview();

    // Initiate publish
    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'title' => 'My TikTok Clip',
        'description' => 'Test description',
    ]);

    $response->assertStatus(202);
    $response->assertJsonPath('data.status', 'pending');

    // Verify SocialPublish row exists (job runs synchronously in testing)
    $this->assertDatabaseHas('social_publishes', [
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'status' => 'published',
        'title' => 'My TikTok Clip',
    ]);

    // List publishes
    $listResponse = $this->spaRequest('GET', '/api/v1/social/publishes', $this->cookies);
    $listResponse->assertOk();
    $listResponse->assertJsonCount(1, 'data');

    // Check status (job ran synchronously during the API request)
    $publish = SocialPublish::first();
    $statusResponse = $this->spaRequest('GET', "/api/v1/social/publishes/{$publish->id}", $this->cookies);
    $statusResponse->assertOk();
    $statusResponse->assertJsonPath('data.status', 'published');
    $statusResponse->assertJsonPath('data.platform_label', 'TikTok');
});

it('tiktok_publish_status_reflects_published_after_job', function () {
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);
    $clipReview = $this->createPublishableClipReview();

    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'title' => 'My Clip',
    ]);
    $response->assertStatus(202);

    $publish = SocialPublish::first();

    // Run job inline
    $job = new PublishToTikTok($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Published);
    expect($publish->external_video_id)->toBe('tiktok_video_12345');
    expect($publish->video_url)->toBe('https://www.tiktok.com/@testaccount/video/tiktok_video_12345');
});

it('tiktok_failed_publish_stores_error_message', function () {
    // Mock manager to return null token BEFORE the API request.
    // The job runs synchronously in testing, so the mock must be in place
    // when the controller dispatches the job.
    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturnNull();
    $this->app->instance(SocialConnectionManager::class, $manager);

    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);
    $clipReview = $this->createPublishableClipReview();

    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'title' => 'My Clip',
    ]);
    $response->assertStatus(202);

    $publish = SocialPublish::first();

    // Job already ran synchronously during the API request and failed
    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Failed);
    expect($publish->error_message)->not->toBeEmpty();
    // Verify no sensitive token VALUE is leaked
    expect(strtolower($publish->error_message))->not->toContain('access_token');
    expect(strtolower($publish->error_message))->not->toContain('refresh_token');
    expect($publish->error_message)->toBe('No valid access token available');
});

it('tiktok_duplicate_publish_blocked', function () {
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);
    $clipReview = $this->createPublishableClipReview();

    // First publish
    $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'title' => 'First',
    ])->assertStatus(202);

    // Second publish should be blocked
    $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'title' => 'Second',
    ])->assertStatus(409);
});

it('tiktok_publish_uses_encrypted_token_never_in_response', function () {
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);
    $clipReview = $this->createPublishableClipReview();

    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'title' => 'My Clip',
    ]);
    $response->assertStatus(202);

    $responseBody = $response->getContent();
    expect($responseBody)->not->toContain('access_token');
    expect($responseBody)->not->toContain('refresh_token');
});

it('tiktok_cross_user_publish_blocked', function () {
    $other = $this->createSecondUser();
    $connection = SocialConnection::factory()->for($other)->connected()->create(['platform' => 'tiktok']);
    $clipReview = ClipReview::factory()->for(
        DerivedAsset::factory()->renderedClip()
    )->create([
        'reviewer_id' => $other->id,
        'status' => 'approved',
    ]);

    $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'title' => 'Other User Clip',
    ])->assertNotFound();
});

it('tiktok_publish_without_tiktok_connection_blocked', function () {
    $clipReview = ClipReview::factory()->for(
        DerivedAsset::factory()->renderedClip()
    )->create([
        'reviewer_id' => $this->user->id,
        'status' => 'approved',
    ]);

    $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'title' => 'My Clip',
    ])->assertNotFound();
});
