<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesTestUsers;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class, CreatesTestUsers::class);

describe('Auth Flow Verification', function () {
    beforeEach(function () {
        $this->userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    });

    it('csrf_cookie_returns_204', function () {
        $cookies = [];
        $this->spaRequest('GET', '/sanctum/csrf-cookie', $cookies)
            ->assertNoContent();
    });

    it('register_returns_201', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('POST', '/api/v1/auth/register', $cookies, $this->userData)
            ->assertStatus(201);
    });

    it('login_returns_200', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('POST', '/api/v1/auth/register', $cookies, $this->userData)
            ->assertStatus(201);

        $this->spaRequest('POST', '/api/v1/auth/login', $cookies, [
            'email' => $this->userData['email'],
            'password' => $this->userData['password'],
        ])->assertOk();
    });

    it('session_persists_across_requests', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('POST', '/api/v1/auth/register', $cookies, $this->userData)
            ->assertStatus(201);

        $this->spaRequest('POST', '/api/v1/auth/login', $cookies, [
            'email' => $this->userData['email'],
            'password' => $this->userData['password'],
        ])->assertOk();

        // Session persists: authenticated request succeeds
        $this->spaRequest('GET', '/api/v1/auth/me', $cookies)
            ->assertOk();
    });

    it('login_regenerates_session', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('POST', '/api/v1/auth/register', $cookies, $this->userData)
            ->assertStatus(201);

        $sessionIdBefore = $this->sessionId($cookies);

        $this->spaRequest('POST', '/api/v1/auth/login', $cookies, [
            'email' => $this->userData['email'],
            'password' => $this->userData['password'],
        ])->assertOk();

        $sessionIdAfter = $this->sessionId($cookies);

        expect($sessionIdBefore)->not->toBe($sessionIdAfter);
    });

    it('user_is_persisted_in_database', function () {
        $cookies = $this->csrfCookies();

        $this->spaRequest('POST', '/api/v1/auth/register', $cookies, $this->userData)
            ->assertStatus(201);

        expect(User::where('email', $this->userData['email'])->exists())->toBeTrue();
    });
});
