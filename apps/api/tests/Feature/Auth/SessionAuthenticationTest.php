<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

it('persists registration and login through rotated database session cookies', function (string $operation, int $status) {
    $data = ['name' => 'Session User', 'email' => 'session@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'];
    if ($operation === 'login') {
        User::factory()->unverified()->create(collect($data)->only(['name', 'email', 'password'])->all());
    }
    $cookies = $this->csrfCookies();
    $guestCookies = $cookies;
    $guestId = $this->sessionId($cookies);
    expect(config('session.driver'))->toBe('database');
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    $this->assertDatabaseHas('sessions', ['id' => $guestId, 'user_id' => null]);

    $response = $this->spaRequest('POST', '/api/v1/auth/'.$operation, $cookies, $data)->assertStatus($status);

    $user = User::where('email', $data['email'])->sole();
    expect(Hash::check($data['password'], $user->password))->toBeTrue();
    expect($user->password)->not->toBe($data['password']);
    $expected = ['user' => ['id' => $user->id, 'name' => 'Session User', 'email' => 'session@example.com', 'email_verified_at' => null]];
    $response->assertExactJson($expected);
    $id = $this->sessionId($cookies);
    expect($id)->not->toBe($guestId);
    expect($this->csrfToken($cookies))->not->toBe($this->csrfToken($guestCookies));
    $this->assertDatabaseHas('sessions', ['id' => $id, 'user_id' => $user->id]);
    $this->spaRequest('GET', '/api/v1/auth/me', $cookies)->assertOk()->assertExactJson($expected);
    $this->spaRequest('GET', '/api/v1/auth/me', $cookies)->assertOk()->assertExactJson($expected);
    $this->assertDatabaseMissing('sessions', ['id' => $guestId]);
    $this->assertGuestCookies($guestCookies);
    $this->assertGuestCookies([]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['registration' => ['register', 201], 'login' => ['login', 200]]);

it('destroys the authenticated database session and rejects saved cookie replay after logout', function () {
    $user = User::factory()->create();
    $cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, ['email' => $user->email, 'password' => 'password'])->assertOk();
    $this->spaRequest('GET', '/api/v1/auth/me', $cookies)->assertOk()->assertJsonPath('user.id', $user->id);
    $authenticatedCookies = $cookies;
    $id = $this->sessionId($cookies);
    $this->assertDatabaseHas('sessions', ['id' => $id, 'user_id' => $user->id]);

    $this->spaRequest('POST', '/api/v1/auth/logout', $cookies)->assertNoContent();

    $this->assertDatabaseMissing('sessions', ['id' => $id]);
    expect($this->sessionId($cookies))->not->toBe($id);
    expect($this->csrfToken($cookies))->not->toBe($this->csrfToken($authenticatedCookies));
    $this->assertGuestCookies($cookies);
    $this->assertGuestCookies($authenticatedCookies);
    $this->assertGuestCookies([]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('returns JSON 401 for guest logout with a valid CSRF pair', function () {
    $cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/logout', $cookies)
        ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    $this->assertGuestCookies([]);
});
