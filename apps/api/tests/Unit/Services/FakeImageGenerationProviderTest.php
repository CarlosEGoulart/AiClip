<?php

namespace Tests\Unit\Services;

use App\Contracts\ImageGenerationProvider;
use App\Services\FakeImageGenerationProvider;
use App\Services\ImageResult;
use Tests\TestCase;

uses(TestCase::class);

/*
 * TC-BE-01 (test-plan §1): FakeImageGenerationProvider determinism + style
 * presets. Plain unit test — no HTTP, no database. The fake is the slice's
 * bound production implementation: deterministic, pure-PHP PNG synthesis
 * (no network, no subprocess, no model download — CI-safe per AGENTS.md §21).
 */

const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

function ihdrDimensions(string $data): array
{
    // IHDR: length (4 bytes) + 'IHDR' (4 bytes), then width/height as
    // big-endian uint32 at the fixed offsets 16 and 20.
    $width = unpack('N', substr($data, 16, 4))[1];
    $height = unpack('N', substr($data, 20, 4))[1];

    return [$width, $height];
}

it('returns the fixed four-entry style preset catalog in catalog order', function () {
    $provider = new FakeImageGenerationProvider;

    $presets = $provider->getStylePresets();

    expect($presets)->toBe([
        ['id' => 'photorealistic', 'label' => 'Photorealistic'],
        ['id' => 'illustration', 'label' => 'Illustration'],
        ['id' => 'cinematic', 'label' => 'Cinematic'],
        ['id' => 'digital_art', 'label' => 'Digital Art'],
    ]);
});

it('returns an image result with the png mime type and requested dimensions', function () {
    $provider = new FakeImageGenerationProvider;

    $result = $provider->generate('A neon-lit city skyline at dusk', 'cinematic', 1080, 1920);

    expect($result)->toBeInstanceOf(ImageResult::class)
        ->and($result->mimeType)->toBe('image/png')
        ->and($result->width)->toBe(1080)
        ->and($result->height)->toBe(1920);
});

it('produces byte-identical artifacts for identical inputs', function () {
    $provider = new FakeImageGenerationProvider;

    $first = $provider->generate('A serene mountain lake at sunrise', 'photorealistic', 1024, 1024);
    $second = $provider->generate('A serene mountain lake at sunrise', 'photorealistic', 1024, 1024);

    expect(hash('sha256', $first->data))->toBe(hash('sha256', $second->data));
});

it('produces different bytes for a different prompt', function () {
    $provider = new FakeImageGenerationProvider;

    $first = $provider->generate('A serene mountain lake at sunrise', 'cinematic', 1024, 1024);
    $second = $provider->generate('A neon-lit city skyline at dusk', 'cinematic', 1024, 1024);

    expect(hash('sha256', $first->data))->not->toBe(hash('sha256', $second->data));
});

it('produces different bytes for a different style preset', function () {
    $provider = new FakeImageGenerationProvider;

    $first = $provider->generate('A serene mountain lake at sunrise', 'photorealistic', 1024, 1024);
    $second = $provider->generate('A serene mountain lake at sunrise', 'illustration', 1024, 1024);

    expect(hash('sha256', $first->data))->not->toBe(hash('sha256', $second->data));
});

it('produces different bytes for different dimensions', function () {
    $provider = new FakeImageGenerationProvider;

    $first = $provider->generate('A serene mountain lake at sunrise', 'cinematic', 1024, 1024);
    $second = $provider->generate('A serene mountain lake at sunrise', 'cinematic', 1080, 1920);

    expect(hash('sha256', $first->data))->not->toBe(hash('sha256', $second->data));
});

it('synthesizes a valid png artifact with the exact requested dimensions', function () {
    $provider = new FakeImageGenerationProvider;

    $data = $provider->generate('Storm clouds over the ocean', 'digital_art', 1080, 1920)->data;

    expect(str_starts_with($data, PNG_SIGNATURE))->toBeTrue();

    [$width, $height] = ihdrDimensions($data);
    expect($width)->toBe(1080)
        ->and($height)->toBe(1920);

    // Chunk structure: IHDR declared length is 13 and the chunk type tag
    // is present at bytes 12..15.
    $ihdrLength = unpack('N', substr($data, 8, 4))[1];
    expect($ihdrLength)->toBe(13)
        ->and(substr($data, 12, 4))->toBe('IHDR')
        ->and(str_contains($data, 'IDAT'))->toBeTrue()
        ->and(str_contains($data, 'IEND'))->toBeTrue();
});

it('reports a non-empty artifact size matching the byte length', function () {
    $provider = new FakeImageGenerationProvider;

    $result = $provider->generate('Forest clearing in morning fog', 'illustration', 1024, 1024);

    expect($result->sizeBytes())->toBeGreaterThan(0)
        ->and($result->sizeBytes())->toBe(strlen($result->data));
});

it('implements the image generation provider contract', function () {
    $provider = new FakeImageGenerationProvider;

    expect($provider)->toBeInstanceOf(ImageGenerationProvider::class);
});
