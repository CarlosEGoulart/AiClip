<?php

namespace Tests\Unit\Services;

use App\Exceptions\ProcessMediaException;
use App\Services\ClipRecommendationProjection;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Helpers — every expectation is derived by hand from the specification
|--------------------------------------------------------------------------
*/

/**
 * Build a valid, chronological, non-overlapping completed transcript.
 *
 * @param  list<array{0: int, 1: int, 2: string}>  $rows
 * @return list<array{start_ms: int, end_ms: int, text: string}>
 */
function transcriptSegments(array $rows): array
{
    return array_map(
        static fn (array $row): array => ['start_ms' => $row[0], 'end_ms' => $row[1], 'text' => $row[2]],
        $rows
    );
}

function candidate(int $index, int $startMs, int $endMs): array
{
    return ['index' => $index, 'start_ms' => $startMs, 'end_ms' => $endMs];
}

function assertProjectionRejected(mixed $segments, int $durationMs = 40000): void
{
    $failure = null;

    try {
        ClipRecommendationProjection::validateSegments($segments, $durationMs);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect(get_class($failure ?? new \stdClass))->toBe(ProcessMediaException::class)
        ->and($failure->getPrevious())->toBeNull()
        ->and($failure->stderr)->toBe('');
}

function assertTextRejected(mixed $text): void
{
    $failure = null;

    try {
        ClipRecommendationProjection::canonicalizeSegmentText((string) $text);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect(get_class($failure ?? new \stdClass))->toBe(ProcessMediaException::class)
        ->and($failure->getPrevious())->toBeNull();
}

/*
|--------------------------------------------------------------------------
| Canonicalization v1 golden vectors
|--------------------------------------------------------------------------
*/

it('collapses and trims the exact canonical ASCII whitespace run', function (string $input, string $expected) {
    expect(ClipRecommendationProjection::canonicalizeSegmentText($input))->toBe($expected);
})->with([
    'tab' => ["\t", ''],
    'line feed' => ["\n", ''],
    'vertical tab' => ["\v", ''],
    'form feed' => ["\f", ''],
    'carriage return' => ["\r", ''],
    'space' => [' ', ''],
    'mixed run' => [" \t\n\v\f\r ", ''],
    'interior collapse' => ["a\t\tb", 'a b'],
    'interior mixed run' => ["a \t\n b", 'a b'],
    'leading and trailing' => ['  hello world  ', 'hello world'],
    'leading run only' => ["\t hello", 'hello'],
    'trailing run only' => ["hello \r\n", 'hello'],
    'single interior space preserved' => ['a b', 'a b'],
    'no whitespace' => ['abc', 'abc'],
    'punctuation preserved' => ['Wait -- what?!', 'Wait -- what?!'],
    'case preserved' => ['Hello World', 'Hello World'],
]);

it('preserves non-ASCII code points and the Unicode normalization form', function () {
    // Decomposed (NFD) and precomposed (NFC) forms are distinct canonical
    // text: M5 never normalizes, folds or case-maps transcript content.
    $decomposed = "e\u{0301}clair";
    $precomposed = "\u{00E9}clair";
    $cyrillic = "\u{041F}\u{0440}\u{0438}\u{0432}\u{0435}\u{0442}";
    $cjk = "\u{4F60}\u{597D}";
    $emoji = "\u{1F600} wow";
    $nonBreakingSpaceInside = "a\u{00A0}b";

    expect(ClipRecommendationProjection::canonicalizeSegmentText($decomposed))->toBe($decomposed)
        ->and(ClipRecommendationProjection::canonicalizeSegmentText($precomposed))->toBe($precomposed)
        ->and($decomposed)->not->toBe($precomposed)
        ->and(ClipRecommendationProjection::canonicalizeSegmentText($cyrillic))->toBe($cyrillic)
        ->and(ClipRecommendationProjection::canonicalizeSegmentText($cjk))->toBe($cjk)
        ->and(ClipRecommendationProjection::canonicalizeSegmentText($emoji))->toBe($emoji)
        // A non-breaking space is not part of the canonical ASCII run, so it
        // is preserved verbatim inside the canonical text.
        ->and(ClipRecommendationProjection::canonicalizeSegmentText($nonBreakingSpaceInside))->toBe($nonBreakingSpaceInside);
});

it('rejects other C0 controls, DEL and invalid UTF-8', function (string $text) {
    assertTextRejected($text);
})->with([
    'null' => ["a\0b"],
    'bell BEL' => ["a\x07b"],
    'backspace' => ["a\x08b"],
    'unit separator' => ["a\x0Fb"],
    'file separator' => ["a\x1Fb"],
    'escape' => ["a\x1Bb"],
    'delete DEL' => ["a\x7Fb"],
    'leading null' => ["\0leading"],
    'trailing DEL' => ["trailing\x7F"],
    'lone continuation byte' => ["\x80"],
    'truncated three byte sequence' => ["\xE2\x82"],
    'overlong encoding' => ["\xC0\xAF"],
    'surrogate half' => ["\xED\xA0\x80"],
]);

it('accepts the canonical whitespace run and every other printable code point', function () {
    expect(ClipRecommendationProjection::canonicalizeSegmentText("\t\v\f\r\n hello \t\v\f\r\n"))->toBe('hello');
});

/*
|--------------------------------------------------------------------------
| Unicode 15.0 White_Space eligibility
|--------------------------------------------------------------------------
*/

it('treats only the pinned Unicode 15.0 White_Space set as whitespace', function (string $text, bool $usable) {
    expect(ClipRecommendationProjection::isUsable($text))->toBe($usable);
})->with([
    'ASCII space only' => ['   ', false],
    'tab only' => ["\t", false],
    'line feed only' => ["\n", false],
    'vertical tab only' => ["\v", false],
    'form feed only' => ["\f", false],
    'carriage return only' => ["\r", false],
    'next line NEL' => ["\u{0085}", false],
    'no-break space' => ["\u{00A0}", false],
    'ogham space mark' => ["\u{1680}", false],
    'en quad' => ["\u{2000}", false],
    'hair space' => ["\u{200A}", false],
    'line separator' => ["\u{2028}", false],
    'paragraph separator' => ["\u{2029}", false],
    'narrow no-break space' => ["\u{202F}", false],
    'medium mathematical space' => ["\u{205F}", false],
    'ideographic space' => ["\u{3000}", false],
    'mixed White_Space run' => ["\u{00A0}\u{2000}\u{3000}\t", false],
    'one visible character' => ['a', true],
    'punctuation only' => ['...', true],
    'digit only' => ['7', true],
    'combining mark only' => ["\u{0301}", true],
    'CJK only' => ["\u{4F60}", true],
    'White_Space plus a letter' => ["\u{3000}a", true],
    'empty' => ['', false],
]);

/*
|--------------------------------------------------------------------------
| Strict transcript validation
|--------------------------------------------------------------------------
*/

it('validates a strict chronological nonoverlapping completed transcript', function () {
    $validated = ClipRecommendationProjection::validateSegments(
        transcriptSegments([[0, 1000, 'one'], [1000, 2000, 'two']]),
        40000
    );

    expect($validated)->toHaveCount(2)
        ->and($validated[0])->toBe(['start_ms' => 0, 'end_ms' => 1000, 'text' => 'one'])
        ->and($validated[1]['text'])->toBe('two');
});

it('rejects a malformed completed transcript before any projection', function (mixed $segments) {
    assertProjectionRejected($segments);
})->with([
    'null' => [null],
    'string' => ['nope'],
    'object map' => [['a' => ['start_ms' => 0, 'end_ms' => 1, 'text' => 'x']]],
    'segment not an object' => [['nope']],
    'segment is a list' => [[[0, 1, 'x']]],
    'missing start_ms' => [[['end_ms' => 1, 'text' => 'x']]],
    'missing end_ms' => [[['start_ms' => 0, 'text' => 'x']]],
    'missing text' => [[['start_ms' => 0, 'end_ms' => 1]]],
    'start_ms string' => [transcriptSegments([['0', 1, 'x']])],
    'start_ms float' => [transcriptSegments([[0.0, 1, 'x']])],
    'start_ms bool' => [transcriptSegments([[false, 1, 'x']])],
    'end_ms string' => [transcriptSegments([[0, '1', 'x']])],
    'end_ms not after start' => [transcriptSegments([[100, 100, 'x']])],
    'end_ms before start' => [transcriptSegments([[100, 50, 'x']])],
    'end_ms beyond duration' => [transcriptSegments([[0, 40001, 'x']])],
    'text not a string' => [transcriptSegments([[0, 1, 42]])],
    'text null' => [transcriptSegments([[0, 1, null]])],
    'overlapping segments' => [transcriptSegments([[0, 1500, 'a'], [1000, 2000, 'b']])],
    'out of chronological order' => [transcriptSegments([[2000, 3000, 'a'], [0, 1000, 'b']])],
    'malformed control in text' => [transcriptSegments([[0, 1, "a\0b"]])],
]);

it('rejects an invalid duration bound', function (int $durationMs) {
    assertProjectionRejected(transcriptSegments([[0, 1, 'x']]), $durationMs);
})->with([0, -1, 2147483648]);

it('accepts exactly 50000 segments and rejects one beyond', function () {
    $rows = [];
    for ($i = 0; $i < 50000; $i++) {
        $rows[] = [$i, $i + 1, 'x'];
    }

    expect(ClipRecommendationProjection::validateSegments(transcriptSegments($rows), 2147483647))->toHaveCount(50000);

    $rows[] = [50000, 50001, 'x'];
    assertProjectionRejected(transcriptSegments($rows), 2147483647);
});

/*
|--------------------------------------------------------------------------
| Half-open overlap projection
|--------------------------------------------------------------------------
*/

it('includes a segment only under the half-open overlap rule', function () {
    $segments = ClipRecommendationProjection::validateSegments(transcriptSegments([
        [0, 1000, 'before'],
        [1000, 2000, 'first window'],
        [2500, 3000, 'between'],
        [3000, 4000, 'second window'],
        [5000, 6000, 'after'],
    ]), 10000);

    $projection = ClipRecommendationProjection::project([
        candidate(0, 1000, 3000),
        candidate(1, 3000, 5000),
    ], $segments);

    // Touching endpoints are excluded; unrelated segments never appear.
    expect($projection['texts'])->toBe([
        'first window between',
        'second window',
    ]);
});

it('rejects a segment boundary touching either candidate endpoint', function () {
    $segments = ClipRecommendationProjection::validateSegments(transcriptSegments([
        [0, 1000, 'a'],
        [1000, 2000, 'b'],
        [2000, 3000, 'c'],
        [3000, 4000, 'd'],
    ]), 10000);

    $projection = ClipRecommendationProjection::project([candidate(0, 1000, 3000)], $segments);

    expect($projection['texts'])->toBe(['b c']);
});

it('includes a boundary-crossing segment in every overlapping projection', function () {
    $segments = ClipRecommendationProjection::validateSegments(transcriptSegments([
        [1000, 3000, 'crossing'],
    ]), 10000);

    $projection = ClipRecommendationProjection::project([
        candidate(0, 0, 2000),
        candidate(1, 1500, 4000),
        candidate(2, 5000, 6000),
    ], $segments);

    expect($projection['texts'])->toBe(['crossing', 'crossing', '']);
});

it('produces exactly one empty projection for a candidate with no overlap', function () {
    $segments = ClipRecommendationProjection::validateSegments(transcriptSegments([[0, 1000, 'only']]), 10000);

    $projection = ClipRecommendationProjection::project([candidate(0, 5000, 6000)], $segments);

    expect($projection['texts'])->toBe([''])
        ->and($projection['text_hashes'])->toBe([['index' => 0, 'sha256' => hash('sha256', '')]]);
});

/*
|--------------------------------------------------------------------------
| Canonical joining, eligibility and digests
|--------------------------------------------------------------------------
*/

it('joins the remaining segment texts by one ASCII space in original order', function () {
    $segments = ClipRecommendationProjection::validateSegments(transcriptSegments([
        [0, 1000, "  first\tsegment  "],
        [1000, 2000, ''],
        [2000, 3000, '   '],
        [3000, 4000, 'second'],
    ]), 10000);

    $projection = ClipRecommendationProjection::project([candidate(0, 0, 5000)], $segments);

    // Empty canonical segments are omitted, not represented as extra spaces.
    expect($projection['texts'])->toBe(['first segment second']);
});

it('replaces whitespace-only projected text with exactly the empty string', function (string $text) {
    $segments = ClipRecommendationProjection::validateSegments(transcriptSegments([[0, 1000, $text]]), 10000);

    $projection = ClipRecommendationProjection::project([candidate(0, 0, 5000)], $segments);

    expect($projection['texts'])->toBe(['']);
})->with([
    'ascii spaces' => ['     '],
    'no-break space' => ["\u{00A0}\u{00A0}"],
    'ideographic space' => ["\u{3000}"],
    'mixed Unicode White_Space' => ["\u{0085}\u{2028}\u{2029}\u{205F}"],
]);

it('hashes the full canonical text before any token truncation', function () {
    $segments = ClipRecommendationProjection::validateSegments(transcriptSegments([
        [0, 1000, 'alpha'],
        [1000, 2000, 'beta'],
    ]), 10000);

    $projection = ClipRecommendationProjection::project([candidate(0, 0, 2000)], $segments);

    expect($projection['texts'][0])->toBe('alpha beta')
        ->and($projection['text_hashes'])->toBe([
            ['index' => 0, 'sha256' => hash('sha256', 'alpha beta')],
        ]);
});

it('emits lowercase 64 character hex digests, including SHA256 of empty text', function () {
    $segments = ClipRecommendationProjection::validateSegments(transcriptSegments([
        [0, 1000, 'alpha'],
        [1000, 2000, 'beta'],
    ]), 10000);

    $projection = ClipRecommendationProjection::project([
        candidate(0, 0, 1000),
        candidate(1, 1000, 2000),
        candidate(2, 9000, 9500),
    ], $segments);

    expect($projection['text_hashes'])->toBe([
        ['index' => 0, 'sha256' => hash('sha256', 'alpha')],
        ['index' => 1, 'sha256' => hash('sha256', 'beta')],
        ['index' => 2, 'sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'],
    ]);

    foreach ($projection['text_hashes'] as $entry) {
        expect($entry['sha256'])->toMatch('/^[0-9a-f]{64}$/');
    }
});

it('builds the empty-string text hashes of an unavailable outcome', function () {
    expect(ClipRecommendationProjection::emptyTextHashes([0, 1, 2]))->toBe([
        ['index' => 0, 'sha256' => hash('sha256', '')],
        ['index' => 1, 'sha256' => hash('sha256', '')],
        ['index' => 2, 'sha256' => hash('sha256', '')],
    ]);
});

/*
|--------------------------------------------------------------------------
| Bounds
|--------------------------------------------------------------------------
*/

it('accepts exactly 16384 canonical bytes and rejects 16385', function () {
    $exact = str_repeat('a', ClipRecommendationProjection::MAX_CANDIDATE_TEXT_BYTES);
    $over = str_repeat('a', ClipRecommendationProjection::MAX_CANDIDATE_TEXT_BYTES + 1);

    $segments = ClipRecommendationProjection::validateSegments(transcriptSegments([[0, 1000, $exact]]), 10000);
    expect(ClipRecommendationProjection::project([candidate(0, 0, 1000)], $segments)['texts'])->toBe([$exact]);

    $failure = null;
    try {
        ClipRecommendationProjection::project(
            [candidate(0, 0, 1000)],
            ClipRecommendationProjection::validateSegments(transcriptSegments([[0, 1000, $over]]), 10000)
        );
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect(get_class($failure ?? new \stdClass))->toBe(ProcessMediaException::class);
});

it('measures the byte bound in UTF-8 bytes rather than code points', function () {
    // 8192 three byte code points are 24576 UTF-8 bytes: over the bound even
    // though the code point count is well inside it.
    $text = str_repeat("\u{4F60}", 8192);
    $failure = null;

    try {
        ClipRecommendationProjection::project(
            [candidate(0, 0, 1000)],
            ClipRecommendationProjection::validateSegments(transcriptSegments([[0, 1000, $text]]), 10000)
        );
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect(strlen($text))->toBeGreaterThan(ClipRecommendationProjection::MAX_CANDIDATE_TEXT_BYTES)
        ->and(get_class($failure ?? new \stdClass))->toBe(ProcessMediaException::class);
});

it('fails the whole attempt rather than dropping an oversized candidate', function () {
    $segments = ClipRecommendationProjection::validateSegments(transcriptSegments([
        [0, 1000, 'small'],
        [1000, 2000, str_repeat('b', 16385)],
    ]), 10000);

    $failure = null;
    try {
        ClipRecommendationProjection::project([candidate(0, 0, 1000), candidate(1, 1000, 2000)], $segments);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    // The first candidate is never returned on its own: exceeding a bound
    // fails the entire attempt.
    expect(get_class($failure ?? new \stdClass))->toBe(ProcessMediaException::class);
});

/*
|--------------------------------------------------------------------------
| Published limits
|--------------------------------------------------------------------------
*/

it('publishes the pinned canonicalization limits', function () {
    expect(ClipRecommendationProjection::MAX_CANDIDATE_TEXT_BYTES)->toBe(16384)
        ->and(ClipRecommendationProjection::MAX_TRANSCRIPT_SEGMENTS)->toBe(50000)
        ->and(ClipRecommendationProjection::MAX_DURATION_MS)->toBe(2147483647)
        ->and(ClipRecommendationProjection::CANONICALIZATION_VERSION)->toBe(1);
});
