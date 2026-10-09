<?php

namespace Tests\Unit\Services;

use App\Services\SrtGenerator;

it('generates valid SRT for a single segment', function () {
    $segments = [
        ['local_start_ms' => 1200, 'local_end_ms' => 2600, 'text' => 'Hello world'],
    ];

    $result = SrtGenerator::generate($segments);

    expect($result)->toBe(
        "1\n00:00:01,200 --> 00:00:02,600\nHello world\n"
    );
});

it('generates valid SRT for multiple segments with sequential indices', function () {
    $segments = [
        ['local_start_ms' => 1200, 'local_end_ms' => 2600, 'text' => 'First'],
        ['local_start_ms' => 3000, 'local_end_ms' => 4500, 'text' => 'Second'],
        ['local_start_ms' => 5000, 'local_end_ms' => 6500, 'text' => 'Third'],
    ];

    $result = SrtGenerator::generate($segments);

    expect($result)->toBe(
        "1\n00:00:01,200 --> 00:00:02,600\nFirst\n\n".
        "2\n00:00:03,000 --> 00:00:04,500\nSecond\n\n".
        "3\n00:00:05,000 --> 00:00:06,500\nThird\n"
    );
});

it('generates valid SRT entry with empty text', function () {
    $segments = [
        ['local_start_ms' => 1000, 'local_end_ms' => 2000, 'text' => ''],
    ];

    $result = SrtGenerator::generate($segments);

    expect($result)->toBe(
        "1\n00:00:01,000 --> 00:00:02,000\n\n"
    );
});

it('preserves multi-line text in SRT output', function () {
    $segments = [
        ['local_start_ms' => 1000, 'local_end_ms' => 2000, 'text' => "Line1\nLine2"],
    ];

    $result = SrtGenerator::generate($segments);

    expect($result)->toBe(
        "1\n00:00:01,000 --> 00:00:02,000\nLine1\nLine2\n"
    );
});

it('formats timestamps correctly with HH:MM:SS,mmm format (comma, zero-padded)', function () {
    $segments = [
        ['local_start_ms' => 0, 'local_end_ms' => 1000, 'text' => 'Zero'],
        ['local_start_ms' => 1200, 'local_end_ms' => 2600, 'text' => '1.2s'],
        ['local_start_ms' => 3600000, 'local_end_ms' => 3601000, 'text' => '1 hour'],
        ['local_start_ms' => 3661000, 'local_end_ms' => 3662000, 'text' => '1h 1m 1s'],
    ];

    $result = SrtGenerator::generate($segments);

    expect($result)->toContain('00:00:00,000 --> 00:00:01,000');
    expect($result)->toContain('00:00:01,200 --> 00:00:02,600');
    expect($result)->toContain('01:00:00,000 --> 01:00:01,000');
    expect($result)->toContain('01:01:01,000 --> 01:01:02,000');
});

it('returns empty string for empty input array', function () {
    $result = SrtGenerator::generate([]);

    expect($result)->toBe('');
});
