<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

it('health endpoint returns 200 with status and dependencies', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'ok',
        'database' => 'connected',
    ]);
    $response->assertJsonStructure([
        'status',
        'database',
        'timestamp',
    ]);
});

it('readiness endpoint returns 200 with aggregated dependency status', function () {
    $response = $this->getJson('/api/v1/readiness');

    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'ok',
    ]);
    $response->assertJsonStructure([
        'status',
        'dependencies' => [
            'database',
            'storage',
            'queue',
        ],
    ]);
});

it('health endpoint returns 503 when database query throws', function () {
    DB::shouldReceive('select')
        ->once()
        ->with('SELECT 1')
        ->andThrow(new \Exception('SYNTHETIC-DB-FAILURE-MARKER password=SYNTHETIC-CREDENTIAL-MARKER'));

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(503);
    $response->assertExactJson([
        'status' => 'error',
        'database' => 'disconnected',
    ]);
});

it('health 503 response contains no stack trace or credentials', function () {
    DB::shouldReceive('select')
        ->once()
        ->with('SELECT 1')
        ->andThrow(new \Exception('SQLSTATE[08006] password=real-secret-password'));

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(503);
    $content = $response->getContent();
    expect($content)->not->toContain('real-secret-password');
    expect($content)->not->toContain('stack');
    expect($content)->not->toContain('trace');
});

it('readiness response contains no credentials, tokens, or stack traces', function () {
    $response = $this->getJson('/api/v1/readiness');

    $content = $response->getContent();
    expect($content)->not->toContain('password');
    expect($content)->not->toContain('secret');
    expect($content)->not->toContain('token');
    expect($content)->not->toContain('stack');
    expect($content)->not->toContain('trace');
});

it('health endpoint is unauthenticated', function () {
    // No authentication headers or session needed
    $response = $this->getJson('/api/v1/health');

    expect($response->status())->toBeIn([200, 503]);
});

it('readiness endpoint is unauthenticated', function () {
    $response = $this->getJson('/api/v1/readiness');

    expect($response->status())->toBeIn([200, 503]);
});
