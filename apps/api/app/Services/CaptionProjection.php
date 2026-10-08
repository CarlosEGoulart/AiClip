<?php

namespace App\Services;

/**
 * Pure projection function matching Slice 2 `project_segments_to_clip_local()` exactly.
 * No side effects, no I/O, deterministic, integer arithmetic only.
 */
final class CaptionProjection
{
    /**
     * Project absolute transcript segments to clip-local time coordinates.
     *
     * Matches Slice 2 `project_segments_to_clip_local()` exactly.
     * Pure function: no side effects, no I/O, deterministic.
     *
     * @param  array<int, array{start_ms: int, end_ms: int, text: string}>  $segments
     * @return array<int, array{local_start_ms: int, local_end_ms: int, text: string}>
     */
    public static function project(array $segments, int $clipStartMs, int $clipEndMs): array
    {
        $clipDurationMs = $clipEndMs - $clipStartMs;

        if ($clipDurationMs <= 0) {
            return [];
        }

        $result = [];

        foreach ($segments as $segment) {
            $localStart = $segment['start_ms'] - $clipStartMs;
            $localEnd = $segment['end_ms'] - $clipStartMs;

            $localStart = max(0, $localStart);
            $localEnd = min($clipDurationMs, $localEnd);

            if ($localEnd <= $localStart) {
                continue;
            }

            $result[] = [
                'local_start_ms' => $localStart,
                'local_end_ms' => $localEnd,
                'text' => $segment['text'],
            ];
        }

        return $result;
    }
}
