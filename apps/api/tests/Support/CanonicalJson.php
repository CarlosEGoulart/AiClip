<?php

namespace Tests\Support;

/**
 * Test helper for canonical JSON matching ProcessMediaAction::canonicalJson()
 * per spec.md Entries I, J:
 * - Sorted keys (recursive)
 * - No whitespace (separators=(',', ':'))
 * - Strict UTF-8 (JSON_UNESCAPED_UNICODE)
 * - No trailing newline
 * - Forward slashes unescaped (JSON_UNESCAPED_SLASHES)
 */
class CanonicalJson
{
    /**
     * Encode value as canonical JSON for SHA256 binding tests.
     * Matches ProcessMediaAction::canonicalJson() exactly.
     *
     * @param  mixed  $value
     * @return string
     */
    public static function encode(mixed $value): string
    {
        $sorted = self::sortKeysRecursive($value);
        return json_encode(
            $sorted,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Recursively sort array keys for canonical JSON.
     * Matches ProcessMediaAction::sortKeysRecursive() exactly.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public static function sortKeysRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // Check if it's a list (sequential integer keys starting from 0)
        if (array_is_list($value)) {
            return array_map([self::class, 'sortKeysRecursive'], $value);
        }

        // It's an object (associative array) - sort keys and recurse
        ksort($value);
        return array_map([self::class, 'sortKeysRecursive'], $value);
    }

    /**
     * Calculate SHA256 of canonical JSON bytes.
     * Matches spec: hash('sha256', canonicalJson($request))
     *
     * @param  mixed  $value
     * @return string
     */
    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }
}