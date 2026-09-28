<?php

namespace App\Services;

use App\Exceptions\ProcessMediaException;

/**
 * Canonicalization v1 and the per-candidate transcript projection of M5.
 *
 * Pure PHP, no database and no worker access, so every text rule of the
 * specification is unit testable on its own. Raw transcript text never leaves
 * this boundary: only canonical text (for the worker request) and per
 * candidate SHA256 digests (for the durable snapshot) are produced.
 */
final class ClipRecommendationProjection
{
    /**
     * Maximum canonical UTF-8 bytes of a single candidate's projected text.
     */
    public const MAX_CANDIDATE_TEXT_BYTES = 16384;

    /**
     * Maximum number of segments in the selected completed transcript.
     */
    public const MAX_TRANSCRIPT_SEGMENTS = 50000;

    public const MAX_DURATION_MS = 2147483647;

    public const CANONICALIZATION_VERSION = 1;

    /**
     * Unicode 15.0 White_Space, pinned as a code point set.
     *
     * U+0009..U+000D, U+0020, U+0085, U+00A0, U+1680, U+2000..U+200A,
     * U+2028, U+2029, U+202F, U+205F and U+3000. \p{Zs} covers every
     * Zs code point; the remaining members are listed explicitly.
     */
    private const WHITE_SPACE_PATTERN = '/[\p{Zs}\x{0009}-\x{000D}\x{0085}\x{2028}\x{2029}]/u';

    /**
     * The canonical whitespace run: ASCII HT, LF, VT, FF, CR and space.
     */
    private const CANONICAL_WHITESPACE_RUN = '/[\x{0009}-\x{000D}\x{0020}]+/u';

    private const LEADING_TRAILING_CANONICAL_WHITESPACE = '/^[\x{0009}-\x{000D}\x{0020}]+|[\x{0009}-\x{000D}\x{0020}]+$/u';

    /**
     * C0 controls other than the canonical whitespace run, plus DEL.
     */
    private const FORBIDDEN_CONTROL = '/[\x{0000}-\x{0008}\x{000E}-\x{001F}\x{007F}]/u';

    /**
     * Canonicalize one transcript segment text (canonicalization v1).
     *
     * Case, punctuation, non-ASCII code points and the Unicode normalization
     * form are preserved exactly. Only the canonical ASCII whitespace run is
     * collapsed and trimmed.
     *
     * @throws ProcessMediaException invalid transcript text
     */
    public static function canonicalizeSegmentText(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            throw new ProcessMediaException('invalid_input', 1, '');
        }

        if (preg_match(self::FORBIDDEN_CONTROL, $text) === 1) {
            throw new ProcessMediaException('invalid_input', 1, '');
        }

        $collapsed = preg_replace(self::CANONICAL_WHITESPACE_RUN, ' ', $text);
        $collapsed = preg_replace(self::LEADING_TRAILING_CANONICAL_WHITESPACE, '', (string) $collapsed);

