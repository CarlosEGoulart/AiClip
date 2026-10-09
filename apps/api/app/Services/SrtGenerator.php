<?php

namespace App\Services;

final class SrtGenerator
{
    /**
     * Generate SRT caption content from projected segments.
     *
     * Pure function: no side effects, no I/O, deterministic.
     *
     * @param  array<int, array{local_start_ms: int, local_end_ms: int, text: string}>  $segments
     * @return string Valid SRT format
     */
    public static function generate(array $segments): string
    {
        if (empty($segments)) {
            return '';
        }

        $entries = [];
        foreach ($segments as $index => $segment) {
            $start = self::msToSrtTimestamp($segment['local_start_ms']);
            $end = self::msToSrtTimestamp($segment['local_end_ms']);
            $text = $segment['text']; // Preserve line breaks

            $entries[] = ($index + 1)."\n".$start.' --> '.$end."\n".$text;
        }

        return implode("\n\n", $entries)."\n";
    }

    /**
     * Convert milliseconds to SRT timestamp format: HH:MM:SS,mmm
     *
     * @param  int  $ms  Milliseconds
     * @return string SRT timestamp (HH:MM:SS,mmm)
     */
    private static function msToSrtTimestamp(int $ms): string
    {
        $hours = (int) floor($ms / 3600000);
        $minutes = (int) floor(($ms % 3600000) / 60000);
        $seconds = (int) floor(($ms % 60000) / 1000);
        $milliseconds = $ms % 1000;

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $seconds, $milliseconds);
    }
}
