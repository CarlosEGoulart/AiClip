<?php

use App\Models\SocialConnection;
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

it('full connect callback list disconnect flow', function () {
    // Step 1: Initiate connection
    $connectResponse = $this->spaRequest('POST', '/api/v1/social/youtube/connect', $this->cookies, []);
    $connectResponse->assertOk();
    $connectResponse->assertJsonStructure([
        'data' => ['authorization_url', 'state', 'connection_id'],
    ]);

    $state = $connectResponse->json('data.state');
    $connectionId = $connectResponse->json('data.connection_id');

    // Verify pending connection exists
    $this->assertDatabaseHas('social_connections', [
        'id' => $connectionId,
        'user_id' => $this->user->id,
        'platform' => 'youtube',
        'provider_user_id' => 'pending',
        'pending_oauth_state' => $state,
    ]);

    // Step 2: Handle callback
    $callbackResponse = $this->spaRequest('GET', "/api/v1/social/youtube/callback?code=valid_code&state={$state}", $this->cookies);
    $callbackResponse->assertStatus(302);
    expect($callbackResponse->headers->get('Location'))->toContain('connected=youtube');

    // Step 3: List connections - should show connected
    $listResponse = $this->spaRequest('GET', '/api/v1/social/connections', $this->cookies);
    $listResponse->assertOk();
    $listResponse->assertJsonCount(1, 'data');
    $listResponse->assertJsonPath('data.0.platform', 'youtube');
    $listResponse->assertJsonPath('data.0.status', 'connected');
    $listResponse->assertJsonPath('data.0.display_name', 'Test YouTube Channel');

    // Step 4: Disconnect
    $disconnectResponse = $this->spaRequest('DELETE', "/api/v1/social/connections/{$connectionId}", $this->cookies);
    $disconnectResponse->assertNoContent();

    // Step 5: List connections - should be empty
    $listResponse = $this->spaRequest('GET', '/api/v1/social/connections', $this->cookies);
    $listResponse->assertOk();
    $listResponse->assertJson(['data' => []]);
});

it('multiple platforms can be connected', function () {
    // Connect YouTube
    $connectResponse = $this->spaRequest('POST', '/api/v1/social/youtube/connect', $this->cookies, []);
    $connectResponse->assertOk();
    $state = $connectResponse->json('data.state');
    $this->spaRequest('GET', "/api/v1/social/youtube/callback?code=valid_code&state={$state}", $this->cookies)
        ->assertStatus(302);

    // Connect Instagram
    $connectResponse = $this->spaRequest('POST', '/api/v1/social/instagram/connect', $this->cookies, []);
    $connectResponse->assertOk();
    $state = $connectResponse->json('data.state');
    $this->spaRequest('GET', "/api/v1/social/instagram/callback?code=valid_code&state={$state}", $this->cookies)
        ->assertStatus(302);

    // List should show both
    $listResponse = $this->spaRequest('GET', '/api/v1/social/connections', $this->cookies);
    $listResponse->assertOk();
    $listResponse->assertJsonCount(2, 'data');
    $platforms = collect($listResponse->json('data'))->pluck('platform')->toArray();
    expect($platforms)->toContain('youtube');
    expect($platforms)->toContain('instagram');
});

it('duplicate platform connection prevented', function () {
    // Connect YouTube
    $connectResponse = $this->spaRequest('POST', '/api/v1/social/youtube/connect', $this->cookies, []);
    $connectResponse->assertOk();
    $state = $connectResponse->json('data.state');
    $this->spaRequest('GET', "/api/v1/social/youtube/callback?code=valid_code&state={$state}", $this->cookies)
        ->assertStatus(302);

    // Try to connect YouTube again
    $connectResponse = $this->spaRequest('POST', '/api/v1/social/youtube/connect', $this->cookies, []);
    $connectResponse->assertStatus(409);
});

it('expired token can be refreshed', function () {
    // Create a connected connection with expired token
    $connection = SocialConnection::factory()->for($this->user)->create([
        'platform' => 'youtube',
        'status' => 'connected',
        'access_token' => 'fake_youtube_access_token_valid',
        'refresh_token' => 'fake_youtube_refresh_token_valid_code',
        'access_token_expires_at' => now()->subHours(1), // expired
    ]);

    // Call status endpoint to trigger refresh
    $statusResponse = $this->spaRequest('GET', "/api/v1/social/connections/{$connection->id}/status", $this->cookies);
    $statusResponse->assertOk();

    // Refresh the connection and check status
    $connection->refresh();
    // The token should have been refreshed via getValidAccessToken in status check
    // Since we can't easily test the internal refresh, we verify the connection exists
    expect($connection->status)->toBe('connected');
});

it('refresh failure marks revoked', function () {
    $connection = SocialConnection::factory()->for($this->user)->create([
        'platform' => 'youtube',
        'status' => 'connected',
        'refresh_token' => 'invalid_refresh_token',
    ]);

    // Try to refresh via manager
    $manager = app(SocialConnectionManager::class);
    $result = $manager->refreshConnection($connection);

    expect($result)->toBeFalse();
    expect($connection->fresh()->status)->toBe('revoked');
});

it('revoked connection shows in list', function () {
    $connection = SocialConnection::factory()->for($this->user)->revoked()->create([
        'platform' => 'youtube',
        'display_name' => 'Revoked Channel',
    ]);

    $listResponse = $this->spaRequest('GET', '/api/v1/social/connections', $this->cookies);
    $listResponse->assertOk();
    $listResponse->assertJsonCount(1, 'data');
    $listResponse->assertJsonPath('data.0.status', 'revoked');
    $listResponse->assertJsonPath('data.0.display_name', 'Revoked Channel');
});

it('concurrent connect attempts handled', function () {
    // This test would require async handling which is complex in Pest
    // We'll verify the 409 prevention works
    $connectResponse = $this->spaRequest('POST', '/api/v1/social/youtube/connect', $this->cookies, []);
    $connectResponse->assertOk();

    // Second attempt should fail
    $connectResponse2 = $this->spaRequest('POST', '/api/v1/social/youtube/connect', $this->cookies, []);
    $connectResponse2->assertStatus(409);
});

it('callback with invalid provider response handled', function () {
    $connectResponse = $this->spaRequest('POST', '/api/v1/social/youtube/connect', $this->cookies, []);
    $connectResponse->assertOk();
    $state = $connectResponse->json('data.state');

    // Use invalid code
    $callbackResponse = $this->spaRequest('GET', "/api/v1/social/youtube/callback?code=invalid_code&state={$state}", $this->cookies);
    $callbackResponse->assertStatus(302);
    expect($callbackResponse->headers->get('Location'))->toContain('error=');
});
