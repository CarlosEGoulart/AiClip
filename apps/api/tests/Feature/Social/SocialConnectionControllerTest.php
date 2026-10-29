<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
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

describe('Authentication & Authorization', function () {
    it('returns 401 for guest accessing GET /api/v1/social/connections', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('GET', '/api/v1/social/connections', $cookies)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    });

    it('returns 401 for guest accessing POST /api/v1/social/youtube/connect', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('POST', '/api/v1/social/youtube/connect', $cookies, [])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    });

    it('returns 401 for guest accessing DELETE /api/v1/social/connections/1', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('DELETE', '/api/v1/social/connections/1', $cookies)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    });

    it('returns 401 for guest accessing GET /api/v1/social/connections/1/status', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('GET', '/api/v1/social/connections/1/status', $cookies)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    });

    it('returns only own connections on index', function () {
        $other = $this->createSecondUser();
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
        SocialConnection::factory()->for($other)->connected()->create(['platform' => 'instagram']);

        $response = $this->spaRequest('GET', '/api/v1/social/connections', $this->cookies);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.platform', 'youtube');
    });

    it('returns 404 for disconnect other user connection', function () {
        $other = $this->createSecondUser();
        $connection = SocialConnection::factory()->for($other)->connected()->create();

        $this->spaRequest('DELETE', "/api/v1/social/connections/{$connection->id}", $this->cookies)
            ->assertNotFound();
    });

    it('returns 404 for status other user connection', function () {
        $other = $this->createSecondUser();
        $connection = SocialConnection::factory()->for($other)->connected()->create();

        $this->spaRequest('GET', "/api/v1/social/connections/{$connection->id}/status", $this->cookies)
            ->assertNotFound();
    });
});

describe('GET /api/v1/social/connections (List)', function () {
    it('returns empty array when no connections', function () {
        $response = $this->spaRequest('GET', '/api/v1/social/connections', $this->cookies);

        $response->assertOk();
        $response->assertJson(['data' => []]);
    });

    it('returns user connections', function () {
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'instagram']);

        $response = $this->spaRequest('GET', '/api/v1/social/connections', $this->cookies);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.platform', 'youtube');
        $response->assertJsonPath('data.1.platform', 'instagram');
        $response->assertJsonPath('data.0.platform_label', 'YouTube');
        $response->assertJsonPath('data.1.platform_label', 'Instagram');
    });

    it('paginates results', function () {
        SocialConnection::factory()->for($this->user)->count(20)->connected()->create();

        $response = $this->spaRequest('GET', '/api/v1/social/connections', $this->cookies);

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonStructure([
            'data',
            'links',
            'meta' => ['current_page', 'per_page', 'total'],
        ]);
    });
});

describe('POST /api/v1/social/{platform}/connect (Initiate)', function () {
    it('returns 422 for invalid platform', function () {
        $this->spaRequest('POST', '/api/v1/social/invalid/connect', $this->cookies, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform']);
    });

    it('returns 409 when connection already exists', function () {
        SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);

        $this->spaRequest('POST', '/api/v1/social/youtube/connect', $this->cookies, [])
            ->assertStatus(409);
    });

    it('returns authorization url and state', function () {
        $response = $this->spaRequest('POST', '/api/v1/social/youtube/connect', $this->cookies, []);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'authorization_url',
                'state',
                'connection_id',
            ],
        ]);
        expect($response->json('data.authorization_url'))->toContain('accounts.google.com');
        expect($response->json('data.state'))->toBeString()->toHaveLength(40);
        expect($response->json('data.authorization_url'))->toContain($response->json('data.state'));
    });

    it('creates pending connection row', function () {
        $this->spaRequest('POST', '/api/v1/social/youtube/connect', $this->cookies, []);

        $this->assertDatabaseHas('social_connections', [
            'user_id' => $this->user->id,
            'platform' => 'youtube',
            'provider_user_id' => 'pending',
            'status' => 'connected',
        ]);
    });

    it('succeeds without CSRF token', function () {
        // Authenticated cookies with the XSRF-TOKEN cookie removed by key,
        // so the request carries a session but no CSRF token.
        $cookies = array_diff_key($this->cookies, ['XSRF-TOKEN' => null]);

        $this->spaRequest('POST', '/api/v1/social/youtube/connect', $cookies, [])
            ->assertOk();
    });
});

