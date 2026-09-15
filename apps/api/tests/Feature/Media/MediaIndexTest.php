<?php

namespace Tests\Feature\Media;

use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('returns 401 for guest accessing GET /api/v1/projects/1/media', function () {
    $cookies = $this->csrfCookies();

    $this->spaRequest('GET', '/api/v1/projects/1/media', $cookies)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

/*
|--------------------------------------------------------------------------
| List: Authenticated user can list own project media
|--------------------------------------------------------------------------
*/

it('returns empty array when project has no media', function () {
    $cookies = $this->cookies;

    // Create a project
    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Media Project']);
    $createResponse->assertCreated();
    $projectId = $createResponse->json('data.id');

    $response = $this->spaRequest('GET', "/api/v1/projects/{$projectId}/media", $cookies);

    $response->assertOk();
    $response->assertExactJson(['data' => []]);
});

it('returns all media assets for own project ordered by created_at desc', function () {
    $cookies = $this->cookies;
    $user = User::where('email', 'test@example.com')->first();

    // Create a project
    $project = Project::create(['name' => 'Media Project', 'user_id' => $user->id]);

    // Create two media assets
    $media1 = MediaAsset::factory()->create([
        'project_id' => $project->id,
        'original_name' => 'video1.mp4',
        'created_at' => now()->subHour(),
    ]);
    $media2 = MediaAsset::factory()->create([
        'project_id' => $project->id,
        'original_name' => 'video2.mp4',
        'created_at' => now(),
    ]);

    $response = $this->spaRequest('GET', "/api/v1/projects/{$project->id}/media", $cookies);

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    // Most recent first
    $response->assertJsonPath('data.0.id', $media2->id);
    $response->assertJsonPath('data.1.id', $media1->id);
});

/*
|--------------------------------------------------------------------------
| List Authorization: Cannot list other user's media
|--------------------------------------------------------------------------
*/

it('returns 404 when listing media for another user project', function () {
    $cookies = $this->cookies;

    $other = User::factory()->create([
        'name' => 'Other User',
        'email' => 'other@example.com',
        'password' => 'password123',
    ]);
    $otherProject = Project::create(['name' => 'Secret Project', 'user_id' => $other->id]);

    $this->spaRequest('GET', "/api/v1/projects/{$otherProject->id}/media", $cookies)
        ->assertNotFound();
});

it('returns 404 for non-existent project', function () {
    $cookies = $this->cookies;

    $this->spaRequest('GET', '/api/v1/projects/999999/media', $cookies)
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| List Response Structure
|--------------------------------------------------------------------------
*/

it('matches the API contract structure', function () {
    $cookies = $this->cookies;
    $user = User::where('email', 'test@example.com')->first();

    $project = Project::create(['name' => 'Media Project', 'user_id' => $user->id]);
    MediaAsset::factory()->create(['project_id' => $project->id]);

    $response = $this->spaRequest('GET', "/api/v1/projects/{$project->id}/media", $cookies);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'project_id',
                'original_name',
                'mime_type',
                'size_bytes',
                'status',
                'created_at',
                'updated_at',
            ],
        ],
    ]);
});
