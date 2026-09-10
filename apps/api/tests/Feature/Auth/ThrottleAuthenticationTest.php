<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // The app's array cache is new for every test, but persists across the
    // fresh HTTP contexts. Exercise limiter policy only through requests.
    $this->freezeSecond();
});

it('blocks the sixth attempt until 300 seconds without extending the first failure window', function () {
    $user = User::factory()->create(['email' => 'expiry@example.com']);
    $cookies = $this->csrfCookies();
    $firstFailure = now();
    $valid = ['email' => $user->email, 'password' => 'password'];

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->spaRequest('POST', '/api/v1/auth/login', $cookies, [...$valid, 'password' => 'wrong-password'])
            ->assertUnprocessable()->assertExactJson([
                'message' => 'The provided credentials do not match our records.',
                'errors' => ['email' => ['The provided credentials do not match our records.']],
            ]);
        $this->travel(10)->seconds();
    }
    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, $valid)->assertTooManyRequests()
        ->assertExactJson([
            'message' => 'Too many login attempts. Please try again in 250 seconds.',
            'errors' => ['email' => ['Too many login attempts. Please try again in 250 seconds.']],
        ]);
    $this->assertGuestCookies($cookies);
    expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);

    $this->travelTo($firstFailure->copy()->addSeconds(299));
    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, $valid)->assertTooManyRequests()
        ->assertJsonPath('errors.email', ['Too many login attempts. Please try again in 1 seconds.']);
    $this->assertGuestCookies($cookies);

    $this->travelTo($firstFailure->copy()->addSeconds(300));
    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, $valid)->assertOk();
    $this->spaRequest('GET', '/api/v1/auth/me', $cookies)->assertOk()->assertJsonPath('user.id', $user->id);
    $this->assertDatabaseHas('sessions', ['id' => $this->sessionId($cookies), 'user_id' => $user->id]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('shares casing limits while isolating actual client IP and email inputs', function () {
    $user = User::factory()->create(['email' => 'isolation@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);
    $blockedCookies = $this->csrfCookies();
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->spaRequest('POST', '/api/v1/auth/login', $blockedCookies, [
            'email' => $attempt % 2 ? 'ISOLATION@example.com' : $user->email,
            'password' => 'wrong-password',
        ], ip: '192.0.2.10')->assertUnprocessable();
    }
    $valid = ['email' => $user->email, 'password' => 'password'];
    $this->spaRequest('POST', '/api/v1/auth/login', $blockedCookies, $valid, ip: '192.0.2.10')->assertTooManyRequests();
    $this->assertGuestCookies($blockedCookies);

    $otherCookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $otherCookies, ['email' => $other->email, 'password' => 'password'], ip: '192.0.2.10')->assertOk();
    $this->spaRequest('GET', '/api/v1/auth/me', $otherCookies)->assertOk()->assertJsonPath('user.id', $other->id);
    $differentIpCookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $differentIpCookies, $valid, ip: '192.0.2.11')->assertOk();
    $this->spaRequest('GET', '/api/v1/auth/me', $differentIpCookies, ip: '192.0.2.11')->assertOk()->assertJsonPath('user.id', $user->id);
    $this->assertDatabaseHas('sessions', ['id' => $this->sessionId($differentIpCookies), 'user_id' => $user->id, 'ip_address' => '192.0.2.11']);

    // Neither successful independent key may reset the blocked one.
    $this->spaRequest('POST', '/api/v1/auth/login', $blockedCookies, $valid, ip: '192.0.2.10')->assertTooManyRequests();
    $this->assertGuestCookies($blockedCookies);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('resets failures on success so five new failures are allowed after real logout', function () {
    $user = User::factory()->create(['email' => 'reset@example.com']);
    $cookies = $this->csrfCookies();
    $valid = ['email' => $user->email, 'password' => 'password'];
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $this->spaRequest('POST', '/api/v1/auth/login', $cookies, [...$valid, 'password' => 'wrong-password'])->assertUnprocessable();
    }
    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, $valid)->assertOk();
    $this->spaRequest('GET', '/api/v1/auth/me', $cookies)->assertOk()->assertJsonPath('user.id', $user->id);
    $id = $this->sessionId($cookies);
    $this->spaRequest('POST', '/api/v1/auth/logout', $cookies)->assertNoContent();
    $this->assertDatabaseMissing('sessions', ['id' => $id]);
    $this->assertGuestCookies($cookies);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->spaRequest('POST', '/api/v1/auth/login', $cookies, [...$valid, 'password' => 'wrong-password'])->assertUnprocessable();
    }
    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, $valid)->assertTooManyRequests();
    $this->assertGuestCookies($cookies);
    expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});
