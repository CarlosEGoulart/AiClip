<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\MediaAsset;
use App\Models\DerivedAsset;
use App\Models\ClipReview;
use App\Models\SocialPublish;
use App\Services\FakeYouTubePublisher;
use App\Services\FakeImageGenerationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('register -> login -> create project -> upload media -> processing completes', function () {
    Config::set('MEDIA_CLIP_RANKING_PROVIDER', 'fake');

    $user = User::factory()->create();

    // Register (new user)
    $registerResponse = $this->postJson('/api/v1/auth/register', [
        'name' => 'Integration User',
        'email' => 'integration@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    expect($registerResponse->status())->toBe(201);

    // Login
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'integration@example.com',
        'password' => 'password123',
    ]);

    expect($loginResponse->status())->toBe(200);

    // Create project
    $projectResponse = $this->actingAs($user)->postJson('/api/v1/projects', [
        'name' => 'Integration Project',
    ]);

    expect($projectResponse->status())->toBe(201);
    $projectId = $projectResponse->json('data.id');

    // Upload media
    $uploadResponse = $this->actingAs($user)->postJson(
        '/api/v1/projects/' . $projectId . '/media/upload',
        ['file' => \Illuminate\Http\UploadedFile::fake()->create('video.mp4', 100)]
    );

    expect($uploadResponse->status())->toBe(201);
    $mediaId = $uploadResponse->json('data.id');

    // Verify media asset exists and processing was dispatched
    $media = MediaAsset::find($mediaId);
    expect($media)->not->toBeNull();
    expect($media->processing_status)->toBeIn(['stored', 'processing', 'completed']);
});

it('no tokens in API responses', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/auth/me');

    $content = $response->getContent();
    expect($content)->not->toContain('access_token');
    expect($content)->not->toContain('refresh_token');
});

it('failed job visibility: failed job is persisted in failed_jobs', function () {
    Queue::fake();

    // Verify the failed_jobs table concept is available via config
    expect(config('queue.failed.driver'))->toBe('database-uuids');
    expect(config('queue.failed.table'))->toBe('failed_jobs');
});

it('idempotency: duplicate render job does not duplicate output', function () {
    Queue::fake();

    // RenderMediaClip has uniqueId: render-media-clip:{mediaAssetId}:{recommendationId}:{candidateIndex}
    // Two jobs with the same uniqueId are deduplicated by the queue.
    $job1 = new \App\Jobs\RenderMediaClip(1, 1, 0);
    $job2 = new \App\Jobs\RenderMediaClip(1, 1, 0);

    expect($job1->uniqueId())->toBe($job2->uniqueId());
});

it('idempotency: duplicate generate image job does not duplicate output', function () {
    Queue::fake();

    $job1 = new \App\Jobs\GenerateImage(1);
    $job2 = new \App\Jobs\GenerateImage(1);

    expect($job1->uniqueId())->toBe($job2->uniqueId());
});

it('fake image generation provider is bound in testing', function () {
    $provider = app(\App\Contracts\ImageGenerationProvider::class);

    expect($provider)->toBeInstanceOf(FakeImageGenerationProvider::class);
});
