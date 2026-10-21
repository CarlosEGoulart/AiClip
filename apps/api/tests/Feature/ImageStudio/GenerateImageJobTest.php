<?php

namespace Tests\Feature\ImageStudio;

use App\Contracts\ImageGenerationProvider;
use App\Enums\ImageStatus;
use App\Exceptions\InvalidInputException;
use App\Jobs\GenerateImage;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use App\Services\FakeImageGenerationProvider;
use App\Services\ImageResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * TC-BE-08 (test-plan §4): the GenerateImage queue job — ownership
 * re-validation, non-pending idempotency, strict Laravel-side result
 * validation, generated/failed marking, storage-failure retry semantics,
 * and sync-queue observability. Artifact assertions run against the
 * configured media disk via Storage::fake and the row's storage_key.
 */

const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

/**
 * Spy/stub provider injected through the job's optional constructor
 * argument (the RenderMediaClip testability pattern). Records every
 * generate() call and can override the result or throw.
 */
class RecordingImageProvider implements ImageGenerationProvider
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public ?ImageResult $override = null;

    public ?\Throwable $exception = null;

    public function getStylePresets(): array
    {
        return (new FakeImageGenerationProvider)->getStylePresets();
    }

    public function generate(string $prompt, string $stylePreset, int $width, int $height): ImageResult
    {
        $this->calls[] = [
            'prompt' => $prompt,
            'style_preset' => $stylePreset,
            'width' => $width,
            'height' => $height,
        ];

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->override
            ?? (new FakeImageGenerationProvider)->generate($prompt, $stylePreset, $width, $height);
    }
}

beforeEach(function () {
    $this->disk = config('media.disk', 'media');
    Storage::fake($this->disk);
});

it('marks a pending image generated with a real stored artifact and metadata', function () {
    $image = Image::factory()->create([
        'parameters' => ['style_preset' => 'cinematic', 'width' => 1080, 'height' => 1920],
        'width' => 1080,
        'height' => 1920,
    ]);

    (new GenerateImage($image->id))->handle();

    $image = $image->fresh();
    $stored = Storage::disk($this->disk)->get($image->storage_key);

    expect($image->status)->toBe(ImageStatus::GENERATED)
        ->and($stored)->not->toBeNull()
        ->and(str_starts_with((string) $stored, PNG_SIGNATURE))->toBeTrue()
        ->and($image->size_bytes)->toBe(strlen((string) $stored))
        ->and($image->width)->toBe(1080)
        ->and($image->height)->toBe(1920)
        ->and($image->mime_type)->toBe('image/png');
});

it('passes the persisted prompt, style preset, and dimensions to the provider', function () {
    $provider = new RecordingImageProvider;
    $image = Image::factory()->create([
        'prompt' => 'A neon-lit city skyline at dusk',
        'parameters' => ['style_preset' => 'digital_art', 'width' => 1920, 'height' => 1080],
        'width' => 1920,
        'height' => 1080,
    ]);

    (new GenerateImage($image->id, $provider))->handle();

    expect($provider->calls)->toHaveCount(1)
        ->and($provider->calls[0])->toBe([
            'prompt' => 'A neon-lit city skyline at dusk',
            'style_preset' => 'digital_art',
            'width' => 1920,
            'height' => 1080,
        ]);
});

it('is idempotent on a terminal image and never re-invokes the provider', function () {
    $provider = new RecordingImageProvider;
    $image = Image::factory()->create();

    (new GenerateImage($image->id, $provider))->handle();
    $afterFirst = $image->fresh();
    $firstContent = Storage::disk($this->disk)->get($afterFirst->storage_key);
    $firstModified = Storage::disk($this->disk)->lastModified($afterFirst->storage_key);

    (new GenerateImage($image->id, $provider))->handle();

    $afterSecond = $image->fresh();
    expect($provider->calls)->toHaveCount(1)
        ->and($afterSecond->status)->toBe($afterFirst->status)
        ->and($afterSecond->size_bytes)->toBe($afterFirst->size_bytes)
        ->and(Storage::disk($this->disk)->get($afterSecond->storage_key))->toBe($firstContent)
        ->and(Storage::disk($this->disk)->lastModified($afterSecond->storage_key))->toBe($firstModified);
});

it('returns early on a non-pending image without calling the provider', function (string $state) {
    $provider = new RecordingImageProvider;
    $image = Image::factory()->{$state}()->create();

    (new GenerateImage($image->id, $provider))->handle();

    $fresh = $image->fresh();
    expect($provider->calls)->toHaveCount(0)
        ->and($fresh->status->value)->toBe($state)
        ->and(Storage::disk($this->disk)->exists($fresh->storage_key))->toBeFalse();
})->with(['generated', 'failed']);

it('skips images whose user does not own the project, leaving them pending', function () {
    $provider = new RecordingImageProvider;
    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs) {
        $logs[] = $event;
    });

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);
    $image = Image::factory()->create([
        'user_id' => $intruder->id,
        'project_id' => $project->id,
    ]);

    (new GenerateImage($image->id, $provider))->handle();

    $fresh = $image->fresh();
    expect($provider->calls)->toHaveCount(0)
        ->and($fresh->status)->toBe(ImageStatus::PENDING)
        ->and(Storage::disk($this->disk)->exists($fresh->storage_key))->toBeFalse();

    $warnings = array_values(array_filter(
        $logs,
        fn (MessageLogged $event) => $event->level === 'warning',
    ));
    expect($warnings)->toHaveCount(1);
});

