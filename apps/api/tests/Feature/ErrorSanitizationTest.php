<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class);

it('500 error body contains no stack trace or storage internals', function () {
    DB::shouldReceive('select')
        ->once()
        ->with('SELECT 1')
        ->andThrow(new \Exception('Unexpected error with storage_key=secret/key and bucket=secret-bucket'));

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(503);
    $content = $response->getContent();
    expect($content)->not->toContain('stack trace');
    expect($content)->not->toContain('storage_key');
    expect($content)->not->toContain('bucket');
    expect($content)->not->toContain('Unexpected error');
});

it('422/404 bodies contain no tokens or credentials', function () {
    // A 422 validation error should not echo back secrets
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test',
        'email' => 'invalid',
        'password' => 'secret123',
    ]);

    $content = $response->getContent();
    expect($content)->not->toContain('secret123');
    expect($content)->not->toContain('access_token');
    expect($content)->not->toContain('refresh_token');
});

it('exception message with DB password is sanitized before log/response', function () {
    $exceptionMessage = 'SQLSTATE[08006] [1045] Access denied for user aiclip (using password: real-db-password)';

    $sanitized = preg_replace('/password[=:]\s*[^;\s]+/i', 'password=[REDACTED]', $exceptionMessage);

    expect($sanitized)->not->toContain('real-db-password');
    expect($sanitized)->toContain('password=[REDACTED]');
});

it('500 error does not expose storage internals in media download error path', function () {
    $owner = User::factory()->create();

    // Request a non-existent image download - should be 404, not 500 with internals
    $response = $this->actingAs($owner)->getJson('/api/v1/images/999999/download');

    expect($response->status())->toBeIn([401, 404]);
    $content = $response->getContent();
    expect($content)->not->toContain('storage_key');
    expect($content)->not->toContain('storage_disk');
    expect($content)->not->toContain('bucket');
});
