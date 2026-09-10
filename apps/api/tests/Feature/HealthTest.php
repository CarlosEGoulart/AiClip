<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

it('returns 200 when database is connected', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200);
});

it('returns valid JSON with expected fields', function () {
    $response = $this->getJson('/api/v1/health');

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

it('has a timestamp in ISO 8601 format', function () {
    $response = $this->getJson('/api/v1/health');

    $data = $response->json();
    $this->assertMatchesRegularExpression(
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
        $data['timestamp']
    );
});

it('actually queries the database', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200);
    $data = $response->json();
    $this->assertEquals('connected', $data['database']);
});

it('returns an exact safe 503 payload when the database query throws', function () {
    DB::shouldReceive('select')
        ->once()
        ->with('SELECT 1')
        ->andThrow(new Exception('SYNTHETIC-DB-FAILURE-MARKER SQLSTATE[08006] password=SYNTHETIC-CREDENTIAL-MARKER'));

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(503);
    $response->assertExactJson([
        'status' => 'error',
        'database' => 'disconnected',
    ]);
});

it('observes the real health query through PostgreSQL on success', function () {
    $this->assertEquals('pgsql', DB::getDriverName());

    $observed = [];
    DB::listen(function ($query) use (&$observed) {
        $observed[] = $query->sql;
    });

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'ok',
        'database' => 'connected',
    ]);
    $response->assertJsonStructure(['status', 'database', 'timestamp']);

    $data = $response->json();
    $this->assertMatchesRegularExpression(
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
        $data['timestamp']
    );
    $this->assertContains('SELECT 1', $observed);
});