it('throws invalid input for a missing image id', function () {
    (new GenerateImage(999999999))->handle();
})->throws(InvalidInputException::class);

it('marks the image failed when the provider throws and writes no artifact', function () {
    $provider = new RecordingImageProvider;
    $provider->exception = new RuntimeException('provider exploded with secret prompt details');

    $image = Image::factory()->create(['prompt' => 'A serene mountain lake at sunrise']);

    (new GenerateImage($image->id, $provider))->handle();

    $fresh = $image->fresh();
    expect($fresh->status)->toBe(ImageStatus::FAILED)
        ->and(Storage::disk($this->disk)->exists($fresh->storage_key))->toBeFalse()
        ->and($fresh->size_bytes)->toBe(0);
});

it('marks the image failed when the provider returns empty data', function () {
    $provider = new RecordingImageProvider;
    $provider->override = new ImageResult(data: '', mimeType: 'image/png', width: 1024, height: 1024);

    $image = Image::factory()->create();

    (new GenerateImage($image->id, $provider))->handle();

    $fresh = $image->fresh();
    expect($fresh->status)->toBe(ImageStatus::FAILED)
        ->and(Storage::disk($this->disk)->exists($fresh->storage_key))->toBeFalse();
});

it('marks the image failed when the artifact lacks the png signature', function () {
    $provider = new RecordingImageProvider;
    $provider->override = new ImageResult(
        data: 'GIF89a definitely not a png payload',
        mimeType: 'image/png',
        width: 1024,
        height: 1024,
    );

    $image = Image::factory()->create();

    (new GenerateImage($image->id, $provider))->handle();

    $fresh = $image->fresh();
    expect($fresh->status)->toBe(ImageStatus::FAILED)
        ->and(Storage::disk($this->disk)->exists($fresh->storage_key))->toBeFalse();
});

it('marks the image failed when the result dimensions mismatch the request', function () {
    $provider = new RecordingImageProvider;
    $provider->override = new ImageResult(
        data: (new FakeImageGenerationProvider)->generate('p', 'cinematic', 1024, 1024)->data,
        mimeType: 'image/png',
        width: 999,
        height: 999,
    );

    $image = Image::factory()->create([
        'width' => 1024,
        'height' => 1024,
    ]);

    (new GenerateImage($image->id, $provider))->handle();

    $fresh = $image->fresh();
    expect($fresh->status)->toBe(ImageStatus::FAILED)
        ->and(Storage::disk($this->disk)->exists($fresh->storage_key))->toBeFalse();
});

it('propagates unexpected storage failures and leaves the row pending for retry', function () {
    $image = Image::factory()->create();

    $disk = Mockery::mock(Storage::disk($this->disk))->makePartial();
    $disk->shouldReceive('put')->andThrow(new RuntimeException('disk unavailable'));
    Storage::shouldReceive('disk')->with($this->disk)->andReturn($disk);

    (new GenerateImage($image->id))->handle();
})->throws(RuntimeException::class, 'disk unavailable');

it('keeps the row pending after an unexpected storage failure', function () {
    $provider = new RecordingImageProvider;
    $image = Image::factory()->create();

    $disk = Mockery::mock(Storage::disk($this->disk))->makePartial();
    $disk->shouldReceive('put')->andThrow(new RuntimeException('disk unavailable'));
    Storage::shouldReceive('disk')->with($this->disk)->andReturn($disk);

    try {
        (new GenerateImage($image->id, $provider))->handle();
        $this->fail('Expected the storage exception to propagate.');
    } catch (RuntimeException) {
        // The queue receives the exception for retry (tries = 3, backoff = 5).
    }

    expect($image->fresh()->status)->toBe(ImageStatus::PENDING)
        ->and($image->fresh()->size_bytes)->toBe(0);
});

it('logs sanitized failure reasons without prompt content or provider internals', function () {
    $provider = new RecordingImageProvider;
    $provider->exception = new RuntimeException('boom containing the prompt: A serene mountain lake at sunrise');

    $image = Image::factory()->create(['prompt' => 'A serene mountain lake at sunrise']);

    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs) {
        $logs[] = $event;
    });

    (new GenerateImage($image->id, $provider))->handle();

    expect($image->fresh()->status)->toBe(ImageStatus::FAILED);

    $warnings = array_values(array_filter(
        $logs,
        fn (MessageLogged $event) => $event->level === 'warning',
    ));
    expect($warnings)->not->toBeEmpty();

    foreach ($warnings as $warning) {
        $rendered = json_encode([$warning->message, $warning->context]);
        expect($rendered)->not->toContain('A serene mountain lake at sunrise')
            ->and($rendered)->not->toContain('boom');
    }
});

it('generates inline through the sync queue connection used by tests and e2e', function () {
    $image = Image::factory()->create();

    GenerateImage::dispatch($image->id);

    expect($image->fresh()->status)->toBe(ImageStatus::GENERATED)
        ->and(Storage::disk($this->disk)->exists($image->fresh()->storage_key))->toBeTrue();
});

it('exposes the queue idempotency identity and retry policy', function () {
    $job = new GenerateImage(42);

    expect($job->uniqueId())->toBe('generate-image:42')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(5);
});
