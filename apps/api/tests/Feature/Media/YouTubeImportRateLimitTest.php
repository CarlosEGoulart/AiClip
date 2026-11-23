<?php

namespace Tests\Feature\Media;

use App\Jobs\YouTubeImportJob;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

/*
 * TC-BE-05 (test-plan §1.5): Rate limiting for youtube-import — 20/min per
 * authenticated user, shared across projects, per-user independence, and
 * test-clock reset.
 */

beforeEach(function () {
    $this->disk = config('media.disk', 'media');
    Storage::fake($this->disk);

    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->projectA = Project::factory()->create(['user_id' => $this->user->id]);
    $this->projectB = Project::factory()->create(['user_id' => $this->user->id]);
    $this->otherProject = Project::factory()->create(['user_id' => $this->otherUser->id]);

    $this->cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $this->cookies, [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();
});

function importRequest(string $projectId, array $cookies, ?string $xsrf = 'matching'): \Illuminate\Testing\TestResponse
{
    $test = \Pest\TestSuite::getInstance()->test;

    return $test->spaRequest(
        'POST',
        "/api/v1/projects/{$projectId}/media/import/youtube",
        $cookies,
        ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        xsrf: $xsrf,
    );
}

it('allows 20 ownership-admitted imports in one minute', function () {
    Queue::fake();

    for ($i = 0; $i < 20; $i++) {
        importRequest($this->projectA->id, $this->cookies)->assertStatus(202);
    }

    expect(MediaAsset::query()->count())->toBe(20);
    Queue::assertPushedTimes(YouTubeImportJob::class, 20);
});

it('returns 429 on the 21st attempt with Retry-After header', function () {
    Queue::fake();

    for ($i = 0; $i < 20; $i++) {
        importRequest($this->projectA->id, $this->cookies)->assertStatus(202);
    }

    $response = importRequest($this->projectA->id, $this->cookies);

    $response->assertStatus(429);
    expect($response->headers->has('Retry-After'))->toBeTrue();
    expect(MediaAsset::query()->count())->toBe(20);
    Queue::assertPushedTimes(YouTubeImportJob::class, 20);
});

it('shares the 20/minute limit across the same user\'s projects', function () {
    Queue::fake();

    // 10 on project A
    for ($i = 0; $i < 10; $i++) {
        importRequest($this->projectA->id, $this->cookies)->assertStatus(202);
    }
    // 10 on project B (same user) — reaches 20
    for ($i = 0; $i < 10; $i++) {
        importRequest($this->projectB->id, $this->cookies)->assertStatus(202);
    }

    // 21st on either project → 429
    $response = importRequest($this->projectA->id, $this->cookies);
    $response->assertStatus(429);

    $response = importRequest($this->projectB->id, $this->cookies);
    $response->assertStatus(429);

    expect(MediaAsset::query()->count())->toBe(20);
});

it('gives another user an independent 20/minute limit', function () {
    Queue::fake();

    $otherCookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $otherCookies, [
        'email' => $this->otherUser->email,
        'password' => 'password',
    ])->assertOk();

    // Exhaust the first user's limit
    for ($i = 0; $i < 20; $i++) {
        importRequest($this->projectA->id, $this->cookies)->assertStatus(202);
    }
    $response = importRequest($this->projectA->id, $this->cookies);
    $response->assertStatus(429);

    // The other user can still import 20 times
    for ($i = 0; $i < 20; $i++) {
        importRequest($this->otherProject->id, $otherCookies)->assertStatus(202);
    }

    expect(MediaAsset::query()->count())->toBe(40);
});
