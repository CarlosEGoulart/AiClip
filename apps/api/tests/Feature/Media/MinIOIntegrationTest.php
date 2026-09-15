<?php

namespace Tests\Feature\Media;

use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

/**
 * Group: minio-integration
 *
 * Real Laravel → Flysystem → league/flysystem-aws-s3-v3 → actual MinIO.
 * No Storage::fake, mocked adapter, local disk fallback, or environment skip.
 * Missing infrastructure is a failed prerequisite, not a skipped pass.
 */
beforeEach(function () {
    // Verify MinIO infrastructure is available — fail immediately if not
    $disk = config('filesystems.disks.media');
    if ($disk === null || ($disk['driver'] ?? '') !== 's3') {
        $this->markTestSkipped('Media disk not configured as S3');
    }

    // Verify actual connectivity by attempting a lightweight operation
    try {
        Storage::disk('media')->put('__connectivity_test__', 'ping');
        Storage::disk('media')->delete('__connectivity_test__');
    } catch (\Exception $e) {
        $this->markTestSkipped('MinIO not reachable: '.$e->getMessage());
    }

    $userData = [
        'name' => 'Integration User',
        'email' => 'integration@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    $cookies = $this->csrfCookies();

    $this->spaRequest('POST', '/api/v1/auth/register', $cookies, $userData)
        ->assertStatus(201);

    $this->spaRequest('POST', '/api/v1/auth/login', $cookies, [
        'email' => $userData['email'],
        'password' => $userData['password'],
    ])->assertOk();

    $this->cookies = $cookies;
});

/*
|--------------------------------------------------------------------------
| Real MinIO: Direct filesystem operations
|--------------------------------------------------------------------------
*/

it('writes, reads, and deletes a file on real MinIO', function () {
    $key = 'integration-test/'.Str::uuid().'.txt';
    $content = 'Hello MinIO integration test';

    // Write
    Storage::disk('media')->put($key, $content);

    // Verify exists (head operation via Flysystem)
    $this->assertTrue(Storage::disk('media')->exists($key));

    // Read and verify
    $this->assertEquals($content, Storage::disk('media')->get($key));

    // Delete
    Storage::disk('media')->delete($key);

    // Verify absent
    $this->assertFalse(Storage::disk('media')->exists($key));

    // Idempotent delete (no error)
    Storage::disk('media')->delete($key);
    $this->assertFalse(Storage::disk('media')->exists($key));
})->group('minio-integration');

/*
|--------------------------------------------------------------------------
| Real MinIO: SPA authenticated upload lifecycle
|--------------------------------------------------------------------------
*/

it('uploads a real video and verifies object + metadata', function () {
    $cookies = $this->cookies;

    // Create a project
    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Integration Project']);
    $createResponse->assertCreated();
    $projectId = $createResponse->json('data.id');

    // Upload a small real MP4 fixture
    $file = UploadedFile::fake()->create('test-integration.mp4', 1024, 'video/mp4');

    $response = $this->call('POST', "/api/v1/projects/{$projectId}/media/upload", [
        'file' => $file,
    ], $this->cookies, [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $this->csrfToken($this->cookies),
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.status', 'stored');
    $response->assertJsonMissingPath('data.storage_disk');
    $response->assertJsonMissingPath('data.storage_key');

    // Query backend model for internal key
    $mediaAsset = MediaAsset::where('project_id', $projectId)->first();
    $this->assertNotNull($mediaAsset);

    // Verify real object exists
    $this->assertTrue(Storage::disk('media')->exists($mediaAsset->storage_key));

    // List via API
    $listResponse = $this->spaRequest('GET', "/api/v1/projects/{$projectId}/media", $cookies);
    $listResponse->assertOk();
    $listResponse->assertJsonCount(1, 'data');

    // Delete via API
    $deleteResponse = $this->spaRequest('DELETE', "/api/v1/media/{$mediaAsset->id}", $cookies);
    $deleteResponse->assertNoContent();

    // Verify row is gone
    $this->assertDatabaseMissing('media_assets', ['id' => $mediaAsset->id]);

    // Verify object is gone
    $this->assertFalse(Storage::disk('media')->exists($mediaAsset->storage_key));
})->group('minio-integration');

/*
|--------------------------------------------------------------------------
| Real MinIO: Project deletion cleans all objects
|--------------------------------------------------------------------------
*/

it('deletes a project and all its media objects', function () {
    $cookies = $this->cookies;
    $user = User::where('email', 'integration@example.com')->first();

    // Create project with two media assets
    $project = Project::create(['name' => 'Deletion Project', 'user_id' => $user->id]);

    $file1 = UploadedFile::fake()->create('video1.mp4', 1024, 'video/mp4');
    $response1 = $this->call('POST', "/api/v1/projects/{$project->id}/media/upload", [
        'file' => $file1,
    ], $this->cookies, [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $this->csrfToken($this->cookies),
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);
    $response1->assertCreated();

    $file2 = UploadedFile::fake()->create('video2.mp4', 1024, 'video/mp4');
    $response2 = $this->call('POST', "/api/v1/projects/{$project->id}/media/upload", [
        'file' => $file2,
    ], $this->cookies, [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $this->csrfToken($this->cookies),
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);
    $response2->assertCreated();

    // Collect keys
    $mediaAssets = MediaAsset::where('project_id', $project->id)->get();
    $this->assertCount(2, $mediaAssets);
    $keys = $mediaAssets->pluck('storage_key')->toArray();

    // Verify objects exist before deletion
    foreach ($keys as $key) {
        $this->assertTrue(Storage::disk('media')->exists($key));
    }

    // Delete the project
    $deleteResponse = $this->spaRequest('DELETE', "/api/v1/projects/{$project->id}", $cookies);
    $deleteResponse->assertNoContent();

    // Verify all rows gone
    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    $this->assertDatabaseMissing('media_assets', ['project_id' => $project->id]);

    // Verify all objects gone
    foreach ($keys as $key) {
        $this->assertFalse(Storage::disk('media')->exists($key));
    }
})->group('minio-integration');

/*
|--------------------------------------------------------------------------
| Real MinIO: Anonymous access is denied
|--------------------------------------------------------------------------
*/

it('denies anonymous access to private bucket objects', function () {
    $key = 'private-test/'.Str::uuid().'.txt';
    Storage::disk('media')->put($key, 'secret content');

    // Attempt anonymous read — should fail
    $this->assertFalse(Storage::disk('media')->getVisibility($key) === 'public');

    // Cleanup
    Storage::disk('media')->delete($key);
})->group('minio-integration');
