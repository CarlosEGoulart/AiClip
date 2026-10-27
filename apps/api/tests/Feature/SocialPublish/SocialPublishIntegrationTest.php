<?php

use App\Enums\SocialPublishStatus;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\SocialConnection;
use App\Models\SocialPublish;
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

it('full_publish_flow_approved_clip_to_youtube', function () {
    // Create connected YouTube connection
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);

    // Create approved clip review with completed render owned by test user
    $clipReview = $this->createPublishableClipReview();

    // Initiate publish
    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'title' => 'My Clip',
        'description' => 'Test description',
    ]);

    $response->assertStatus(202);
    $response->assertJsonPath('data.status', 'pending');

    // Verify SocialPublish row exists
    $this->assertDatabaseHas('social_publishes', [
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'status' => 'pending',
        'title' => 'My Clip',
    ]);

    // List publishes
    $listResponse = $this->spaRequest('GET', '/api/v1/social/publishes', $this->cookies);
    $listResponse->assertOk();
    $listResponse->assertJsonCount(1, 'data');

    // Check status
    $publish = SocialPublish::first();
    $statusResponse = $this->spaRequest('GET', "/api/v1/social/publishes/{$publish->id}", $this->cookies);
    $statusResponse->assertOk();
    $statusResponse->assertJsonPath('data.status', 'pending');
});

it('publish_status_reflects_published_after_job', function () {
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    $clipReview = $this->createPublishableClipReview();

    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'title' => 'My Clip',
    ]);
    $response->assertStatus(202);

    $publish = SocialPublish::first();

    // Run job inline
    $job = new \App\Jobs\PublishToYouTube($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe('published'); // FakeYouTubePublisher succeeds
    expect($publish->external_video_id)->toBe('fake_video_id_12345');
    expect($publish->video_url)->toBe('https://youtu.be/fake_video_id_12345');
});

it('failed_publish_stores_error_message', function () {
    // Create connection but mock manager to return no token
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    $clipReview = $this->createPublishableClipReview();

    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'title' => 'My Clip',
    ]);
    $response->assertStatus(202);

    $publish = SocialPublish::first();

    // Mock manager to return null token
    $manager = \Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturnNull();
    $this->app->instance(SocialConnectionManager::class, $manager);

    $job = new \App\Jobs\PublishToYouTube($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe('failed');
    expect($publish->error_message)->not->toBeEmpty();
    // Verify no sensitive token VALUE is leaked (the word "token" alone is fine)
    expect(strtolower($publish->error_message))->not->toContain('access_token');
    expect(strtolower($publish->error_message))->not->toContain('refresh_token');
    expect($publish->error_message)->toBe('No valid access token available');
});

it('duplicate_publish_blocked', function () {
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    $clipReview = $this->createPublishableClipReview();

    // First publish
    $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'title' => 'First',
    ])->assertStatus(202);

    // Second publish should be blocked
    $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'title' => 'Second',
    ])->assertStatus(409);
});

it('publish_uses_encrypted_token_never_in_response', function () {
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    $clipReview = $this->createPublishableClipReview();

    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'title' => 'My Clip',
    ]);
    $response->assertStatus(202);

    $responseBody = $response->getContent();
    expect($responseBody)->not->toContain('access_token');
    expect($responseBody)->not->toContain('refresh_token');
});

it('publish_refreshes_expired_token', function () {
    // Create connection with expired token
    $connection = SocialConnection::factory()->for($this->user)->create([
        'platform' => 'youtube',
        'status' => 'connected',
        'access_token' => 'fake_youtube_access_token_expired',
        'refresh_token' => 'fake_youtube_refresh_token_valid',
        'access_token_expires_at' => now()->subHours(1),
    ]);

    $clipReview = $this->createPublishableClipReview();

    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'title' => 'My Clip',
    ]);
    $response->assertStatus(202);

    $publish = SocialPublish::first();

    // Run job - should refresh token via FakeYouTubeProvider
    $job = new \App\Jobs\PublishToYouTube($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe('published');
});

it('cross_user_publish_blocked', function () {
    $other = $this->createSecondUser();
    $connection = SocialConnection::factory()->for($other)->connected()->create(['platform' => 'youtube']);
    $clipReview = ClipReview::factory()->for(
        DerivedAsset::factory()->renderedClip()
    )->create([
        'reviewer_id' => $other->id,
        'status' => 'approved',
    ]);

    $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'title' => 'Other User Clip',
    ])->assertNotFound();
});

it('publish_without_youtube_connection_blocked', function () {
    $clipReview = ClipReview::factory()->for(
        DerivedAsset::factory()->renderedClip()
    )->create([
        'reviewer_id' => $this->user->id,
        'status' => 'approved',
    ]);

    $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'title' => 'My Clip',
    ])->assertNotFound();
});
