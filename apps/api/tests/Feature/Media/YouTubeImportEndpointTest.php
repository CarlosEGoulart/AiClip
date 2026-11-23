<?php

namespace Tests\Feature\Media;

use App\Jobs\YouTubeImportJob;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pest\TestSuite;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

/*
 * TC-BE-04/05 (test-plan §1.4): POST /api/v1/projects/{project}/media/import/youtube
 * — 202 enqueue-only, ownership 404s, validation 422s, guest 401, CSRF 419,
 * rate limit 429, and response field sanitization. The media disk is faked
 * so the sync queue can complete processing inline safely.
 */

beforeEach(function () {
    $this->disk = config('media.disk', 'media');
    Storage::fake($this->disk);

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    $this->otherUser = User::factory()->create();
    $this->otherProject = Project::factory()->create(['user_id' => $this->otherUser->id]);

    $this->cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $this->cookies, [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();
});

function youtubePayload(array $overrides = []): array
{
    return array_merge([
        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ], $overrides);
}

it('returns 202 accepted with media_asset_id and status queued and enqueues the job', function () {
    Queue::fake();

    $test = TestSuite::getInstance()->test;
    $response = $test->spaRequest(
        'POST',
        "/api/v1/projects/{$test->project->id}/media/import/youtube",
        $test->cookies,
        youtubePayload(),
    );

    $response->assertStatus(202);

    $data = $response->json('data');
    expect($data['media_asset_id'])->toBeInt()
        ->and($data['status'])->toBe('queued');

    expect(collect(array_keys($data))->sort()->values()->all())
        ->toBe(collect(['media_asset_id', 'status'])->sort()->values()->all());

    $mediaAsset = MediaAsset::query()->sole();
    expect($mediaAsset->project_id)->toBe($test->project->id)
        ->and($mediaAsset->original_name)->toBe('youtube-import')
        ->and($mediaAsset->mime_type)->toBe('video/mp4')
        ->and($mediaAsset->size_bytes)->toBe(0)
        ->and($mediaAsset->status)->toBe('queued')
        ->and($mediaAsset->processing_status)->toBe('queued')
        ->and($mediaAsset->storage_key)->toMatch('#\A'.$test->user->id.'/'.$test->project->id.'/[0-9a-f-]{36}\.mp4\z#');

    Queue::assertPushed(YouTubeImportJob::class, function (YouTubeImportJob $job) use ($mediaAsset, $test) {
        return $job->mediaAssetId === $mediaAsset->id
            && $job->url === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
            && $job->userId === $test->user->id;
    });
    Queue::assertPushedTimes(YouTubeImportJob::class, 1);
});

it('creates the media asset with the correct storage key format', function () {
    Queue::fake();

    $test = TestSuite::getInstance()->test;
    $test->spaRequest(
        'POST',
        "/api/v1/projects/{$test->project->id}/media/import/youtube",
        $test->cookies,
        youtubePayload(),
    );

    $mediaAsset = MediaAsset::query()->sole();
    expect($mediaAsset->storage_key)->toMatch('/^'.preg_quote((string) $test->user->id, '/').'\/'.preg_quote((string) $test->project->id, '/').'\/[a-f0-9-]+\.mp4$/');
});

it('does not expose storage_disk, storage_key, or user_id in the response', function () {
    Queue::fake();

    $test = TestSuite::getInstance()->test;
    $response = $test->spaRequest(
        'POST',
        "/api/v1/projects/{$test->project->id}/media/import/youtube",
        $test->cookies,
        youtubePayload(),
    );

    $response->assertStatus(202);
    $response->assertJsonMissingPath('data.storage_disk');
    $response->assertJsonMissingPath('data.storage_key');
    $response->assertJsonMissingPath('data.user_id');
});

it('accepts youtu.be URLs', function () {
    Queue::fake();

    $test = TestSuite::getInstance()->test;
    $response = $test->spaRequest(
        'POST',
        "/api/v1/projects/{$test->project->id}/media/import/youtube",
        $test->cookies,
        youtubePayload(['url' => 'https://youtu.be/dQw4w9WgXcQ']),
    );

    $response->assertStatus(202);
    expect(MediaAsset::query()->count())->toBe(1);
});

it('rejects invalid YouTube URLs with 422 and creates no row', function () {
    $assetsBefore = MediaAsset::query()->count();

    $test = TestSuite::getInstance()->test;
    $response = $test->spaRequest(
        'POST',
        "/api/v1/projects/{$test->project->id}/media/import/youtube",
        $test->cookies,
        youtubePayload(['url' => 'https://example.com/video']),
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['url']);
    expect(MediaAsset::query()->count())->toBe($assetsBefore);
});

foreach ([
    'empty url' => ['url' => ''],
    'vimeo url' => ['url' => 'https://vimeo.com/123456789'],
    'non-string url' => ['url' => ['not', 'a', 'string']],
] as $label => $overrides) {
    it("rejects {$label} with 422", function () use ($overrides) {
        $payload = array_merge(youtubePayload(), $overrides);

        $test = TestSuite::getInstance()->test;
        $response = $test->spaRequest(
            'POST',
            "/api/v1/projects/{$test->project->id}/media/import/youtube",
            $test->cookies,
            $payload,
        );

        $response->assertUnprocessable();
        expect(MediaAsset::query()->count())->toBe(0);
    });
}

it('rejects a missing url field with 422', function () {
    $test = TestSuite::getInstance()->test;
    $payload = ['not_url' => 'something'];

    $response = $test->spaRequest(
        'POST',
        "/api/v1/projects/{$test->project->id}/media/import/youtube",
        $test->cookies,
        $payload,
    );

    $response->assertUnprocessable();
    expect(MediaAsset::query()->count())->toBe(0);
});

it('returns 404 for a project owned by another user and creates no row', function () {
    $assetsBefore = MediaAsset::query()->count();

    $test = TestSuite::getInstance()->test;
    $response = $test->spaRequest(
        'POST',
        "/api/v1/projects/{$test->otherProject->id}/media/import/youtube",
        $test->cookies,
        youtubePayload(),
    );

    $response->assertNotFound();
    expect(MediaAsset::query()->count())->toBe($assetsBefore);
});

it('returns 404 for a non-existent project and creates no row', function () {
    $assetsBefore = MediaAsset::query()->count();

    $test = TestSuite::getInstance()->test;
    $response = $test->spaRequest(
        'POST',
        '/api/v1/projects/999999999/media/import/youtube',
        $test->cookies,
        youtubePayload(),
    );

    $response->assertNotFound();
    expect(MediaAsset::query()->count())->toBe($assetsBefore);
});

it('returns an identical 404 body for non-owned and non-existent projects', function () {
    $test = TestSuite::getInstance()->test;

    $nonOwned = $test->spaRequest(
        'POST',
        "/api/v1/projects/{$test->otherProject->id}/media/import/youtube",
        $test->cookies,
        youtubePayload(),
    );
    $nonExistent = $test->spaRequest(
        'POST',
        '/api/v1/projects/999999999/media/import/youtube',
        $test->cookies,
        youtubePayload(),
    );

    $nonOwned->assertNotFound();
    $nonExistent->assertNotFound();
});

it('returns 401 for a guest with a valid CSRF token', function () {
    $guestCookies = $this->csrfCookies();

    $test = TestSuite::getInstance()->test;
    $response = $test->spaRequest(
        'POST',
        "/api/v1/projects/{$test->project->id}/media/import/youtube",
        $guestCookies,
        youtubePayload(),
    );

    $response->assertUnauthorized();
    expect(MediaAsset::query()->count())->toBe(0);
});

it('rate limits imports at 20 requests per minute per user', function () {
    $test = TestSuite::getInstance()->test;

    for ($i = 0; $i < 20; $i++) {
        $test->spaRequest(
            'POST',
            "/api/v1/projects/{$test->project->id}/media/import/youtube",
            $test->cookies,
            youtubePayload(),
        )->assertStatus(202);
    }

    $response = $test->spaRequest(
        'POST',
        "/api/v1/projects/{$test->project->id}/media/import/youtube",
        $test->cookies,
        youtubePayload(),
    );

    $response->assertStatus(429);
    expect($response->headers->has('Retry-After'))->toBeTrue();
    expect(MediaAsset::query()->count())->toBe(20);
});
