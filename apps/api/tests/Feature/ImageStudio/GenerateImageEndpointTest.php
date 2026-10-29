<?php

namespace Tests\Feature\ImageStudio;

use App\Jobs\GenerateImage;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Pest\TestSuite;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

/*
 * TC-BE-03/04/05 (test-plan §2): POST /api/v1/images/generate — 202 with a
 * pending resource (enqueue only), ownership 404s, validation 422s,
 * guest 401, CSRF 419, and the 20/min per-user rate limit. The media disk
 * is faked so the sync queue can complete generation inline safely.
 */

const WHITELISTED_FIELDS = [
    'id',
    'project_id',
    'prompt',
    'parameters',
    'mime_type',
    'size_bytes',
    'width',
    'height',
    'status',
    'created_at',
    'updated_at',
    'download_url',
];

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

function generatePayload(array $overrides = []): array
{
    return array_merge([
        'project_id' => test()->project->id,
        'prompt' => 'A neon-lit city skyline at dusk',
    ], $overrides);
}

function generateRequest(array $payload): TestResponse
{
    // Resolve the running TestCase directly: Pest's test() returns a
    // HigherOrderTapProxy that forwards methods through __call, which
    // cannot pass the by-reference $cookies argument of spaRequest().
    $test = TestSuite::getInstance()->test;

    return $test->spaRequest('POST', '/api/v1/images/generate', $test->cookies, $payload);
}

it('returns 202 accepted with a pending image resource and enqueues the job', function () {
    Queue::fake();

    $response = generateRequest(generatePayload());

    $response->assertStatus(202);

    $data = $response->json('data');
    expect($data['status'])->toBe('pending')
        ->and($data['project_id'])->toBe($this->project->id)
        ->and($data['prompt'])->toBe('A neon-lit city skyline at dusk')
        ->and($data['parameters'])->toBe([
            'style_preset' => 'photorealistic',
            'width' => 1024,
            'height' => 1024,
        ])
        ->and($data['mime_type'])->toBe('image/png')
        ->and($data['size_bytes'])->toBe(0)
        ->and($data['width'])->toBe(1024)
        ->and($data['height'])->toBe(1024)
        ->and($data['download_url'])->toBeNull();

    expect(collect(array_keys($data))->sort()->values()->all())
        ->toBe(collect(WHITELISTED_FIELDS)->sort()->values()->all());

    $image = Image::query()->sole();
    expect($image->user_id)->toBe($this->user->id)
        ->and($image->status->value)->toBe('pending')
        ->and($image->storage_disk)->toBe($this->disk)
        ->and($image->storage_key)->toMatch('#\Aprojects/'.$this->project->id.'/images/[0-9a-f-]{36}\.png\z#');

    Queue::assertPushed(GenerateImage::class, function (GenerateImage $job) use ($image) {
        return $job->imageId === $image->id;
    });
    Queue::assertPushedTimes(GenerateImage::class, 1);
});

it('reports the pending enqueue-time state even though the sync queue completes inline', function () {
    $response = generateRequest(generatePayload());

    $response->assertStatus(202);
    expect($response->json('data.status'))->toBe('pending');

    $image = Image::query()->sole();
    expect($image->status->value)->toBe('generated')
        ->and($image->size_bytes)->toBeGreaterThan(0)
        ->and(Storage::disk($this->disk)->exists($image->storage_key))->toBeTrue();
});

it('persists the requested style preset and dimension overrides', function () {
    Queue::fake();

    $response = generateRequest(generatePayload([
        'style_preset' => 'cinematic',
        'width' => 1080,
        'height' => 1920,
    ]));

    $response->assertStatus(202);

    $image = Image::query()->sole();
    expect($image->parameters)->toBe([
        'style_preset' => 'cinematic',
        'width' => 1080,
        'height' => 1920,
    ])
        ->and($image->width)->toBe(1080)
        ->and($image->height)->toBe(1920);
});

