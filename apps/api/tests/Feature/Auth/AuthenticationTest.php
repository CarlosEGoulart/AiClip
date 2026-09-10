<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SpaRequests;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, SpaRequests::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:5173');
});

it('registers a new user and returns 201', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'email_verified_at'],
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);
});

it('stores password hashed', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $user = User::where('email', 'test@example.com')->first();
    expect(Hash::isHashed($user->password))->toBeTrue();
    expect(Hash::check('password123', $user->password))->toBeTrue();
    expect($user->password)->not->toBe('password123');
});

it('does not return password or remember_token in response', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.remember_token');
});

it('authenticates user after registration', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $this->assertAuthenticated();
});

it('registration establishes a session', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(201);
    $this->assertAuthenticated();

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'test@example.com');
});

it('rejects missing name', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('rejects invalid email', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'not-an-email',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('rejects duplicate email', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'existing@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('rejects missing password', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('rejects password confirmation mismatch', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'different-password',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('logs in with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'email_verified_at'],
        ]);

    $this->assertAuthenticatedAs($user);
});

it('login regenerates session identifier', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ])->assertOk();

    $sessionBefore = session()->getId();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ])->assertOk();

    $sessionAfter = session()->getId();

    expect($sessionAfter)->not->toBe($sessionBefore);
});

it('rejects incorrect password with generic error', function () {
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('rejects unknown email with same generic error as existing email', function () {
    User::factory()->create([
        'email' => 'existing@example.com',
        'password' => 'password123',
    ]);

    $existingResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'existing@example.com',
        'password' => 'wrong-password',
    ]);

    $unknownResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'password123',
    ]);

    $existingResponse->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $unknownResponse->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $existingBody = $existingResponse->json();
    $unknownBody = $unknownResponse->json();

    expect($existingBody['message'])->toBe($unknownBody['message']);
});

it('rejects missing credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('returns 401 for guest on /me', function () {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401);
});

it('returns sanitized user for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'email_verified_at'],
        ])
        ->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.remember_token');
});

it('logs out authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/auth/logout');

    $response->assertNoContent();
});

it('invalidates session after logout and me returns 401', function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $user = User::factory()->create();
    $cookies = $this->csrfCookies();

    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $this->spaRequest('GET', '/api/v1/auth/me', $cookies)
        ->assertOk()
        ->assertJsonPath('user.email', $user->email);

    $savedCookies = $cookies;
    $id = $this->sessionId($cookies);
    $this->assertDatabaseHas('sessions', ['id' => $id, 'user_id' => $user->id]);

    $this->spaRequest('POST', '/api/v1/auth/logout', $cookies)
        ->assertNoContent();

    $this->assertDatabaseMissing('sessions', ['id' => $id]);
    $this->assertGuestCookies($cookies);
    $this->assertGuestCookies($savedCookies);
    $this->assertGuestCookies([]);
});

it('returns 401 for guest logout', function () {
    $response = $this->postJson('/api/v1/auth/logout');

    $response->assertStatus(401);
});

it('throttles login after too many failed attempts', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(429);
});

it('clears throttle on successful login', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ])->assertOk();

    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ])->assertOk();
});