        return (string) $collapsed;
    }

    /**
     * Validate the whole selected completed transcript before projection.
     *
     * Every segment is validated, not only overlapping ones: malformed types
     * or times are rejected without defaulting a missing field.
     *
     * @param  array<int, mixed>  $segments
     * @return list<array{start_ms: int, end_ms: int, text: string}>
     *
     * @throws ProcessMediaException invalid_input
     */
    public static function validateSegments(mixed $segments, int $durationMs): array
    {
        if (! is_array($segments) || ! array_is_list($segments)) {
            throw new ProcessMediaException('invalid_input', 1, '');
        }

        if (count($segments) > self::MAX_TRANSCRIPT_SEGMENTS) {
            throw new ProcessMediaException('invalid_input', 1, '');
        }

        if ($durationMs < 1 || $durationMs > self::MAX_DURATION_MS) {
            throw new ProcessMediaException('invalid_input', 1, '');
        }

        $validated = [];
        $previousEndMs = null;

        foreach ($segments as $position => $segment) {
            if (! is_array($segment) || array_is_list($segment)) {
                throw new ProcessMediaException('invalid_input', 1, '');
            }

            foreach (['start_ms', 'end_ms', 'text'] as $field) {
                if (! array_key_exists($field, $segment)) {
                    throw new ProcessMediaException('invalid_input', 1, '');
                }
            }

            $startMs = $segment['start_ms'];
            $endMs = $segment['end_ms'];

            if (! is_int($startMs) || is_bool($startMs) || ! is_int($endMs) || is_bool($endMs)) {
                throw new ProcessMediaException('invalid_input', 1, '');
            }

            if ($startMs < 0 || $startMs >= self::MAX_DURATION_MS) {
                throw new ProcessMediaException('invalid_input', 1, '');
            }

            if ($endMs <= $startMs || $endMs > $durationMs) {
                throw new ProcessMediaException('invalid_input', 1, '');
            }

            if (! is_string($segment['text'])) {
                throw new ProcessMediaException('invalid_input', 1, '');
            }

            // Chronological, non-overlapping order across the whole transcript.
            if ($previousEndMs !== null && $startMs < $previousEndMs) {
                throw new ProcessMediaException('invalid_input', 1, '');
            }
            $previousEndMs = $endMs;

            $validated[] = [
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'text' => self::canonicalizeSegmentText($segment['text']),
            ];
        }

        return $validated;
    }

    /**
     * Project the canonical per-candidate text of the selected transcript.
     *
     * A segment belongs to a candidate under the half-open overlap rule
     * `segment.start_ms < candidate.end_ms && segment.end_ms > candidate.start_ms`.
     * A boundary-crossing segment may appear in more than one projection; no
     * word-level cropping is invented and unrelated segments are excluded.
     *
     * @param  list<array{index: int, start_ms: int, end_ms: int}>  $candidates
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $segments
     * @return array{texts: list<string>, text_hashes: list<array{index: int, sha256: string}>}
     *
     * @throws ProcessMediaException invalid_input
     */
    public static function project(array $candidates, array $segments): array
    {
        $texts = [];
        $hashes = [];

        foreach ($candidates as $candidate) {
            $pieces = [];

            foreach ($segments as $segment) {
                if ($segment['start_ms'] < $candidate['end_ms'] && $segment['end_ms'] > $candidate['start_ms']) {
                    $canonical = $segment['text'];
                    if ($canonical !== '') {
                        $pieces[] = $canonical;
                    }
                }
            }

            $text = implode(' ', $pieces);
            $text = self::isUsable($text) ? $text : '';

            if (strlen($text) > self::MAX_CANDIDATE_TEXT_BYTES) {
                throw new ProcessMediaException('invalid_input', 1, '');
            }

            $texts[] = $text;
            $hashes[] = [
                'index' => $candidate['index'],
                'sha256' => self::digest($text),
            ];
        }

        return ['texts' => $texts, 'text_hashes' => $hashes];
    }

    /**
     * The chronological per-candidate text hashes of a durable snapshot.
     *
     * @param  list<array{index: int, sha256: string}>  $hashes
     * @return list<array{index: int, sha256: string}>
     */
    public static function emptyTextHashes(array $indexes): array
    {
        return array_map(
            static fn (int $index): array => ['index' => $index, 'sha256' => self::digest('')],
            array_values($indexes)
        );
    }

    /**
     * Whether canonical text carries at least one character outside the
     * pinned Unicode 15.0 White_Space set.
     */
    public static function isUsable(string $canonicalText): bool
    {
        if ($canonicalText === '') {
            return false;
        }

        return preg_replace(self::WHITE_SPACE_PATTERN, '', $canonicalText) !== '';
    }

    /**
     * SHA256 of the full canonical UTF-8 text, before any token truncation,
     * as lowercase 64-character hex. SHA256(empty) is a legal value.
     */
    public static function digest(string $canonicalText): string
    {
        return hash('sha256', $canonicalText);
    }

    public static function isDigest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    public static function isEmptyDigest(mixed $value): bool
    {
        return $value === self::digest('');
    }
}
