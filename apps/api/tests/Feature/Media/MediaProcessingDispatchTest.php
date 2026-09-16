<?php

namespace Tests\Feature\Media;

use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

/*
|--------------------------------------------------------------------------
| Setup: Register and login a user before each test
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $userData = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    $cookies = $this->csrfCookies();

    // Register the user
    $this->spaRequest('POST', '/api/v1/auth/register', $cookies, $userData)
        ->assertStatus(201);

    // Login the user (re-establish session)
    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, [
        'email' => $userData['email'],
        'password' => $userData['password'],
    ])->assertOk();

    $this->cookies = $cookies;

    // Mock ProcessMediaAction to prevent actual worker invocation in sync queue
    $actionMock = Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')
        ->andReturn([
            'status' => 'success',
            'probe' => ['duration_ms' => 1000],
        ]);
    app()->instance(ProcessMediaAction::class, $actionMock);
});

/*
|--------------------------------------------------------------------------
| Upload Dispatches Processing Job
|--------------------------------------------------------------------------
*/

it('dispatches ProcessMediaAsset job after successful upload', function () {
    Queue::fake();
    $cookies = $this->cookies;

    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $projectId = $createResponse->json('data.id');

    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    $response = $this->call('POST', "/api/v1/projects/{$projectId}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertCreated();
    Queue::assertPushed(ProcessMediaAsset::class, function ($job) use ($projectId) {
        return $job->mediaAsset->project_id === $projectId;
    });
});

it('upload response does not depend on job completion', function () {
    $cookies = $this->cookies;

    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $projectId = $createResponse->json('data.id');

    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    $response = $this->call('POST', "/api/v1/projects/{$projectId}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.status', 'stored');
});

it('preserves existing upload response structure', function () {
    $cookies = $this->cookies;

    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $projectId = $createResponse->json('data.id');

    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    $response = $this->call('POST', "/api/v1/projects/{$projectId}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertCreated();
    $response->assertJsonStructure([
        'data' => [
            'id',
            'project_id',
            'original_name',
            'mime_type',
            'size_bytes',
            'status',
            'created_at',
            'updated_at',
        ],
    ]);
});

/*
|--------------------------------------------------------------------------
| Authorization: Non-owner cannot trigger processing
|--------------------------------------------------------------------------
*/

it('does not dispatch job for non-owner upload attempt', function () {
    Queue::fake();
    $cookies = $this->cookies;

    // Create another user and their project
    $other = User::factory()->create();
    $otherProject = Project::create(['name' => 'Other Project', 'user_id' => $other->id]);

    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    $response = $this->call('POST', "/api/v1/projects/{$otherProject->id}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertNotFound();
    Queue::assertNotPushed(ProcessMediaAsset::class);
});

/*
|--------------------------------------------------------------------------
| Processing Status Tracking
|--------------------------------------------------------------------------
*/

it('creates media asset with default processing_status stored', function () {
    Queue::fake();
    $cookies = $this->cookies;

    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $projectId = $createResponse->json('data.id');

    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    $response = $this->call('POST', "/api/v1/projects/{$projectId}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertCreated();
    $mediaAsset = MediaAsset::where('project_id', $projectId)->first();
    expect($mediaAsset->processing_status)->toBe('stored');
});

it('media asset has processing lifecycle columns after upload', function () {
    Queue::fake();
    $cookies = $this->cookies;

    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $projectId = $createResponse->json('data.id');

    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    $response = $this->call('POST', "/api/v1/projects/{$projectId}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertCreated();
    $mediaAsset = MediaAsset::where('project_id', $projectId)->first();
    expect($mediaAsset->processing_status)->toBe('stored');
    expect($mediaAsset->idempotency_key)->toBeNull();
    expect($mediaAsset->processing_started_at)->toBeNull();
    expect($mediaAsset->processing_completed_at)->toBeNull();
    expect($mediaAsset->processing_error)->toBeNull();
});
