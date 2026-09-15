<?php

namespace Tests\Feature\Media;

use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
| Cascade Cleanup: Deleting project removes media
|--------------------------------------------------------------------------
*/

it('deleting project removes all associated media from database', function () {
    $cookies = $this->cookies;
    $user = User::where('email', 'test@example.com')->first();

    $project = Project::create(['name' => 'Media Project', 'user_id' => $user->id]);
    $media1 = MediaAsset::factory()->create(['project_id' => $project->id]);
    $media2 = MediaAsset::factory()->create(['project_id' => $project->id]);

    $this->spaRequest('DELETE', "/api/v1/projects/{$project->id}", $cookies)
        ->assertNoContent();

    $this->assertDatabaseMissing('media_assets', ['id' => $media1->id]);
    $this->assertDatabaseMissing('media_assets', ['id' => $media2->id]);
    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
});

it('deleting project with no media succeeds without errors', function () {
    $cookies = $this->cookies;
    $user = User::where('email', 'test@example.com')->first();

    $project = Project::create(['name' => 'Empty Project', 'user_id' => $user->id]);

    $this->spaRequest('DELETE', "/api/v1/projects/{$project->id}", $cookies)
        ->assertNoContent();

    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
});

it('fails to delete project when storage deletion fails', function () {
    $cookies = $this->cookies;
    $user = User::where('email', 'test@example.com')->first();

    $project = Project::create(['name' => 'Media Project', 'user_id' => $user->id]);
    $media1 = MediaAsset::factory()->create(['project_id' => $project->id]);
    $media2 = MediaAsset::factory()->create(['project_id' => $project->id]);

    // Mock storage to fail on first delete, succeed on second
    // Use Storage::shouldReceive() instead of Storage::fake() to avoid Mockery conflict
    Storage::shouldReceive('disk')
        ->once()
        ->with('media')
        ->andReturnSelf();
    Storage::shouldReceive('delete')
        ->once()
        ->with($media1->storage_key)
        ->andThrow(new \Exception('Storage unavailable'));
    Storage::shouldReceive('delete')
        ->once()
        ->with($media2->storage_key)
        ->andReturn(true);

    // Per the corrected contract: storage failure -> 500, project preserved
    $this->spaRequest('DELETE', "/api/v1/projects/{$project->id}", $cookies)
        ->assertStatus(500);

    // Project and all media records are preserved
    $this->assertDatabaseHas('projects', ['id' => $project->id]);
    $this->assertDatabaseHas('media_assets', ['id' => $media1->id]);
    $this->assertDatabaseHas('media_assets', ['id' => $media2->id]);
});
