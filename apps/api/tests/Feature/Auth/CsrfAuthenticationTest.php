<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

it('initializes readable XSRF and HttpOnly session cookies with local HTTP attributes', function () {
    $cookies = [];
    $response = $this->spaRequest('GET', '/sanctum/csrf-cookie', $cookies)->assertNoContent();
    $session = $response->getCookie(config('session.cookie'), false);
    $xsrf = $response->getCookie('XSRF-TOKEN', false);
    expect($session)->not->toBeNull();
    expect($xsrf)->not->toBeNull();
    expect($session->isHttpOnly())->toBeTrue();
    expect($xsrf->isHttpOnly())->toBeFalse();
    foreach ([$session, $xsrf] as $cookie) {
        expect($cookie->getPath())->toBe('/');
        expect($cookie->getSameSite())->toBe('lax');
        expect($cookie->isSecure())->toBe((bool) config('session.secure'));
    }
    $this->assertDatabaseHas('sessions', ['id' => $this->sessionId($cookies), 'user_id' => null]);
});

it('rejects invalid CSRF without side effects and accepts the matching pair', function (string $operation, int $status, string $token) {
    $data = ['name' => 'CSRF User', 'email' => 'csrf@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'];
    if ($operation !== 'register') {
        User::factory()->create(collect($data)->only(['name', 'email', 'password'])->all());
    }
    $cookies = $this->csrfCookies();
    if ($operation === 'logout') {
        $this->spaRequest('POST', '/api/v1/auth/login', $cookies, $data)->assertOk();
    }
    $originalCookies = $cookies;
    $otherCookies = $this->csrfCookies();
    $header = match ($token) {
        'missing' => null,
        'wrong' => 'not-an-encrypted-xsrf-token',
        'other session' => $otherCookies['XSRF-TOKEN'],
    };
    $before = User::count();

    $this->spaRequest('POST', '/api/v1/auth/'.$operation, $cookies, $data, $header)
        ->assertStatus(419)->assertExactJson(['message' => 'CSRF token mismatch.']);

    $this->assertDatabaseCount('users', $before);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    if ($operation === 'logout') {
        $this->spaRequest('GET', '/api/v1/auth/me', $originalCookies)
            ->assertOk()->assertJsonPath('user.email', $data['email']);
    } else {
        $this->assertGuestCookies($originalCookies);
        expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
    }

    $this->spaRequest('POST', '/api/v1/auth/'.$operation, $cookies, $data)->assertStatus($status);
    if ($operation === 'logout') {
        $this->assertGuestCookies($cookies);
    } else {
        $this->spaRequest('GET', '/api/v1/auth/me', $cookies)->assertOk()->assertJsonPath('user.email', $data['email']);
    }
})->with(['register' => ['register', 201], 'login' => ['login', 200], 'logout' => ['logout', 204]])
    ->with(['missing', 'wrong', 'other session']);
