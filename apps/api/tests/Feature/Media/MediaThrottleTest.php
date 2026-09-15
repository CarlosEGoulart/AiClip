<?php

namespace Tests\Feature\Media;

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
| Rate Limiting: Upload throttling
|--------------------------------------------------------------------------
*/

it('returns 429 after 10 uploads in one minute', function () {
    $cookies = $this->cookies;
    $user = User::where('email', 'test@example.com')->first();

    $project = Project::create(['name' => 'Media Project', 'user_id' => $user->id]);

    Storage::fake('media');

    // Make 10 successful uploads using wire cookie CSRF token
    for ($i = 0; $i < 10; $i++) {
        $file = UploadedFile::fake()->create("video{$i}.mp4", 1024, 'video/mp4');

        $response = $this->call('POST', "/api/v1/projects/{$project->id}/media/upload", [], $cookies, ['file' => $file], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
            'HTTP_ORIGIN' => 'http://localhost:5173',
        ]);

        $response->assertCreated();
    }

    // 11th upload should be rate limited
    $file = UploadedFile::fake()->create('video11.mp4', 1024, 'video/mp4');

    $response = $this->call('POST', "/api/v1/projects/{$project->id}/media/upload", [], $cookies, ['file' => $file], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'] ?? '',
        'HTTP_ORIGIN' => 'http://localhost:5173',
    ]);

    $response->assertTooManyRequests();
});
