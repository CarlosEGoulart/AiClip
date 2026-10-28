<?php

use App\Enums\SocialPublishStatus;
use App\Jobs\PublishToInstagram;
use App\Jobs\PublishToYouTube;
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

    // Verify SocialPublish row exists (job runs synchronously in testing)
    $this->assertDatabaseHas('social_publishes', [
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'status' => 'published',
        'title' => 'My Clip',
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
    $job = new PublishToYouTube($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Published);
    expect($publish->external_video_id)->toBe('fake_video_id_12345');
    expect($publish->video_url)->toBe('https://youtu.be/fake_video_id_12345');
});

it('failed_publish_stores_error_message', function () {
    // Mock manager to return null token BEFORE the API request.
    // The job runs synchronously in testing, so the mock must be in place
    // when the controller dispatches the job.
    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturnNull();
    $this->app->instance(SocialConnectionManager::class, $manager);

    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    $clipReview = $this->createPublishableClipReview();

    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'title' => 'My Clip',
    ]);
    $response->assertStatus(202);

    $publish = SocialPublish::first();

    // Job already ran synchronously during the API request and failed
    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Failed);
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
        'access_token' => encrypt('fake_youtube_access_token_expired'),
        'refresh_token' => encrypt('fake_youtube_refresh_token_valid'),
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
    $job = new PublishToYouTube($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Published);
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

it('full_publish_flow_approved_clip_to_instagram', function () {
    // Create connected Instagram connection
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'instagram']);

    // Create approved clip review with completed render owned by test user
    $clipReview = $this->createPublishableClipReview();

    // Initiate publish
    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'instagram',
        'title' => 'My Instagram Clip',
        'description' => 'Test description',
    ]);

    $response->assertStatus(202);
    $response->assertJsonPath('data.status', 'pending');

    // Verify SocialPublish row exists (job runs synchronously in testing)
    $this->assertDatabaseHas('social_publishes', [
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'instagram',
        'status' => 'published',
        'title' => 'My Instagram Clip',
    ]);

    // List publishes
    $listResponse = $this->spaRequest('GET', '/api/v1/social/publishes', $this->cookies);
    $listResponse->assertOk();
    $listResponse->assertJsonCount(1, 'data');
});

it('youtube_and_instagram_both_publishable', function () {
    // Create connected connections for both platforms
    $ytConnection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    $igConnection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'instagram']);

    // Create two approved clip reviews
    $clipReview1 = $this->createPublishableClipReview();
    $clipReview2 = $this->createPublishableClipReview();

    // Publish to YouTube
    $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview1->id,
        'platform' => 'youtube',
        'title' => 'YouTube Clip',
    ])->assertStatus(202);

    // Publish to Instagram
    $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview2->id,
        'platform' => 'instagram',
        'title' => 'Instagram Clip',
    ])->assertStatus(202);

    // Verify both publishes exist
    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'youtube',
        'title' => 'YouTube Clip',
    ]);
    $this->assertDatabaseHas('social_publishes', [
        'platform' => 'instagram',
        'title' => 'Instagram Clip',
    ]);
});

it('publish_without_instagram_connection_blocked', function () {
    $clipReview = ClipReview::factory()->for(
        DerivedAsset::factory()->renderedClip()
    )->create([
        'reviewer_id' => $this->user->id,
        'status' => 'approved',
    ]);

    $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'instagram',
        'title' => 'My Clip',
    ])->assertNotFound();
});

it('instagram_publish_status_reflects_published_after_job', function () {
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'instagram']);
    $clipReview = $this->createPublishableClipReview();

    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'instagram',
        'title' => 'My Clip',
    ]);
    $response->assertStatus(202);

    $publish = SocialPublish::first();

    // Run job inline
    $job = new PublishToInstagram($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Published);
    expect($publish->external_video_id)->toBe('instagram_post_12345');
    expect($publish->video_url)->toBe('https://instagram.com/p/instagram_post_12345');
});

it('instagram_failed_publish_stores_error_message', function () {
    // Mock manager to return null token BEFORE the API request.
    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturnNull();
    $this->app->instance(SocialConnectionManager::class, $manager);

    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'instagram']);
    $clipReview = $this->createPublishableClipReview();

    $response = $this->spaRequest('POST', '/api/v1/social/publish', $this->cookies, [
        'clip_review_id' => $clipReview->id,
        'platform' => 'instagram',
        'title' => 'My Clip',
    ]);
    $response->assertStatus(202);

    $publish = SocialPublish::first();

    // Job already ran synchronously during the API request and failed
    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Failed);
    expect($publish->error_message)->not->toBeEmpty();
    expect(strtolower($publish->error_message))->not->toContain('access_token');
    expect(strtolower($publish->error_message))->not->toContain('refresh_token');
    expect($publish->error_message)->toBe('No valid access token available');
});
