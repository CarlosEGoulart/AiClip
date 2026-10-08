<?php

namespace Tests\Unit\Services;

use App\Services\CaptionProjection;

it('projects segments normally when fully inside clip range', function () {
    $segments = [
        ['start_ms' => 11200, 'end_ms' => 12600, 'text' => 'Hello'],
    ];
    $expected = [
        ['local_start_ms' => 1200, 'local_end_ms' => 2600, 'text' => 'Hello'],
    ];
    expect(CaptionProjection::project($segments, 10000, 15000))->toBe($expected);
});

it('excludes segments completely before clip range', function () {
    $segments = [
        ['start_ms' => 5000, 'end_ms' => 6000, 'text' => 'Before'],
    ];
    expect(CaptionProjection::project($segments, 10000, 15000))->toBe([]);
});

it('excludes segments completely after clip range', function () {
    $segments = [
        ['start_ms' => 20000, 'end_ms' => 21000, 'text' => 'After'],
    ];
    expect(CaptionProjection::project($segments, 10000, 15000))->toBe([]);
});

it('clamps partial overlap at clip start to zero', function () {
    $segments = [
        ['start_ms' => 9000, 'end_ms' => 11000, 'text' => 'Start'],
    ];
    $expected = [
        ['local_start_ms' => 0, 'local_end_ms' => 1000, 'text' => 'Start'],
    ];
    expect(CaptionProjection::project($segments, 10000, 15000))->toBe($expected);
});

it('clamps partial overlap at clip end to clip duration', function () {
    $segments = [
        ['start_ms' => 14000, 'end_ms' => 20000, 'text' => 'End'],
    ];
    $expected = [
        ['local_start_ms' => 4000, 'local_end_ms' => 5000, 'text' => 'End'],
    ];
    expect(CaptionProjection::project($segments, 10000, 15000))->toBe($expected);
});

it('returns empty array for empty input', function () {
    expect(CaptionProjection::project([], 10000, 15000))->toBe([]);
});

it('is deterministic - repeated calls with same inputs produce identical output', function () {
    $segments = [
        ['start_ms' => 5000, 'end_ms' => 6000, 'text' => 'Before'],
        ['start_ms' => 9000, 'end_ms' => 11000, 'text' => 'Start overlap'],
        ['start_ms' => 11000, 'end_ms' => 13000, 'text' => 'Inside'],
        ['start_ms' => 14000, 'end_ms' => 16000, 'text' => 'End overlap'],
        ['start_ms' => 20000, 'end_ms' => 21000, 'text' => 'After'],
    ];
    $clipStart = 10000;
    $clipEnd = 15000;

    $result1 = CaptionProjection::project($segments, $clipStart, $clipEnd);
    $result2 = CaptionProjection::project($segments, $clipStart, $clipEnd);
    $result3 = CaptionProjection::project($segments, $clipStart, $clipEnd);

    expect($result1)->toBe($result2);
    expect($result2)->toBe($result3);
    expect($result1)->toBe([
        ['local_start_ms' => 0, 'local_end_ms' => 1000, 'text' => 'Start overlap'],
        ['local_start_ms' => 1000, 'local_end_ms' => 3000, 'text' => 'Inside'],
        ['local_start_ms' => 4000, 'local_end_ms' => 5000, 'text' => 'End overlap'],
    ]);
});

it('handles mixed segments with before, overlapping, inside, and after', function () {
    $segments = [
        ['start_ms' => 5000, 'end_ms' => 6000, 'text' => 'Before'],
        ['start_ms' => 9000, 'end_ms' => 11000, 'text' => 'Start overlap'],
        ['start_ms' => 11000, 'end_ms' => 13000, 'text' => 'Inside'],
        ['start_ms' => 14000, 'end_ms' => 16000, 'text' => 'End overlap'],
        ['start_ms' => 20000, 'end_ms' => 21000, 'text' => 'After'],
    ];
    $expected = [
        ['local_start_ms' => 0, 'local_end_ms' => 1000, 'text' => 'Start overlap'],
        ['local_start_ms' => 1000, 'local_end_ms' => 3000, 'text' => 'Inside'],
        ['local_start_ms' => 4000, 'local_end_ms' => 5000, 'text' => 'End overlap'],
    ];
    expect(CaptionProjection::project($segments, 10000, 15000))->toBe($expected);
});

it('returns empty array for zero-duration clip', function () {
    $segments = [
        ['start_ms' => 10000, 'end_ms' => 11000, 'text' => 'X'],
    ];
    expect(CaptionProjection::project($segments, 10000, 10000))->toBe([]);
});

it('excludes segments exactly at clip bounds (zero duration after clamping)', function () {
    $segments = [
        ['start_ms' => 10000, 'end_ms' => 10000, 'text' => 'X'],
        ['start_ms' => 15000, 'end_ms' => 15000, 'text' => 'Y'],
    ];
    expect(CaptionProjection::project($segments, 10000, 15000))->toBe([]);
});

it('preserves empty text in output', function () {
    $segments = [
        ['start_ms' => 11000, 'end_ms' => 12000, 'text' => ''],
    ];
    $expected = [
        ['local_start_ms' => 1000, 'local_end_ms' => 2000, 'text' => ''],
    ];
    expect(CaptionProjection::project($segments, 10000, 15000))->toBe($expected);
});

it('uses integer arithmetic only - no floating point', function () {
    $segments = [
        ['start_ms' => 10001, 'end_ms' => 10003, 'text' => 'X'],
    ];
    $result = CaptionProjection::project($segments, 10000, 15000);

    expect($result)->toHaveCount(1);
    expect($result[0]['local_start_ms'])->toBeInt()->toBe(1);
    expect($result[0]['local_end_ms'])->toBeInt()->toBe(3);
    expect($result[0]['text'])->toBe('X');

    // Verify no float values anywhere in result
    foreach ($result as $segment) {
        expect($segment['local_start_ms'])->toBeInt();
        expect($segment['local_end_ms'])->toBeInt();
    }
});
