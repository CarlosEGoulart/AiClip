<?php

namespace Tests\Feature\Media;

use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

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
});

/*
|--------------------------------------------------------------------------
| Authentication: Guest access denied
|--------------------------------------------------------------------------
*/

it('returns 401 for guest accessing POST /api/v1/projects/1/media/upload', function () {
    $cookies = $this->csrfCookies();

    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    // Use $this->call() directly for file uploads — spaRequest() JSON-encodes data
    $this->call('POST', '/api/v1/projects/1/media/upload', [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

/*
|--------------------------------------------------------------------------
| Upload: Authenticated user can upload a video
|--------------------------------------------------------------------------
*/

it('allows authenticated user to upload a video to own project', function () {
    $cookies = $this->cookies;

    // Create a project first
    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $createResponse->assertCreated();
    $projectId = $createResponse->json('data.id');

    // Fake storage
    Storage::fake('media');

    // Create a fake video file
    $file = UploadedFile::fake()->create('test-video.mp4', 5120, 'video/mp4');

    // Upload via API — use wire cookie CSRF token (URL-decoded from XSRF-TOKEN cookie)
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
    $response->assertJsonPath('data.project_id', $projectId);
    $response->assertJsonPath('data.original_name', 'test-video.mp4');
    $response->assertJsonPath('data.status', 'stored');

    // Verify database record
    $this->assertDatabaseHas('media_assets', [
        'project_id' => $projectId,
        'original_name' => 'test-video.mp4',
        'status' => 'stored',
    ]);

    // Verify storage key uses UUID-based format with user prefix (not the DB ID)
    $mediaAsset = MediaAsset::where('project_id', $projectId)->first();
    $userId = auth()->id();
    expect($mediaAsset->storage_key)->toMatch('/^'.preg_quote((string) $userId, '/').'\/'.preg_quote((string) $projectId, '/').'\/[a-f0-9-]+\.mp4$/');

    // Verify storage disk was used (the file was written via Storage::fake)
    Storage::disk('media')->assertExists($mediaAsset->storage_key);
});

/*
|--------------------------------------------------------------------------
| Upload Validation: Rejects invalid files
|--------------------------------------------------------------------------
*/

it('returns 422 when file field is missing', function () {
    $cookies = $this->cookies;

    // Create a project
    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $createResponse->assertCreated();
    $projectId = $createResponse->json('data.id');

    $response = $this->spaRequest('POST', "/api/v1/projects/{$projectId}/media/upload", $cookies, []);

    $response->assertUnprocessable();
});

it('returns 422 when file MIME type is not accepted', function () {
    $cookies = $this->cookies;

    // Create a project
    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $createResponse->assertCreated();
    $projectId = $createResponse->json('data.id');

    // Create a text file (not a video)
    $file = UploadedFile::fake()->create('test.txt', 1024, 'text/plain');

    $response = $this->call('POST', "/api/v1/projects/{$projectId}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['file']);
});

/*
|--------------------------------------------------------------------------
| Upload Authorization: Cannot upload to other user's project
|--------------------------------------------------------------------------
*/

it('returns 404 when uploading to another user project', function () {
    $cookies = $this->cookies;

    // Create another user and their project
    $other = User::factory()->create([
        'name' => 'Other User',
        'email' => 'other@example.com',
        'password' => 'password123',
    ]);
    $otherProject = Project::create(['name' => 'Secret Project', 'user_id' => $other->id]);

    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    $response = $this->call('POST', "/api/v1/projects/{$otherProject->id}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertNotFound();
});

it('returns 404 when uploading to non-existent project', function () {
    $cookies = $this->cookies;

    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    $response = $this->call('POST', '/api/v1/projects/999999/media/upload', [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Upload Response Structure: Does not expose internal fields
|--------------------------------------------------------------------------
*/

it('does not expose storage_disk or storage_key in upload response', function () {
    $cookies = $this->cookies;

    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $createResponse->assertCreated();
    $projectId = $createResponse->json('data.id');

    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    $response = $this->call('POST', "/api/v1/projects/{$projectId}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertCreated();
    $response->assertJsonMissingPath('data.storage_disk');
    $response->assertJsonMissingPath('data.storage_key');
    $response->assertJsonMissingPath('data.user_id');
});
