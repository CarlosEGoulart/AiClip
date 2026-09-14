<?php

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
| Helper: create a second user for authorization tests
|--------------------------------------------------------------------------
*/

function createSecondUser(): User
{
    return User::factory()->create([
        'name' => 'Other User',
        'email' => 'other@example.com',
        'password' => 'password123',
    ]);
}

/*
|--------------------------------------------------------------------------
| Authentication: Guest access denied
|--------------------------------------------------------------------------
*/

it('returns 401 for guest accessing GET /api/v1/projects', function () {
    $cookies = $this->csrfCookies();

    $this->spaRequest('GET', '/api/v1/projects', $cookies)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('returns 401 for guest accessing POST /api/v1/projects', function () {
    $cookies = $this->csrfCookies();

    $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Test'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('returns 401 for guest accessing GET /api/v1/projects/1', function () {
    $cookies = $this->csrfCookies();

    $this->spaRequest('GET', '/api/v1/projects/1', $cookies)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('returns 401 for guest accessing DELETE /api/v1/projects/1', function () {
    $cookies = $this->csrfCookies();

    $this->spaRequest('DELETE', '/api/v1/projects/1', $cookies)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

/*
|--------------------------------------------------------------------------
| Create: Authenticated user can create a project
|--------------------------------------------------------------------------
*/

it('allows authenticated user to create a project', function () {
    $cookies = $this->cookies;

    $response = $this->spaRequest('POST', '/api/v1/projects', $cookies, [
        'name' => 'My First Project',
    ]);

    $response->assertCreated();
    $response->assertJsonStructure([
        'data' => [
            'id',
            'name',
            'description',
            'created_at',
            'updated_at',
        ],
    ]);
    $response->assertJsonPath('data.name', 'My First Project');

    $this->assertDatabaseHas('projects', [
        'name' => 'My First Project',
    ]);
});

/*
|--------------------------------------------------------------------------
| List: Authenticated user can list own projects
|--------------------------------------------------------------------------
*/

it('allows authenticated user to list own projects', function () {
    $cookies = $this->cookies;
    $user = User::where('email', 'test@example.com')->first();

    // Create two projects for this user
    $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Project A'])->assertCreated();
    $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Project B'])->assertCreated();

    // Create a project for another user
    $other = createSecondUser();
    \App\Models\Project::create(['name' => 'Other Project', 'user_id' => $other->id]);

    $response = $this->spaRequest('GET', '/api/v1/projects', $cookies);

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('data.0.name', 'Project A');
    $response->assertJsonPath('data.1.name', 'Project B');
});

/*
|--------------------------------------------------------------------------
| View: Authenticated user can view own project
|--------------------------------------------------------------------------
*/

it('allows authenticated user to view own project', function () {
    $cookies = $this->cookies;

    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Viewable Project']);
    $createResponse->assertCreated();
    $projectId = $createResponse->json('data.id');

    $response = $this->spaRequest('GET', "/api/v1/projects/{$projectId}", $cookies);

    $response->assertOk();
    $response->assertJsonPath('data.name', 'Viewable Project');
    $response->assertJsonPath('data.id', $projectId);
});

/*
|--------------------------------------------------------------------------
| Delete: Authenticated user can delete own project
|--------------------------------------------------------------------------
*/

it('allows authenticated user to delete own project', function () {
    $cookies = $this->cookies;

    $createResponse = $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => 'Delete Me']);
    $createResponse->assertCreated();
    $projectId = $createResponse->json('data.id');

    $response = $this->spaRequest('DELETE', "/api/v1/projects/{$projectId}", $cookies);

    $response->assertNoContent();

    $this->assertDatabaseMissing('projects', ['id' => $projectId]);
});

/*
|--------------------------------------------------------------------------
| Authorization: User cannot access other user's projects
|--------------------------------------------------------------------------
*/

it('returns 404 when user tries to view another user project', function () {
    $cookies = $this->cookies;
    $other = createSecondUser();
    $otherProject = \App\Models\Project::create(['name' => 'Secret Project', 'user_id' => $other->id]);

    $this->spaRequest('GET', "/api/v1/projects/{$otherProject->id}", $cookies)
        ->assertNotFound();
});

it('returns 404 when user tries to delete another user project', function () {
    $cookies = $this->cookies;
    $other = createSecondUser();
    $otherProject = \App\Models\Project::create(['name' => 'Secret Project', 'user_id' => $other->id]);

    $this->spaRequest('DELETE', "/api/v1/projects/{$otherProject->id}", $cookies)
        ->assertNotFound();

    $this->assertDatabaseHas('projects', [
        'id' => $otherProject->id,
    ]);
});

/*
|--------------------------------------------------------------------------
| Validation: Rejects invalid data
|--------------------------------------------------------------------------
*/

it('rejects create with missing name', function () {
    $cookies = $this->cookies;

    $this->spaRequest('POST', '/api/v1/projects', $cookies, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('rejects create with empty name', function () {
    $cookies = $this->cookies;

    $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('rejects create with name exceeding 255 characters', function () {
    $cookies = $this->cookies;

    $this->spaRequest('POST', '/api/v1/projects', $cookies, ['name' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

/*
|--------------------------------------------------------------------------
| Security: Ownership cannot be overridden via request payload
|--------------------------------------------------------------------------
*/

it('ignores user_id in create request payload', function () {
    $cookies = $this->cookies;
    $other = createSecondUser();

    $response = $this->spaRequest('POST', '/api/v1/projects', $cookies, [
        'name' => 'Injected Project',
        'user_id' => $other->id,
    ]);

    $response->assertCreated();

    // Verify user_id is not exposed in the API response
    $response->assertJsonMissing(['user_id' => $other->id]);
    $response->assertJsonMissingPath('data.user_id');

    $project = \App\Models\Project::where('name', 'Injected Project')->first();
    expect($project->user_id)->not->toBe($other->id);
});
