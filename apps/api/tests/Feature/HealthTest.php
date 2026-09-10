<?php

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
