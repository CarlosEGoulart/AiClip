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
| Authentication: Guest access denied
|--------------------------------------------------------------------------
*/

it('returns 401 for guest accessing DELETE /api/v1/media/1', function () {
    $cookies = $this->csrfCookies();

    $this->spaRequest('DELETE', '/api/v1/media/1', $cookies)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

/*
|--------------------------------------------------------------------------
| Delete: Authenticated user can delete own media
|--------------------------------------------------------------------------
*/

it('allows authenticated user to delete own media', function () {
    $cookies = $this->cookies;
    $user = User::where('email', 'test@example.com')->first();

    // Create a project and media
    $project = Project::create(['name' => 'Media Project', 'user_id' => $user->id]);
    $media = MediaAsset::factory()->create(['project_id' => $project->id]);

    Storage::fake('media');

    $response = $this->spaRequest('DELETE', "/api/v1/media/{$media->id}", $cookies);

    $response->assertNoContent();

    $this->assertDatabaseMissing('media_assets', ['id' => $media->id]);
});

/*
|--------------------------------------------------------------------------
| Delete Authorization: Cannot delete other user's media
|--------------------------------------------------------------------------
*/

it('returns 404 when deleting media owned by another user', function () {
    $cookies = $this->cookies;

    $other = User::factory()->create([
        'name' => 'Other User',
        'email' => 'other@example.com',
        'password' => 'password123',
    ]);
    $otherProject = Project::create(['name' => 'Secret Project', 'user_id' => $other->id]);
    $otherMedia = MediaAsset::factory()->create(['project_id' => $otherProject->id]);

    $this->spaRequest('DELETE', "/api/v1/media/{$otherMedia->id}", $cookies)
        ->assertNotFound();

    $this->assertDatabaseHas('media_assets', ['id' => $otherMedia->id]);
});

it('returns 404 for non-existent media ID', function () {
    $cookies = $this->cookies;

    $this->spaRequest('DELETE', '/api/v1/media/999999', $cookies)
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Delete Behavior: Storage failure handling
|--------------------------------------------------------------------------
*/

it('preserves database record when storage deletion fails', function () {
    $cookies = $this->cookies;
    $user = User::where('email', 'test@example.com')->first();

    $project = Project::create(['name' => 'Media Project', 'user_id' => $user->id]);
    $media = MediaAsset::factory()->create(['project_id' => $project->id]);

    // Make storage delete throw an exception
    Storage::shouldReceive('disk')
        ->once()
        ->with('media')
        ->andReturnSelf();
    Storage::shouldReceive('delete')
        ->once()
        ->with($media->storage_key)
        ->andThrow(new \Exception('Storage unavailable'));

    $response = $this->spaRequest('DELETE', "/api/v1/media/{$media->id}", $cookies);

    // Per the corrected contract: storage failure -> 500, DB record preserved
    $response->assertStatus(500);

    // DB record is preserved (NOT deleted)
    $this->assertDatabaseHas('media_assets', ['id' => $media->id]);
});

/*
|--------------------------------------------------------------------------
| Delete Behavior: Does not affect other assets
|--------------------------------------------------------------------------
*/

it('deleting one media asset does not affect other assets', function () {
    $cookies = $this->cookies;
    $user = User::where('email', 'test@example.com')->first();

    $project = Project::create(['name' => 'Media Project', 'user_id' => $user->id]);
    $media1 = MediaAsset::factory()->create(['project_id' => $project->id]);
    $media2 = MediaAsset::factory()->create(['project_id' => $project->id]);

    Storage::fake('media');

    $this->spaRequest('DELETE', "/api/v1/media/{$media1->id}", $cookies)
        ->assertNoContent();

    $this->assertDatabaseMissing('media_assets', ['id' => $media1->id]);
    $this->assertDatabaseHas('media_assets', ['id' => $media2->id]);
});
