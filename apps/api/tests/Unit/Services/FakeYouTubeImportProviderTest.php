<?php

namespace Tests\Unit\Services;

use App\Contracts\YouTubeImportProvider;
use App\Services\FakeYouTubeImportProvider;
use Tests\TestCase;

uses(TestCase::class);

/*
 * TC-BE-02 (test-plan §1.2): FakeYouTubeImportProvider — deterministic fake.
 * Plain unit test — no HTTP, no database. CI-safe: no network, no YouTube
 * API credentials, no model download (AGENTS.md §21).
 */

beforeEach(function () {
    $this->provider = new FakeYouTubeImportProvider();
});

it('getPlatform returns youtube', function () {
    expect($this->provider->getPlatform())->toBe('youtube');
});

it('implements the YouTubeImportProvider contract', function () {
    expect($this->provider)->toBeInstanceOf(YouTubeImportProvider::class);
});

it('validateUrl accepts youtube.com/watch?v= URLs', function () {
    expect($this->provider->validateUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'))->toBeTrue();
    expect($this->provider->validateUrl('https://youtube.com/watch?v=dQw4w9WgXcQ'))->toBeTrue();
    expect($this->provider->validateUrl('http://www.youtube.com/watch?v=dQw4w9WgXcQ'))->toBeTrue();
});

it('validateUrl accepts youtu.be URLs', function () {
    expect($this->provider->validateUrl('https://youtu.be/dQw4w9WgXcQ'))->toBeTrue();
    expect($this->provider->validateUrl('http://youtu.be/dQw4w9WgXcQ'))->toBeTrue();
});

it('validateUrl accepts URLs without scheme', function () {
    expect($this->provider->validateUrl('www.youtube.com/watch?v=dQw4w9WgXcQ'))->toBeTrue();
    expect($this->provider->validateUrl('youtu.be/dQw4w9WgXcQ'))->toBeTrue();
});

it('validateUrl rejects non-YouTube URLs', function () {
    expect($this->provider->validateUrl('https://example.com/video'))->toBeFalse();
    expect($this->provider->validateUrl('https://vimeo.com/123456789'))->toBeFalse();
    expect($this->provider->validateUrl('https://www.youtube.com/watch?v=short'))->toBeFalse();
    expect($this->provider->validateUrl('https://www.youtube.com/watch?v=toolongvideodude'))->toBeFalse();
    expect($this->provider->validateUrl('not-a-url'))->toBeFalse();
});

it('validateUrl rejects empty string', function () {
    expect($this->provider->validateUrl(''))->toBeFalse();
});

it('validateUrl rejects non-string input', function () {
    expect($this->provider->validateUrl(''))->toBeFalse();
    expect($this->provider->validateUrl((string) null))->toBeFalse();
});

it('extractMetadata returns deterministic fixture for valid URL', function () {
    $metadata = $this->provider->extractMetadata('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    expect($metadata)->toBe([
        'video_id' => 'dQw4w9WgXcQ',
        'title' => 'Fake YouTube Video',
        'duration_seconds' => 300,
        'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
    ]);
});

it('extractMetadata returns deterministic fixture for youtu.be URL', function () {
    $metadata = $this->provider->extractMetadata('https://youtu.be/dQw4w9WgXcQ');

    expect($metadata)->toBe([
        'video_id' => 'dQw4w9WgXcQ',
        'title' => 'Fake YouTube Video',
        'duration_seconds' => 300,
        'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
    ]);
});

it('extractMetadata is deterministic across calls', function () {
    $first = $this->provider->extractMetadata('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    $second = $this->provider->extractMetadata('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    expect($first)->toBe($second);
});

it('extractMetadata returns empty array for invalid URL', function () {
    expect($this->provider->extractMetadata('https://example.com/video'))->toBe([]);
    expect($this->provider->extractMetadata(''))->toBe([]);
});