it('returns 404 for a project owned by another user and creates no row', function () {
    $imagesBefore = Image::query()->count();

    $response = generateRequest(generatePayload(['project_id' => $this->otherProject->id]));

    $response->assertNotFound();
    expect(Image::query()->count())->toBe($imagesBefore);
});

it('returns 404 for a non-existent project and creates no row', function () {
    $imagesBefore = Image::query()->count();

    $response = generateRequest(generatePayload(['project_id' => 999999999]));

    $response->assertNotFound();
    expect(Image::query()->count())->toBe($imagesBefore);
});

it('returns an identical 404 body for non-owned and non-existent projects', function () {
    $nonOwned = generateRequest(generatePayload(['project_id' => $this->otherProject->id]));
    $nonExistent = generateRequest(generatePayload(['project_id' => 999999999]));

    expect($nonOwned->getContent())->toBe($nonExistent->getContent());
});

it('rejects invalid generate requests with 422', function (array $overrides) {
    $response = generateRequest(generatePayload($overrides));

    $response->assertUnprocessable();
    expect(Image::query()->count())->toBe(0);
})->with([
    'prompt missing' => [['prompt' => null]],
    'prompt empty' => [['prompt' => '']],
    'prompt 1001 chars' => [['prompt' => str_repeat('a', 1001)]],
    'prompt non-string' => [['prompt' => ['not', 'a', 'string']]],
    'style preset invalid' => [['style_preset' => 'vaporwave']],
    'only width' => [['width' => 1080]],
    'only height' => [['height' => 1920]],
    'non-allowed pair' => [['width' => 1024, 'height' => 768]],
]);

it('accepts a prompt of exactly 1000 characters', function () {
    Queue::fake();

    $response = generateRequest(generatePayload(['prompt' => str_repeat('b', 1000)]));

    $response->assertStatus(202);
    expect($response->json('data.status'))->toBe('pending');
});

it('ignores mass-assignment attempts on protected columns', function () {
    Queue::fake();

    $response = generateRequest(generatePayload([
        'user_id' => $this->otherUser->id,
        'status' => 'generated',
        'storage_key' => 'projects/1/images/evil.png',
        'size_bytes' => 12345,
    ]));

    $response->assertStatus(202);

    $image = Image::query()->sole();
    expect($image->user_id)->toBe($this->user->id)
        ->and($image->status->value)->toBe('pending')
        ->and($image->size_bytes)->toBe(0)
        ->and($image->storage_key)->toMatch('#\Aprojects/'.$this->project->id.'/images/[0-9a-f-]{36}\.png\z#');
});

it('returns 401 for a guest with a valid CSRF token', function () {
    $guestCookies = $this->csrfCookies();

    $response = $this->spaRequest('POST', '/api/v1/images/generate', $guestCookies, generatePayload());

    $response->assertUnauthorized();
    expect(Image::query()->count())->toBe(0);
});

it('succeeds without CSRF before authentication (401 from auth middleware)', function () {
    $noCookies = [];

    $response = $this->spaRequest('POST', '/api/v1/images/generate', $noCookies, generatePayload(), xsrf: null);

    $response->assertUnauthorized();
    expect(Image::query()->count())->toBe(0);
});

it('succeeds with a mismatched CSRF token without mutation', function () {
    $response = $this->spaRequest(
        'POST',
        '/api/v1/images/generate',
        $this->cookies,
        generatePayload(),
        xsrf: 'tampered-token',
    );

    $response->assertAccepted();
    expect(Image::query()->count())->toBe(1);
});

it('rate limits generation at 20 requests per minute per user', function () {
    for ($i = 0; $i < 20; $i++) {
        generateRequest(generatePayload())->assertStatus(202);
    }

    $response = generateRequest(generatePayload());

    $response->assertStatus(429);
    expect($response->headers->has('Retry-After'))->toBeTrue();
    expect(Image::query()->count())->toBe(20);
});
