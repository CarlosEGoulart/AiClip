<?php

namespace Tests\Unit\Services;

use App\Exceptions\ProcessMediaException;
use App\Services\ClipRecommendationReadiness as Readiness;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Seven states and their fixed precedence
|--------------------------------------------------------------------------
*/

const STALE_SEGMENTS = [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'stale synthetic sentence']];
const MALFORMED_SEGMENTS = [['start_ms' => 0, 'text' => 'missing end_ms']];

it('classifies every state of the specification matrix', function () {
    expect(Readiness::classify(true, false, 'completed', false, STALE_SEGMENTS, 40000))
        ->toBe(Readiness::COMPLETED_VALID)
        ->and(Readiness::classify(true, false, 'completed', false, [], 40000))
        ->toBe(Readiness::COMPLETED_EMPTY)
        ->and(Readiness::classify(false, false, 'completed', false, STALE_SEGMENTS, 40000))
        ->toBe(Readiness::NO_AUDIO)
        ->and(Readiness::classify(true, true, 'completed', false, STALE_SEGMENTS, 40000))
        ->toBe(Readiness::EXTRACTION_FAILED)
        ->and(Readiness::classify(true, false, 'failed', false, null, 40000))
        ->toBe(Readiness::TRANSCRIPTION_FAILED)
        ->and(Readiness::classify(true, false, null, false, null, 40000))
        ->toBe(Readiness::MISSING)
        ->and(Readiness::classify(true, false, null, true, null, 40000))
        ->toBe(Readiness::NOT_READY)
        ->and(Readiness::classify(true, false, 'pending', false, null, 40000))
        ->toBe(Readiness::NOT_READY)
        ->and(Readiness::classify(true, false, 'transcribing', false, null, 40000))
        ->toBe(Readiness::NOT_READY);
});

it('publishes exactly the seven specification states', function () {
    expect(Readiness::STATES)->toBe([
        'completed_valid',
        'completed_empty',
        'no_audio',
        'extraction_failed',
        'transcription_failed',
        'missing',
        'not_ready',
    ]);
});

it('lets authoritative no-audio beat a stale or malformed completed transcript', function (mixed $segments) {
    expect(Readiness::classify(false, false, 'completed', false, $segments, 40000))
        ->toBe(Readiness::NO_AUDIO);
})->with([
    'stale valid text' => [STALE_SEGMENTS],
    'malformed segments' => [MALFORMED_SEGMENTS],
    'no segments key at all' => [null],
]);

it('lets authoritative extraction failure beat a stale or malformed completed transcript', function (mixed $segments) {
    expect(Readiness::classify(true, true, 'completed', false, $segments, 40000))
        ->toBe(Readiness::EXTRACTION_FAILED);
})->with([
    'stale valid text' => [STALE_SEGMENTS],
    'malformed segments' => [MALFORMED_SEGMENTS],
    'no segments key at all' => [null],
]);

it('never reads a generic asset failure as an extraction failure', function () {
    // The caller passes only the recorded extraction outcome. A failure
    // caused by another stage leaves that flag false, so a valid completed
    // transcript is still classified as usable input.
    expect(Readiness::classify(true, false, 'completed', false, STALE_SEGMENTS, 40000))
        ->toBe(Readiness::COMPLETED_VALID)
        ->and(Readiness::classify(true, false, 'failed', false, null, 40000))
        ->toBe(Readiness::TRANSCRIPTION_FAILED);
});

it('treats a missing transcript while upstream is still active as not ready', function () {
    expect(Readiness::classify(true, false, null, true, null, 40000))->toBe(Readiness::NOT_READY)
        ->and(Readiness::classify(true, false, null, false, null, 40000))->toBe(Readiness::MISSING);
});

it('never reclassifies invalid completed segments as missing or empty', function (mixed $segments) {
    $failure = null;

    try {
        Readiness::classify(true, false, 'completed', false, $segments, 40000);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect(get_class($failure ?? new \stdClass))->toBe(ProcessMediaException::class)
        ->and($failure->getMessage())->toBe('invalid_input')
        ->and($failure->stderr)->toBe('');
})->with([
    'missing end_ms' => [MALFORMED_SEGMENTS],
    'missing text' => [[['start_ms' => 0, 'end_ms' => 1]]],
    'string times' => [[['start_ms' => '0', 'end_ms' => 1, 'text' => 'x']]],
    'overlapping segments' => [transcriptRows([[0, 1500, 'a'], [1000, 2000, 'b']])],
    'control character' => [transcriptRows([[0, 1, "a\0b"]])],
    'null segments' => [null],
    'not a list' => [['a' => ['start_ms' => 0, 'end_ms' => 1, 'text' => 'x']]],
]);

it('distinguishes completed_empty from a completed transcript with no usable text', function () {
    // completed_empty is an authoritative empty segment list. A non-empty
    // transcript is completed_valid; whether any candidate ends up with text
    // is a projection decision, not a transcript state.
    $whitespaceOnly = [['start_ms' => 0, 'end_ms' => 1000, 'text' => "\u{3000}"]];

    expect(Readiness::classify(true, false, 'completed', false, [], 40000))
        ->toBe(Readiness::COMPLETED_EMPTY)
        ->and(Readiness::classify(true, false, 'completed', false, $whitespaceOnly, 40000))
        ->toBe(Readiness::COMPLETED_VALID);
});

it('rejects an unknown transcript status as invalid input', function () {
    $failure = null;

    try {
        Readiness::classify(true, false, 'unknown_status', false, null, 40000);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect(get_class($failure ?? new \stdClass))->toBe(ProcessMediaException::class)
        ->and($failure->getMessage())->toBe('invalid_input');
});

it('records the classified state or the unavailable reason in a snapshot', function () {
    expect(Readiness::snapshotState(Readiness::COMPLETED_VALID))->toBe('completed_valid')
        ->and(Readiness::snapshotState(Readiness::NO_AUDIO))->toBe('no_audio')
        ->and(Readiness::snapshotState(Readiness::NO_CANDIDATE_TEXT))->toBe('no_candidate_text')
        ->and(Readiness::snapshotState('unavailable', 'no_audio'))->toBe('no_audio');
});

/**
 * @param  list<array{0: int, 1: int, 2: string}>  $rows
 * @return list<array{start_ms: int, end_ms: int, text: string}>
 */
function transcriptRows(array $rows): array
{
    return array_map(
        static fn (array $row): array => ['start_ms' => $row[0], 'end_ms' => $row[1], 'text' => $row[2]],
        $rows
    );
}
