<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

it('rejects registration validation failures without creating users or authenticating', function (string $field, mixed $value, string $error) {
    $existing = User::factory()->create(['email' => 'existing@example.com']);
    $before = $existing->fresh()->getRawOriginal();
    $data = ['name' => 'Validation User', 'email' => 'validation@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'];
    if ($value === '__missing__') {
        unset($data[$field]);
    } else {
        $data[$field] = $value;
    }
    if ($field === 'password' && is_string($value) && $value !== '__missing__') {
        $data['password_confirmation'] = $value;
    }
    $cookies = $this->csrfCookies();

    $response = $this->spaRequest('POST', '/api/v1/auth/register', $cookies, $data);

    $response->assertUnprocessable()->assertJsonValidationErrors($error);
    expect(array_keys($response->json()))->toBe(['message', 'errors']);
    expect(array_keys($response->json('errors')))->toBe([$error]);
    expect($response->getContent())->not->toContain('password123', $existing->password, $existing->remember_token);
    foreach (['password', 'password_confirmation'] as $sensitiveField) {
        $submitted = $data[$sensitiveField] ?? null;
        if (is_string($submitted) && $submitted !== '') {
            expect($response->getContent())->not->toContain($submitted);
        }
    }
    $this->assertDatabaseCount('users', 1);
    expect($existing->fresh()->getRawOriginal())->toBe($before);
    expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
    $this->assertGuestCookies($cookies);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'missing name' => ['name', '__missing__', 'name'],
    'blank name' => ['name', '   ', 'name'],
    'non-string name' => ['name', ['unexpected'], 'name'],
    'name 256' => ['name', str_repeat('N', 256), 'name'],
    'missing email' => ['email', '__missing__', 'email'],
    'blank email' => ['email', '   ', 'email'],
    'malformed email' => ['email', 'not-an-email', 'email'],
    'non-string email' => ['email', ['unexpected'], 'email'],
    'email 256' => ['email', str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 61).'.com', 'email'],
    'duplicate email' => ['email', 'existing@example.com', 'email'],
    'missing password' => ['password', '__missing__', 'password'],
    'blank password' => ['password', '', 'password'],
    'non-string password' => ['password', ['unexpected'], 'password'],
    'password 7' => ['password', 'short12', 'password'],
    'missing confirmation' => ['password_confirmation', '__missing__', 'password'],
    'mismatched confirmation' => ['password_confirmation', 'does-not-match', 'password'],
]);

it('accepts registration length boundaries and hashes the exact minimum password', function (string $name, string $email) {
    $cookies = $this->csrfCookies();
    $data = ['name' => $name, 'email' => $email, 'password' => 'eight123', 'password_confirmation' => 'eight123', 'email_verified_at' => '2026-01-02T03:04:05Z', 'remember_token' => 'untrusted-private-value'];

    $response = $this->spaRequest('POST', '/api/v1/auth/register', $cookies, $data)->assertCreated();

    $user = User::sole();
    expect(Hash::check('eight123', $user->password))->toBeTrue();
    expect($user->password)->not->toBe('eight123');
    expect($user->email_verified_at)->toBeNull();
    expect($user->remember_token)->toBeNull();
    $expected = ['user' => ['id' => $user->id, 'name' => $name, 'email' => $email, 'email_verified_at' => null]];
    $response->assertExactJson($expected);
    $this->spaRequest('GET', '/api/v1/auth/me', $cookies)->assertOk()->assertExactJson($expected);
    $this->assertDatabaseHas('sessions', ['id' => $this->sessionId($cookies), 'user_id' => $user->id]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'minimum name and password' => ['N', 'boundary@example.com'],
    '255 character name and email' => [str_repeat('N', 255), str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 60).'.com'],
]);

it('rejects invalid login input without changing the user or granting identity', function (array $data, array $fields) {
    $user = User::factory()->create(['email' => 'login@example.com']);
    $before = $user->fresh()->getRawOriginal();
    $cookies = $this->csrfCookies();

    $response = $this->spaRequest('POST', '/api/v1/auth/login', $cookies, $data);

    $response->assertUnprocessable()->assertJsonValidationErrors($fields);
    expect(array_keys($response->json()))->toBe(['message', 'errors']);
    expect($response->getContent())->not->toContain($user->password, $user->remember_token);
    expect($user->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('users', 1);
    expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
    $this->assertGuestCookies($cookies);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'missing fields' => [[], ['email', 'password']],
    'blank email' => [['email' => '  ', 'password' => 'password'], ['email']],
    'non-string email' => [['email' => ['unexpected'], 'password' => 'password'], ['email']],
    'malformed email' => [['email' => 'not-an-email', 'password' => 'password'], ['email']],
    'blank password' => [['email' => 'login@example.com', 'password' => ''], ['password']],
    'non-string password' => [['email' => 'login@example.com', 'password' => ['unexpected']], ['password']],
]);

it('returns the same sanitized generic error for wrong password and unknown email', function (string $email) {
    $user = User::factory()->create(['email' => 'known@example.com']);
    $before = $user->fresh()->getRawOriginal();
    $cookies = $this->csrfCookies();

    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, ['email' => $email, 'password' => 'wrong-password'])
        ->assertUnprocessable()->assertExactJson([
            'message' => 'The provided credentials do not match our records.',
            'errors' => ['email' => ['The provided credentials do not match our records.']],
        ]);

    expect($user->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('users', 1);
    expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
    $this->assertGuestCookies($cookies);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['known@example.com', 'unknown@example.com']);

it('returns exactly public user fields and types on login and persisted me', function (?string $verifiedAt, ?string $expectedTimestamp) {
    $user = User::factory()->create(['name' => 'Public User', 'email' => 'public@example.com', 'email_verified_at' => $verifiedAt]);
    $cookies = $this->csrfCookies();
    $expected = ['user' => ['id' => $user->id, 'name' => 'Public User', 'email' => 'public@example.com', 'email_verified_at' => $expectedTimestamp]];

    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, ['email' => $user->email, 'password' => 'password'])
        ->assertOk()->assertExactJson($expected)->assertJsonPath('user.id', $user->id);
    $this->spaRequest('GET', '/api/v1/auth/me', $cookies)->assertOk()->assertExactJson($expected);

    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['unverified' => [null, null], 'verified' => ['2026-01-02 03:04:05', '2026-01-02T03:04:05.000000Z']]);