describe('GET /api/v1/social/{platform}/callback (OAuth Callback)', function () {
    it('returns 302 redirect', function () {
        $cookies = $this->csrfCookies();
        $connection = SocialConnection::factory()->for($this->user)->pending()->create([
            'platform' => 'youtube',
            'pending_oauth_state' => 'valid_state_1234567890123456789012345678901234567890',
        ]);

        $response = $this->spaRequest('GET', "/api/v1/social/youtube/callback?code=valid_code&state={$connection->pending_oauth_state}", $cookies);

        $response->assertStatus(302);
    });

    it('validates state parameter', function () {
        $cookies = $this->csrfCookies();
        $connection = SocialConnection::factory()->for($this->user)->pending()->create([
            'platform' => 'youtube',
            'pending_oauth_state' => 'valid_state_1234567890123456789012345678901234567890',
        ]);

        // Missing state
        $response = $this->spaRequest('GET', '/api/v1/social/youtube/callback?code=valid_code', $cookies);
        $response->assertStatus(302);
        expect($response->headers->get('Location'))->toContain('error=');

        // Wrong state
        $response = $this->spaRequest('GET', '/api/v1/social/youtube/callback?code=valid_code&state=wrong_state', $cookies);
        $response->assertStatus(302);
        expect($response->headers->get('Location'))->toContain('error=');
    });

    it('rejects expired state', function () {
        $cookies = $this->csrfCookies();
        $connection = SocialConnection::factory()->for($this->user)->pending()->create([
            'platform' => 'youtube',
            'pending_oauth_state' => 'valid_state_1234567890123456789012345678901234567890',
            'pending_oauth_state_expires_at' => now()->subMinutes(1),
        ]);

        $response = $this->spaRequest('GET', "/api/v1/social/youtube/callback?code=valid_code&state={$connection->pending_oauth_state}", $cookies);
        $response->assertStatus(302);
        expect($response->headers->get('Location'))->toContain('error=');
    });

    it('rejects missing code', function () {
        $cookies = $this->csrfCookies();
        $connection = SocialConnection::factory()->for($this->user)->pending()->create([
            'platform' => 'youtube',
            'pending_oauth_state' => 'valid_state_1234567890123456789012345678901234567890',
        ]);

        $response = $this->spaRequest('GET', "/api/v1/social/youtube/callback?state={$connection->pending_oauth_state}", $cookies);
        $response->assertStatus(302);
        expect($response->headers->get('Location'))->toContain('error=');
    });

    it('redirects to frontend on success', function () {
        $cookies = $this->csrfCookies();
        $connection = SocialConnection::factory()->for($this->user)->pending()->create([
            'platform' => 'youtube',
            'pending_oauth_state' => 'valid_state_1234567890123456789012345678901234567890',
        ]);

        $response = $this->spaRequest('GET', "/api/v1/social/youtube/callback?code=valid_code&state={$connection->pending_oauth_state}", $cookies);
        $response->assertStatus(302);
        expect($response->headers->get('Location'))->toContain('connected=youtube');
    });

    it('redirects to frontend on failure', function () {
        $cookies = $this->csrfCookies();
        $connection = SocialConnection::factory()->for($this->user)->pending()->create([
            'platform' => 'youtube',
            'pending_oauth_state' => 'valid_state_1234567890123456789012345678901234567890',
        ]);

        $response = $this->spaRequest('GET', "/api/v1/social/youtube/callback?code=invalid_code&state={$connection->pending_oauth_state}", $cookies);
        $response->assertStatus(302);
        expect($response->headers->get('Location'))->toContain('error=');
    });
});

describe('DELETE /api/v1/social/connections/{connection} (Disconnect)', function () {
    it('returns 204 on success', function () {
        $connection = SocialConnection::factory()->for($this->user)->connected()->create();

        $this->spaRequest('DELETE', "/api/v1/social/connections/{$connection->id}", $this->cookies)
            ->assertNoContent();
    });

    it('returns 404 for nonexistent connection', function () {
        $this->spaRequest('DELETE', '/api/v1/social/connections/999999', $this->cookies)
            ->assertNotFound();
    });

    it('returns 404 for other user connection', function () {
        $other = $this->createSecondUser();
        $connection = SocialConnection::factory()->for($other)->connected()->create();

        $this->spaRequest('DELETE', "/api/v1/social/connections/{$connection->id}", $this->cookies)
            ->assertNotFound();
    });

    it('succeeds without CSRF token', function () {
        $connection = SocialConnection::factory()->for($this->user)->connected()->create();
        // Authenticated cookies with the XSRF-TOKEN cookie removed by key,
        // so the request carries a session but no CSRF token.
        $cookies = array_diff_key($this->cookies, ['XSRF-TOKEN' => null]);

        $this->spaRequest('DELETE', "/api/v1/social/connections/{$connection->id}", $cookies)
            ->assertNoContent();
    });
});

describe('GET /api/v1/social/connections/{connection}/status (Optional)', function () {
    it('returns connection status', function () {
        $connection = SocialConnection::factory()->for($this->user)->connected()->create([
            'status' => 'connected',
            'last_error' => null,
            'access_token_expires_at' => now()->addHours(1),
        ]);

        $response = $this->spaRequest('GET', "/api/v1/social/connections/{$connection->id}/status", $this->cookies);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'status',
                'last_error',
                'access_token_expires_at',
            ],
        ]);
    });

    it('returns 404 for other user connection', function () {
        $other = $this->createSecondUser();
        $connection = SocialConnection::factory()->for($other)->connected()->create();

        $this->spaRequest('GET', "/api/v1/social/connections/{$connection->id}/status", $this->cookies)
            ->assertNotFound();
    });
});
