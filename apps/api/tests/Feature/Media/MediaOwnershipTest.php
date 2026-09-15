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
| Ownership Isolation: User B cannot access User A's media
|--------------------------------------------------------------------------
*/

it('user B cannot list user A media via project A', function () {
    $cookies = $this->cookies;
    $userA = User::where('email', 'test@example.com')->first();

    // User A creates project and media
    $projectA = Project::create(['name' => 'Project A', 'user_id' => $userA->id]);
    MediaAsset::factory()->create(['project_id' => $projectA->id]);

    // User B registers and logs in (unique email)
    $cookiesB = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/register', $cookiesB, [
        'name' => 'User B',
        'email' => 'userb-list@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(201);

    $this->spaRequest('POST', '/api/v1/auth/login', $cookiesB, [
        'email' => 'userb-list@example.com',
        'password' => 'password123',
    ])->assertOk();

    // User B tries to list User A's media
    $this->spaRequest('GET', "/api/v1/projects/{$projectA->id}/media", $cookiesB)
        ->assertNotFound();
});

it('user B cannot delete user A media', function () {
    $cookies = $this->cookies;
    $userA = User::where('email', 'test@example.com')->first();

    // User A creates project and media
    $projectA = Project::create(['name' => 'Project A', 'user_id' => $userA->id]);
    $mediaA = MediaAsset::factory()->create(['project_id' => $projectA->id]);

    // User B registers and logs in (unique email)
    $cookiesB = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/register', $cookiesB, [
        'name' => 'User B',
        'email' => 'userb-delete@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(201);

    $this->spaRequest('POST', '/api/v1/auth/login', $cookiesB, [
        'email' => 'userb-delete@example.com',
        'password' => 'password123',
    ])->assertOk();

    // User B tries to delete User A's media
    $this->spaRequest('DELETE', "/api/v1/media/{$mediaA->id}", $cookiesB)
        ->assertNotFound();

    $this->assertDatabaseHas('media_assets', ['id' => $mediaA->id]);
});

it('user B cannot upload to user A project', function () {
    $cookies = $this->cookies;
    $userA = User::where('email', 'test@example.com')->first();

    // User A creates project
    $projectA = Project::create(['name' => 'Project A', 'user_id' => $userA->id]);

    // User B registers and logs in (unique email)
    $cookiesB = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/register', $cookiesB, [
        'name' => 'User B',
        'email' => 'userb-upload@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(201);

    $this->spaRequest('POST', '/api/v1/auth/login', $cookiesB, [
        'email' => 'userb-upload@example.com',
        'password' => 'password123',
    ])->assertOk();

    Storage::fake('media');
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    // User B tries to upload to User A's project using wire cookie CSRF token
    $response = $this->call('POST', "/api/v1/projects/{$projectA->id}/media/upload", [], $cookiesB, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookiesB['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertNotFound();
});
